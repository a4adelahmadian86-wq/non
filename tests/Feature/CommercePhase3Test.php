<?php

namespace Tests\Feature;

use App\Models\FarastEntitlement;
use App\Models\FarastPricingPolicyVersion;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CommerceAuthorizationService;
use App\Services\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercePhase3Test extends TestCase
{
    use RefreshDatabase;

    private function policy(string $code='ai.assistance', int $price=10000, int $taxBp=1000): FarastPricingPolicyVersion
    {
        return FarastPricingPolicyVersion::create([
            'policy_code'=>$code,'version'=>1,'capability_code'=>$code,'unit'=>'request','currency'=>'IRR',
            'unit_price'=>$price,'additional_unit_price'=>$price,'fee_amount'=>500,'discount_basis_points'=>500,
            'tax_basis_points'=>$taxBp,'payg_multiplier_basis_points'=>10000,'included_quantity'=>0,
            'effective_from'=>now()->subMinute(),'checksum'=>hash('sha256',$code.'|1|'.$price),
        ]);
    }

    public function test_pricing_is_deterministic_and_preserves_policy_version(): void
    {
        $this->policy('deterministic',10000,1000);
        $quote=app(PricingEngine::class)->quote('deterministic',2);

        $this->assertTrue($quote['available']);
        $this->assertSame(1,$quote['policy_version']);
        $this->assertSame(20000,$quote['subtotal']);
        $this->assertSame(1000,$quote['discount']);
        $this->assertSame(500,$quote['fee']);
        $this->assertSame(1950,$quote['tax']);
        $this->assertSame(21450,$quote['total']);
    }

    public function test_subscription_entitlement_reservation_commit_and_charge_debit_once(): void
    {
        $this->policy();
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>50000]);
        $ent=FarastEntitlement::create([
            'user_id'=>$user->id,'capability_code'=>'ai.assistance','mode'=>'subscription','status'=>'active',
            'quantity'=>2,'used_quantity'=>0,'unit'=>'request','priority'=>100,'starts_at'=>now()->subMinute(),
        ]);

        $commerce=app(CommerceAuthorizationService::class);
        $decision=$commerce->authorize($user,'ai.assistance',[],1,['policy_code'=>'ai.assistance','unit'=>'request']);
        $this->assertTrue($decision->allowed);
        $this->assertSame('subscription',$decision->mode);

        $reservation=$commerce->reserve($user,'ai.assistance',1,[],['policy_code'=>'ai.assistance','unit'=>'request','idempotency_key'=>'test-ai-1']);
        $event=$commerce->commit($reservation,['metadata'=>['test'=>true]]);

        $this->assertDatabaseHas('farast_usage_events',['id'=>$event->id,'idempotency_key'=>'test-ai-1']);
        $this->assertDatabaseHas('farast_charges',['idempotency_key'=>'test-ai-1','total'=>0]);
        $this->assertSame(1,(int)$ent->fresh()->used_quantity);

        $again=$commerce->reserve($user,'ai.assistance',1,[],['policy_code'=>'ai.assistance','unit'=>'request','idempotency_key'=>'test-ai-1']);
        $this->assertSame($reservation->id,$again->id);
        $eventAgain=$commerce->commit($again);
        $this->assertSame($event->id,$eventAgain->id);
        $this->assertSame(1,DB::table('farast_usage_events')->where('idempotency_key','test-ai-1')->count());
    }

    public function test_payg_reservation_holds_and_commit_debits_wallet(): void
    {
        $this->policy('paid.tool',10000,0);
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>25000]);
        $commerce=app(CommerceAuthorizationService::class);

        $reservation=$commerce->reserve($user,'paid.tool',1,[],['policy_code'=>'paid.tool','unit'=>'request','idempotency_key'=>'payg-1']);
        $this->assertDatabaseHas('farast_wallet_reservations',['idempotency_key'=>'payg-1','status'=>'reserved']);

        $commerce->commit($reservation);
        $this->assertSame(15000,(int)$user->fresh()->wallet->balance_rials);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'debit','amount_rials'=>10000]);
    }

    public function test_failed_execution_releases_reservation_without_charge(): void
    {
        $this->policy('failed.tool',10000,0);
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>20000]);
        $commerce=app(CommerceAuthorizationService::class);

        $reservation=$commerce->reserve($user,'failed.tool',1,[],['policy_code'=>'failed.tool','unit'=>'request','idempotency_key'=>'failed-1']);
        $commerce->release($reservation);

        $this->assertDatabaseHas('farast_usage_reservations',['idempotency_key'=>'failed-1','status'=>'released']);
        $this->assertDatabaseHas('farast_wallet_reservations',['idempotency_key'=>'failed-1','status'=>'released']);
        $this->assertDatabaseMissing('farast_usage_events',['idempotency_key'=>'failed-1']);
        $this->assertSame(20000,(int)$user->fresh()->wallet->balance_rials);
    }

    public function test_insufficient_credit_is_denied_and_refund_is_idempotent(): void
    {
        $this->policy('expensive',50000,0);
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>1000]);
        $commerce=app(CommerceAuthorizationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insufficient_credit');
        $commerce->reserve($user,'expensive',1,[],['policy_code'=>'expensive','unit'=>'request','idempotency_key'=>'no-money']);
    }

    public function test_refund_returns_credit_without_mutating_historical_charge_snapshot(): void
    {
        $this->policy('refundable',10000,0);
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>20000]);
        $commerce=app(CommerceAuthorizationService::class);

        $reservation=$commerce->reserve($user,'refundable',1,[],['policy_code'=>'refundable','unit'=>'request','idempotency_key'=>'refund-1']);
        $commerce->commit($reservation);
        $charge=\App\Models\FarastCharge::where('idempotency_key','refund-1')->firstOrFail();
        $refund=$commerce->refund($charge,10000,'customer_request','refund-key-1');

        $this->assertSame('completed',$refund->status);
        $this->assertSame(20000,(int)$user->fresh()->wallet->balance_rials);
        $again=$commerce->refund($charge,10000,'customer_request','refund-key-1');
        $this->assertSame($refund->id,$again->id);
        $this->assertSame(10000,(int)$charge->fresh()->total);
        $this->assertSame('refunded',$charge->fresh()->status);
    }
}
