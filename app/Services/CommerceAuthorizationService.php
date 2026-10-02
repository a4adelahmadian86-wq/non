<?php

namespace App\Services;

use App\Contracts\Commerce\AuthorizationDecision;
use App\Models\FarastCharge;
use App\Models\FarastCouponRedemption;
use App\Models\FarastEntitlement;
use App\Models\FarastInvoice;
use App\Models\FarastInvoiceItem;
use App\Models\FarastRefund;
use App\Models\FarastUsageEvent;
use App\Models\FarastUsageReservation;
use App\Models\FarastWalletReservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CommerceAuthorizationService
{
    public function __construct(private PricingEngine $pricing, private CapabilityService $capabilities, private PromotionService $promotions) {}

    public function authorize(User $actor, string $capability, array $scope = [], float $quantity = 1, array $context = []): AuthorizationDecision
    {
        $quantity=max(0,$quantity);
        $organizationId=$actor->organization_id ?: ($scope['organization_id'] ?? null);
        $entitlement=$this->findEntitlement($actor,$capability,$scope,$organizationId);
        $pricingContext=$context; $pricingContext['included_quantity']=($entitlement && $entitlement->mode==='subscription') ? ($entitlement->quantity===null ? $quantity : min($quantity,max(0,(float)$entitlement->quantity-(float)$entitlement->used_quantity-$this->reservedForEntitlement((int)$entitlement->id)))) : 0; $baseQuote=$this->pricing->quote((string)($context['policy_code'] ?? $capability),$quantity,$pricingContext); $adjustments=$this->promotions->trustedAdjustments($actor,$capability,$quantity,(int)($baseQuote['subtotal']??0),$context); $pricingContext['trusted_adjustments']=$adjustments; $quote=$this->pricing->quote((string)($context['policy_code'] ?? $capability),$quantity,$pricingContext); $quote['adjustments']=$adjustments['metadata']??[];
        $unit=(string)($entitlement?->unit ?: ($quote['unit'] ?? ($context['unit'] ?? 'unit')));
        $remaining=$entitlement?->quantity===null?null:($entitlement?->quantity !== null ? max(0,(float)$entitlement->quantity-(float)$entitlement->used_quantity-$this->reservedForEntitlement((int)$entitlement->id)):null);
        $limits=['quota'=>$entitlement?->quantity,'used'=>$entitlement?->used_quantity,'reserved'=>$entitlement?$this->reservedForEntitlement((int)$entitlement->id):0,'payg_available'=>(bool)($context['allow_payg']??true)];

        if($actor->isAdmin()) $mode='administrator';
        elseif($entitlement && ($entitlement->quantity===null || $remaining >= $quantity)) $mode=(string)$entitlement->mode;
        elseif($entitlement && (($context['allow_overage']??false) || (($context['allow_payg']??true)&&$quote['available']))) $mode=($context['allow_overage']??false)?'overage':'payg';
        elseif($this->legacyAllows($actor,$capability)) $mode='free';
        elseif(($context['allow_payg']??true)&&$quote['available']&&(($context['postpaid']??false)||$this->canAfford($actor,(int)$quote['total']))) $mode='payg';
        else return $this->decision(false,$entitlement,'none',$remaining??0,$unit,$quote,null,$limits,$entitlement?'quota_exhausted':'not_entitled');

        if($quote['available'] && $quote['total']>0 && $mode!=='administrator' && !($context['postpaid']??false) && !$this->canAfford($actor,(int)$quote['total']))
            return $this->decision(false,$entitlement,$mode,$remaining??0,$unit,$quote,null,$limits,'insufficient_credit');

        if(($context['reserve']??false)===true){
            $reservation=$this->reserve($actor,$capability,$quantity,$scope,$context);
            return $this->decision(true,$entitlement,$mode,$remaining,$unit,$quote,$reservation->reservation_id,$limits,null);
        }
        return $this->decision(true,$entitlement,$mode,$remaining,$unit,$quote,null,$limits,null);
    }

    public function reserve(User $actor,string $capability,float $quantity,array $scope=[],array $context=[]): FarastUsageReservation
    {
        return DB::transaction(function()use($actor,$capability,$quantity,$scope,$context){
            $key=(string)($context['idempotency_key']??Str::uuid());
            $existing=FarastUsageReservation::where('idempotency_key',$key)->first();
            if($existing)return $existing;
            $organizationId=$actor->organization_id ?: ($scope['organization_id']??null);
            $entitlement=$this->findEntitlement($actor,$capability,$scope,$organizationId,true);
            $pricingContext=$context; $pricingContext['included_quantity']=($entitlement && $entitlement->mode==='subscription') ? ($entitlement->quantity===null ? $quantity : min($quantity,max(0,(float)$entitlement->quantity-(float)$entitlement->used_quantity-$this->reservedForEntitlement((int)$entitlement->id)))) : 0; $baseQuote=$this->pricing->quote((string)($context['policy_code']??$capability),$quantity,$pricingContext); $adjustments=$this->promotions->trustedAdjustments($actor,$capability,$quantity,(int)($baseQuote['subtotal']??0),$context); $pricingContext['trusted_adjustments']=$adjustments; $quote=$this->pricing->quote((string)($context['policy_code']??$capability),$quantity,$pricingContext); $quote['adjustments']=$adjustments['metadata']??[];
            $mode=$this->mode($actor,$capability,$quantity,$entitlement,$quote,$context);
            if($entitlement && $entitlement->quantity!==null && !in_array($mode,['payg','overage'],true)){
                $available=max(0,(float)$entitlement->quantity-(float)$entitlement->used_quantity-$this->reservedForEntitlement((int)$entitlement->id));
                if($available<$quantity)throw new RuntimeException('quota_exhausted');
            }
            if($quote['total']>0&&!($context['postpaid']??false)){
                $wallet=Wallet::firstOrCreate(['user_id'=>$actor->id],['balance_rials'=>0]);
                $wallet=Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $held=(int)FarastWalletReservation::where('wallet_id',$wallet->id)->where('status','reserved')->sum('amount');
                if((int)$wallet->balance_rials-$held<(int)$quote['total'])throw new RuntimeException('insufficient_credit');
                FarastWalletReservation::create(['reservation_id'=>Str::uuid(),'idempotency_key'=>$key,'wallet_id'=>$wallet->id,'amount'=>(int)$quote['total'],'currency'=>$quote['currency'],'status'=>'reserved','expires_at'=>now()->addMinutes((int)($context['reservation_ttl_minutes']??15))]);
            }
            return FarastUsageReservation::create([
                'reservation_id'=>Str::uuid(),'idempotency_key'=>$key,'actor_id'=>$actor->id,'organization_id'=>$organizationId,
                'project_id'=>$scope['project_id']??null,'document_id'=>$scope['document_id']??null,'entitlement_id'=>$entitlement?->id,
                'pricing_policy_version_id'=>$quote['pricing_policy_version_id']??null,'capability'=>$capability,'quantity'=>$quantity,
                'unit'=>$entitlement?->unit?:($quote['unit']??($context['unit']??'unit')),'mode'=>$mode,'status'=>'reserved',
                'expires_at'=>now()->addMinutes((int)($context['reservation_ttl_minutes']??15)),
                'metadata'=>['quote'=>$quote,'context'=>$this->safeContext($context)],
            ]);
        });
    }

    public function commit(FarastUsageReservation|string $reservation,array $resultContext=[]): FarastUsageEvent
    {
        return DB::transaction(function()use($reservation,$resultContext){
            $row=$reservation instanceof FarastUsageReservation?FarastUsageReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail():FarastUsageReservation::where('reservation_id',$reservation)->lockForUpdate()->firstOrFail();
            if($row->status==='committed'&&$row->usage_event_id)return FarastUsageEvent::findOrFail($row->usage_event_id);
            if($row->status!=='reserved')throw new RuntimeException('reservation_not_active');
            if($row->expires_at&&now()->greaterThan($row->expires_at)){$this->releaseInternal($row);throw new RuntimeException('reservation_expired');}
            $existing=FarastUsageEvent::where('idempotency_key',$row->idempotency_key)->first();
            if($existing){$row->update(['status'=>'committed','committed_at'=>now(),'usage_event_id'=>$existing->id]);return $existing;}
            if($row->entitlement_id&&!in_array($row->mode,['payg','overage'],true)){
                $ent=FarastEntitlement::whereKey($row->entitlement_id)->lockForUpdate()->firstOrFail();
                if($ent->quantity!==null&&(float)$ent->used_quantity+(float)$row->quantity>(float)$ent->quantity)throw new RuntimeException('quota_exhausted');
                if($ent->quantity!==null)$ent->increment('used_quantity',(int)ceil((float)$row->quantity));
            }
            $eventId=Str::uuid()->toString();
            $checksum=hash('sha256',json_encode(['event_id'=>$eventId,'idempotency_key'=>$row->idempotency_key,'actor_id'=>$row->actor_id,'capability'=>$row->capability,'quantity'=>(string)$row->quantity,'unit'=>$row->unit,'occurred_at'=>now()->toIso8601String(),'entitlement_id'=>$row->entitlement_id,'pricing_policy_version_id'=>$row->pricing_policy_version_id],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
            $event=FarastUsageEvent::create(['event_id'=>$eventId,'idempotency_key'=>$row->idempotency_key,'actor_id'=>$row->actor_id,'organization_id'=>$row->organization_id,'project_id'=>$row->project_id,'document_id'=>$row->document_id,'capability'=>$row->capability,'quantity'=>$row->quantity,'unit'=>$row->unit,'occurred_at'=>now(),'entitlement_id'=>$row->entitlement_id,'pricing_policy_version_id'=>$row->pricing_policy_version_id,'cost_metadata'=>$resultContext['cost_metadata']??null,'metadata'=>$resultContext['metadata']??null,'checksum'=>$checksum]);
            $quote=is_array($row->metadata['quote']??null)?$row->metadata['quote']:$this->pricing->quote($row->capability,(float)$row->quantity);
            $charge=$this->createCharge($event,$row,$quote,$resultContext);
            $row->update(['status'=>'committed','committed_at'=>now(),'usage_event_id'=>$event->id]);
            return $event;
        });
    }

    public function release(FarastUsageReservation|string $reservation): void
    {
        DB::transaction(function()use($reservation){$row=$reservation instanceof FarastUsageReservation?FarastUsageReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail():FarastUsageReservation::where('reservation_id',$reservation)->lockForUpdate()->firstOrFail();$this->releaseInternal($row);});
    }

    public function settlePostpaid(FarastCharge|string $charge): FarastCharge
    {
        return DB::transaction(function() use ($charge) {
            $row=$charge instanceof FarastCharge
                ? FarastCharge::whereKey($charge->id)->lockForUpdate()->firstOrFail()
                : FarastCharge::where('charge_id',$charge)->lockForUpdate()->firstOrFail();
            if($row->status==='charged')return $row;
            if($row->status!=='pending')throw new RuntimeException('charge_not_settleable');
            $wallet=Wallet::firstOrCreate(['user_id'=>$row->actor_id],['balance_rials'=>0]);
            $wallet=Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if((int)$wallet->balance_rials<(int)$row->total)throw new RuntimeException('insufficient_credit');
            $before=(int)$wallet->balance_rials;$after=$before-(int)$row->total;
            $wallet->update(['balance_rials'=>$after]);
            WalletTransaction::create([
                'wallet_id'=>$wallet->id,'type'=>'debit','amount_rials'=>(int)$row->total,
                'balance_before'=>$before,'balance_after'=>$after,'reference_type'=>'farast_postpaid_charge',
                'reference_id'=>$row->id,'description'=>'تسویه مصرف پس‌پرداخت FARAST',
                'idempotency_key'=>'postpaid-'.$row->idempotency_key,
            ]);
            $row->update(['status'=>'charged']);
            if($row->invoice_id)FarastInvoice::whereKey($row->invoice_id)->update(['status'=>'paid','paid_at'=>now(),'updated_at'=>now()]);
            return $row->fresh();
        });
    }

    public function refund(FarastCharge|string $charge,int $amount,string $reason,string $idempotencyKey): FarastRefund
    {
        return DB::transaction(function()use($charge,$amount,$reason,$idempotencyKey){
            $row=$charge instanceof FarastCharge?FarastCharge::whereKey($charge->id)->lockForUpdate()->firstOrFail():FarastCharge::where('charge_id',$charge)->lockForUpdate()->firstOrFail();
            if($amount<=0)throw new RuntimeException('invalid_refund_amount');
            if($existing=FarastRefund::where('idempotency_key',$idempotencyKey)->first())return $existing;
            $refunded=(int)FarastRefund::where('charge_id',$row->id)->where('status','completed')->sum('amount');
            if($amount>(int)$row->total-$refunded)throw new RuntimeException('refund_exceeds_charge');
            $refund=FarastRefund::create(['refund_id'=>Str::uuid(),'idempotency_key'=>$idempotencyKey,'charge_id'=>$row->id,'actor_id'=>$row->actor_id,'amount'=>$amount,'currency'=>$row->currency,'status'=>'completed','reason'=>$reason,'metadata'=>['charge_snapshot'=>$row->snapshot]]);
            if($row->actor_id&&$amount>0){
                $wallet=Wallet::firstOrCreate(['user_id'=>$row->actor_id],['balance_rials'=>0]);$wallet=Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $before=(int)$wallet->balance_rials;$wallet->update(['balance_rials'=>$before+$amount]);
                WalletTransaction::create(['wallet_id'=>$wallet->id,'type'=>'credit','amount_rials'=>$amount,'balance_before'=>$before,'balance_after'=>$before+$amount,'reference_type'=>'farast_refund','reference_id'=>$refund->id,'description'=>'بازپرداخت اعتبار FARAST','idempotency_key'=>'refund-'.$idempotencyKey]);
            }
            if($amount+$refunded>=(int)$row->total)$row->update(['status'=>'refunded']);
            return $refund;
        });
    }

    private function createCharge(FarastUsageEvent $event,FarastUsageReservation $reservation,array $quote,array $resultContext):FarastCharge
    {
        if($existing=FarastCharge::where('idempotency_key',$reservation->idempotency_key)->first())return $existing;
        $charge=FarastCharge::create(['charge_id'=>Str::uuid(),'idempotency_key'=>$reservation->idempotency_key,'actor_id'=>$event->actor_id,'organization_id'=>$event->organization_id,'usage_event_id'=>$event->id,'reservation_id'=>$reservation->id,'order_id'=>$resultContext['order_id']??null,'capability'=>$event->capability,'quantity'=>$event->quantity,'unit'=>$event->unit,'unit_price'=>(int)($quote['unit_price']??0),'currency'=>$quote['currency']??'IRR','subtotal'=>(int)($quote['subtotal']??0),'discount'=>(int)($quote['discount']??0),'fee'=>(int)($quote['fee']??0),'tax'=>(int)($quote['tax']??0),'total'=>(int)($quote['total']??0),'status'=>(($quote['total']??0)>0&&($reservation->metadata['context']['postpaid']??false))?'pending':'charged','pricing_policy_version_id'=>$event->pricing_policy_version_id,'snapshot'=>$quote+['result'=>$resultContext]]);
        $adjustmentCode=$quote['adjustments']['coupon_code']??null;
        if($adjustmentCode){ FarastCouponRedemption::firstOrCreate(['idempotency_key'=>'charge-'.$reservation->idempotency_key],['coupon_code'=>$adjustmentCode,'actor_id'=>$event->actor_id,'charge_id'=>$charge->id,'discount_amount'=>(int)($quote['discount']??0),'currency'=>$charge->currency]); }
        $invoice=FarastInvoice::create(['invoice_number'=>'FAR-'.now()->format('YmdHis').'-'.str_pad((string)$charge->id,6,'0',STR_PAD_LEFT),'actor_id'=>$event->actor_id,'organization_id'=>$event->organization_id,'currency'=>$charge->currency,'subtotal'=>$charge->subtotal,'discount'=>$charge->discount,'fee'=>$charge->fee,'tax'=>$charge->tax,'total'=>$charge->total,'status'=>$charge->total>0?'issued':'paid','issued_at'=>now(),'paid_at'=>$charge->total>0?null:now(),'snapshot'=>$charge->snapshot]);
        FarastInvoiceItem::create(['invoice_id'=>$invoice->id,'charge_id'=>$charge->id,'description'=>$event->capability,'quantity'=>$event->quantity,'unit'=>$event->unit,'unit_price'=>$charge->unit_price,'subtotal'=>$charge->subtotal,'discount'=>$charge->discount,'fee'=>$charge->fee,'tax'=>$charge->tax,'total'=>$charge->total,'currency'=>$charge->currency,'snapshot'=>$charge->snapshot]);
        $charge->update(['invoice_id'=>$invoice->id]);
        if($charge->total>0&&$charge->status==='charged')$this->commitWalletHold($reservation,$charge);
        return $charge->fresh();
    }

    private function commitWalletHold(FarastUsageReservation $reservation,FarastCharge $charge):void
    {
        $hold=FarastWalletReservation::where('idempotency_key',$reservation->idempotency_key)->where('status','reserved')->lockForUpdate()->first();if(!$hold)return;
        $wallet=Wallet::whereKey($hold->wallet_id)->lockForUpdate()->firstOrFail();if((int)$wallet->balance_rials<(int)$hold->amount)throw new RuntimeException('insufficient_credit');
        $before=(int)$wallet->balance_rials;$wallet->update(['balance_rials'=>$before-(int)$hold->amount]);
        WalletTransaction::create(['wallet_id'=>$wallet->id,'type'=>'debit','amount_rials'=>(int)$hold->amount,'balance_before'=>$before,'balance_after'=>$before-(int)$hold->amount,'reference_type'=>'farast_charge','reference_id'=>$charge->id,'description'=>'تسویه مصرف FARAST','idempotency_key'=>'charge-'.$charge->idempotency_key]);
        $hold->update(['charge_id'=>$charge->id,'status'=>'committed','committed_at'=>now()]);
    }

    private function releaseInternal(FarastUsageReservation $row):void
    {
        if($row->status!=='reserved')return;
        FarastWalletReservation::where('idempotency_key',$row->idempotency_key)->where('status','reserved')->update(['status'=>'released','released_at'=>now()]);
        $row->update(['status'=>'released','released_at'=>now()]);
    }

    private function mode(User $actor,string $capability,float $quantity,?FarastEntitlement $entitlement,array $quote,array $context):string
    {
        if($actor->isAdmin())return'administrator';
        if($entitlement){
            $available=$entitlement->quantity===null?PHP_FLOAT_MAX:max(0,(float)$entitlement->quantity-(float)$entitlement->used_quantity-$this->reservedForEntitlement((int)$entitlement->id));
            if($available>=$quantity)return(string)$entitlement->mode;
            if(($context['allow_overage']??false)&&$quote['available'])return'overage';
            if(($context['allow_payg']??true)&&$quote['available'])return'payg';
            throw new RuntimeException('quota_exhausted');
        }
        if($this->legacyAllows($actor,$capability))return'free';
        if(($context['allow_payg']??true)&&$quote['available']){
            if(!($context['postpaid']??false)&&!$this->canAfford($actor,(int)$quote['total']))throw new RuntimeException('insufficient_credit');
            return'payg';
        }
        throw new RuntimeException('not_entitled');
    }

    private function findEntitlement(User $actor,string $capability,array $scope,?int $organizationId,bool $lock=false):?FarastEntitlement
    {
        $q=FarastEntitlement::query()->where('capability_code',$capability)->where('status','active')
            ->where(fn($x)=>$x->where('user_id',$actor->id)->orWhereNull('user_id'))
            ->where(fn($x)=>$x->whereNull('organization_id')->orWhere('organization_id',$organizationId))
            ->where(function($x)use($scope){if(!empty($scope['project_id']))$x->where('project_id',$scope['project_id'])->orWhereNull('project_id');else$x->whereNull('project_id');})
            ->where(fn($x)=>$x->whereNull('starts_at')->orWhere('starts_at','<=',now()))
            ->where(fn($x)=>$x->whereNull('ends_at')->orWhere('ends_at','>',now()))
            ->orderByDesc('priority')->orderByRaw('CASE WHEN project_id IS NULL THEN 0 ELSE 1 END DESC')->orderByRaw('CASE WHEN user_id IS NULL THEN 0 ELSE 1 END DESC');
        if($lock)$q->lockForUpdate();
        return$q->first();
    }

    private function reservedForEntitlement(int $id):float{return(float)FarastUsageReservation::where('entitlement_id',$id)->where('status','reserved')->sum('quantity');}
    private function canAfford(User $actor,int $amount):bool{if($amount<=0)return true;$wallet=Wallet::firstOrCreate(['user_id'=>$actor->id],['balance_rials'=>0]);$held=(int)FarastWalletReservation::where('wallet_id',$wallet->id)->where('status','reserved')->sum('amount');return(int)$wallet->balance_rials-$held>=$amount;}
    private function legacyAllows(User $actor,string $capability):bool{if($actor->isAdmin())return true;return match($capability){'document.editing'=>$this->capabilities->allowed($actor,'can_type'),'ai.assistance','ai.generation','ai.rewriting','ai.correction'=>$this->capabilities->allowed($actor,'can_ai'),'ocr','handwriting.ocr','document.intelligence'=>$this->capabilities->allowed($actor,'can_ai'),'speech.transcription'=>$this->capabilities->allowed($actor,'can_voice'),'feedback.submit'=>$this->capabilities->allowed($actor,'can_feedback'),'export.docx'=>$this->capabilities->allowed($actor,'can_export_docx'),'export.pdf'=>$this->capabilities->allowed($actor,'can_export_pdf'),default=>false};}
    private function decision(bool $allowed,?FarastEntitlement $entitlement,string $mode,?float $remaining,string $unit,array $quote,?string $reservation,array $limits,?string $reason):AuthorizationDecision{return new AuthorizationDecision($allowed,$entitlement?->id,$mode,$remaining,$unit,$quote['pricing_policy_version_id']??null,$quote,$reservation,$limits,$reason);}
    private function safeContext(array $context):array{return array_intersect_key($context,array_flip(['policy_code','region_code','currency','unit','allow_payg','allow_overage','postpaid','reservation_ttl_minutes']));}
}