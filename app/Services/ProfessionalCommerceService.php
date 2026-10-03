<?php

namespace App\Services;

use App\Models\FarastCommercialPlan;
use App\Models\FarastCommercialProduct;
use App\Models\FarastEntitlement;
use App\Models\FarastPriceQuote;
use App\Models\FarastSubscription;
use App\Models\FarastUsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProfessionalCommerceService
{
    public function __construct(private CommerceAuthorizationService $commerce, private SubscriptionEntitlementService $subscriptions) {}

    public function quote(User $actor,string $capability,float $quantity,array $context=[]): FarastPriceQuote
    {
        if($quantity<0)throw new RuntimeException('quantity_must_be_non_negative');
        $key=(string)($context['idempotency_key']??Str::uuid());
        $existing=FarastPriceQuote::where('idempotency_key',$key)->first();
        if($existing&&$existing->expires_at?->isFuture())return $existing;
        $decision=$this->commerce->authorize($actor,$capability,$context['scope']??[],$quantity,array_merge($context,['reserve'=>false]));
        $quote=$decision->pricingPolicy;
        $expires=now()->addSeconds(max(30,(int)($context['quote_ttl_seconds']??300)));
        return FarastPriceQuote::updateOrCreate(['idempotency_key'=>$key],[
            'quote_id'=>(string)Str::uuid(),'actor_id'=>$actor->id,'organization_id'=>$actor->organization_id,
            'capability'=>$capability,'quantity'=>$quantity,'unit'=>$decision->unit,'currency'=>$quote['currency']??'IRR',
            'pricing_policy_version_id'=>$decision->pricingPolicyVersionId,'subtotal'=>(int)($quote['subtotal']??0),
            'discount'=>(int)($quote['discount']??0),'fee'=>(int)($quote['fee']??0),'tax'=>(int)($quote['tax']??0),
            'total'=>(int)($quote['total']??0),'status'=>$decision->allowed?'valid':'denied','expires_at'=>$expires,
            'snapshot'=>['decision'=>$decision->toArray(),'quote'=>$quote,'context'=>$this->safeContext($context)],
        ]);
    }

    public function createProduct(array $data): FarastCommercialProduct { return FarastCommercialProduct::create($data); }
    public function createPlan(array $data): FarastCommercialPlan { return FarastCommercialPlan::create($data); }

    public function subscribe(User $actor,FarastCommercialPlan $plan): FarastSubscription
    {
        if(!$plan->active)throw new RuntimeException('plan_inactive');
        return DB::transaction(function()use($actor,$plan){
            $subscription=FarastSubscription::create([
                'subscription_id'=>(string)Str::uuid(),'user_id'=>$actor->id,'organization_id'=>$actor->organization_id,
                'plan_id'=>$plan->id,'status'=>'active','starts_at'=>now(),
                'ends_at'=>$plan->billing_interval==='monthly'?now()->addMonth():($plan->billing_interval==='yearly'?now()->addYear():null),
                'metadata'=>['plan_code'=>$plan->code,'currency'=>$plan->currency,'price'=>(int)$plan->price,'quotas'=>$plan->quotas,
                    'allow_payg'=>(bool)$plan->allow_payg,'allow_overage'=>(bool)$plan->allow_overage,'postpaid'=>(bool)$plan->postpaid],
            ]);
            $this->subscriptions->sync($actor,(int)$plan->id);
            return $subscription;
        });
    }

    public function grantTemporaryPurchase(User $actor,string $capability,float $quantity,string $unit='unit',?int $projectId=null,array $metadata=[]): FarastEntitlement
    {
        if($quantity<=0)throw new RuntimeException('quantity_must_be_positive');
        return FarastEntitlement::create([
            'user_id'=>$actor->id,'organization_id'=>$actor->organization_id,'project_id'=>$projectId,'capability_code'=>$capability,
            'mode'=>'temporary_purchase','status'=>'active','quantity'=>(int)ceil($quantity),'used_quantity'=>0,'unit'=>$unit,'priority'=>200,
            'source_type'=>'temporary_purchase','source_id'=>(string)($metadata['purchase_id']??Str::uuid()),'starts_at'=>now(),
            'ends_at'=>$metadata['ends_at']??now()->addDays(30),'metadata'=>$metadata,
        ]);
    }

    public function recordUsageCounter(User $actor,string $capability,string $unit,float $quantity,?string $scopeKey=null): FarastUsageCounter
    {
        if($quantity<0)throw new RuntimeException('quantity_must_be_non_negative');
        $scope=$scopeKey?:'user:'.$actor->id;
        $start=now()->startOfMonth();$end=now()->endOfMonth();
        $counter=FarastUsageCounter::firstOrCreate(
            ['scope_key'=>$scope,'capability'=>$capability,'unit'=>$unit,'period_start'=>$start],
            ['quantity'=>0,'period_end'=>$end]
        );
        $counter->increment('quantity',$quantity);
        return $counter->fresh();
    }

    private function safeContext(array $context):array
    {
        return array_intersect_key($context,array_flip(['policy_code','region_code','currency','unit','allow_payg','allow_overage','postpaid','quote_ttl_seconds','scope']));
    }
}
