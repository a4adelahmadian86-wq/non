<?php

namespace Tests\Feature;

use App\Models\FarastCommercialPlan;
use App\Models\FarastEntitlement;
use App\Models\FarastPricingPolicyVersion;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ProfessionalCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfessionalCommercialEngineTest extends TestCase
{
    use RefreshDatabase;

    private function policy(string $code='professional.ai', int $price=10000, string $unit='request'): FarastPricingPolicyVersion
    {
        return FarastPricingPolicyVersion::create([
            'policy_code'=>$code,'version'=>1,'capability_code'=>$code,'unit'=>$unit,'currency'=>'IRR',
            'unit_price'=>$price,'additional_unit_price'=>$price,'fee_amount'=>500,'discount_basis_points'=>0,
            'tax_basis_points'=>1000,'payg_multiplier_basis_points'=>10000,'included_quantity'=>0,
            'effective_from'=>now()->subMinute(),'checksum'=>hash('sha256',$code.'|1|'.$price),
        ]);
    }

    public function test_quote_is_distinct_from_charge_and_persists_policy_version(): void
    {
        $this->policy();
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>50000]);

        $quote=app(ProfessionalCommerceService::class)->quote($user,'professional.ai',2,['policy_code'=>'professional.ai','unit'=>'request','idempotency_key'=>'quote-1']);

        $this->assertSame('valid',$quote->status);
        $this->assertSame(1,(int)$quote->pricing_policy_version_id);
        $this->assertSame(2,$quote->quantity);
        $this->assertDatabaseMissing('farast_charges',['idempotency_key'=>'quote-1']);
    }

    public function test_subscription_quota_and_payg_fallback_are_real(): void
    {
        $this->policy();
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>50000]);
        $ent=FarastEntitlement::create([
            'user_id'=>$user->id,'capability_code'=>'professional.ai','mode'=>'subscription','status'=>'active',
            'quantity'=>2,'used_quantity'=>0,'unit'=>'request','priority'=>100,'starts_at'=>now()->subMinute(),
        ]);

        $commerce=app(\App\Services\CommerceAuthorizationService::class);
        $first=$commerce->reserve($user,'professional.ai',2,[],['policy_code'=>'professional.ai','unit'=>'request','idempotency_key'=>'sub-1']);
        $commerce->commit($first);
        $second=$commerce->reserve($user,'professional.ai',1,[],['policy_code'=>'professional.ai','unit'=>'request','idempotency_key'=>'payg-1']);
        $commerce->commit($second);

        $this->assertSame(2,(int)$ent->fresh()->used_quantity);
        $this->assertDatabaseHas('farast_charges',['idempotency_key'=>'payg-1','total'=>11550]);
    }

    public function test_temporary_purchase_and_organization_entitlement_are_supported(): void
    {
        $user=User::factory()->create();
        $ent=FarastEntitlement::create([
            'user_id'=>null,'organization_id'=>$user->organization_id,'capability_code'=>'org.ocr','mode'=>'temporary_purchase',
            'status'=>'active','quantity'=>10,'used_quantity'=>0,'unit'=>'page','priority'=>200,'starts_at'=>now()->subMinute(),
        ]);
        $this->assertNotNull($ent->organization_id);
    }

    public function test_cost_and_usage_are_idempotent(): void
    {
        $service=app(\App\Services\CostEventService::class);
        $a=$service->record(['capability'=>'professional.ai','quantity'=>1,'unit'=>'request','cost_amount'=>2000,'idempotency_key'=>'cost-1']);
        $b=$service->record(['capability'=>'professional.ai','quantity'=>1,'unit'=>'request','cost_amount'=>2000,'idempotency_key'=>'cost-1']);
        $this->assertSame($a->id,$b->id);
    }

    public function test_partial_refund_cannot_exceed_charge(): void
    {
        $this->policy('refundable');
        $user=User::factory()->create();
        Wallet::create(['user_id'=>$user->id,'balance_rials'=>50000]);
        $commerce=app(\App\Services\CommerceAuthorizationService::class);
        $reservation=$commerce->reserve($user,'refundable',1,[],['policy_code'=>'refundable','unit'=>'request','idempotency_key'=>'refund-partial']);
        $commerce->commit($reservation);
        $charge=\App\Models\FarastCharge::where('idempotency_key','refund-partial')->firstOrFail();
        $commerce->refund($charge,5000,'partial','refund-partial-1');
        $commerce->refund($charge,5000,'partial','refund-partial-2');
        $this->expectException(\RuntimeException::class);
        $commerce->refund($charge,1,'excess','refund-partial-3');
    }

    public function test_plan_subscription_creates_real_entitlements(): void
    {
        $user=User::factory()->create();
        $plan=FarastCommercialPlan::create([
            'code'=>'pro','name'=>'Pro','billing_interval'=>'monthly','price'=>100000,'currency'=>'IRR',
            'quotas'=>['ai_requests'=>200,'ocr_pages'=>100,'voice_minutes'=>60],
            'allow_payg'=>true,'active'=>true,
        ]);
        app(ProfessionalCommerceService::class)->subscribe($user,$plan);
        $this->assertDatabaseHas('farast_entitlements',['user_id'=>$user->id,'capability_code'=>'ai.assistance','quantity'=>200]);
        $this->assertDatabaseHas('farast_entitlements',['user_id'=>$user->id,'capability_code'=>'ocr','quantity'=>100]);
    }
}
