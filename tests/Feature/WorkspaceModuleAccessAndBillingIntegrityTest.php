<?php

namespace Tests\Feature;

use App\Models\FarastCommercialPlan;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceModuleAccessAndBillingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_workspace_module_routes_enforce_permissions_instead_of_relying_on_hidden_navigation(): void
    {
        $observer = User::factory()->create(['role' => User::ROLE_OBSERVER]);

        $this->actingAs($observer)
            ->get(route('platform.workflows'))
            ->assertForbidden();

        $this->post(route('platform.workflows.save'), [
            'name' => 'Unauthorized workflow',
            'trigger_type' => 'document.saved',
            'steps' => [['action' => 'notify', 'message' => 'test']],
        ])->assertForbidden();

        $this->post(route('platform.ocr'), [
            'source_path' => 'private/unowned/source.pdf',
        ])->assertForbidden();

        $this->get(route('platform.security'))->assertForbidden();
        $this->get(route('platform.billing'))->assertForbidden();
    }

    public function test_organization_manager_can_view_and_manage_workflows_with_explicit_permissions(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ORGANIZATION_MANAGER]);

        $this->actingAs($manager)
            ->get(route('platform.workflows'))
            ->assertOk();

        $this->post(route('platform.workflows.save'), [
            'name' => 'Invoice follow-up',
            'trigger_type' => 'order.paid',
            'steps' => [['action' => 'notify', 'message' => 'Payment received']],
            'enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('farast_workflows', [
            'user_id' => $manager->id,
            'name' => 'Invoice follow-up',
            'trigger_type' => 'order.paid',
        ]);
    }

    public function test_workflow_updates_cannot_target_another_users_workflow(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ORGANIZATION_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ORGANIZATION_MANAGER]);

        $foreignWorkflowId = DB::table('farast_workflows')->insertGetId([
            'user_id' => $other->id,
            'name' => 'Foreign workflow',
            'trigger_type' => 'document.saved',
            'steps' => json_encode([['action' => 'notify', 'message' => 'private']]),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)
            ->post(route('platform.workflows.save'), [
                'id' => $foreignWorkflowId,
                'name' => 'Overwritten workflow',
                'trigger_type' => 'document.saved',
                'steps' => [['action' => 'notify', 'message' => 'attempt']],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('farast_workflows', [
            'id' => $foreignWorkflowId,
            'user_id' => $other->id,
            'name' => 'Foreign workflow',
        ]);
    }

    public function test_billing_uses_canonical_commercial_plan_catalog(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        FarastCommercialPlan::create([
            'code' => 'canonical-free',
            'name' => 'Canonical free plan',
            'price' => 0,
            'currency' => 'IRR',
            'quotas' => ['ai_requests' => 25],
            'active' => true,
        ]);

        DB::table('farast_plans')->insert([
            'code' => 'legacy-wrong-plan',
            'name' => 'Legacy plan must not appear',
            'price' => 999999,
            'currency' => 'IRR',
            'quotas' => json_encode(['ai_requests' => 1]),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('platform.billing'))
            ->assertOk()
            ->assertSee('Canonical free plan')
            ->assertDontSee('Legacy plan must not appear');
    }

    public function test_free_plan_activation_creates_canonical_subscription_and_entitlements(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $plan = FarastCommercialPlan::create([
            'code' => 'free-workspace',
            'name' => 'Free Workspace',
            'price' => 0,
            'currency' => 'IRR',
            'billing_interval' => 'monthly',
            'quotas' => [
                'typing_pages' => 10,
                'ai_requests' => 25,
                'ocr_pages' => 5,
            ],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('platform.billing.subscribe', $plan->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $subscription = DB::table('farast_subscriptions')
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertNotEmpty($subscription->subscription_id);
        $this->assertSame((int) $plan->id, (int) $subscription->plan_id);

        $this->assertDatabaseHas('farast_entitlements', [
            'user_id' => $user->id,
            'capability_code' => 'ai.assistance',
            'source_type' => 'subscription',
            'source_id' => (string) $plan->id,
            'status' => 'active',
            'quantity' => 25,
        ]);
        $this->assertDatabaseHas('farast_entitlements', [
            'user_id' => $user->id,
            'capability_code' => 'ocr',
            'source_type' => 'subscription',
            'source_id' => (string) $plan->id,
            'status' => 'active',
            'quantity' => 5,
        ]);
    }

    public function test_paid_plan_cannot_be_activated_without_verified_payment(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $plan = FarastCommercialPlan::create([
            'code' => 'paid-workspace',
            'name' => 'Paid Workspace',
            'price' => 250000,
            'currency' => 'IRR',
            'quotas' => ['ai_requests' => 500],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('platform.billing'))
            ->post(route('platform.billing.subscribe', $plan->id))
            ->assertRedirect(route('platform.billing'))
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseMissing('farast_subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('farast_entitlements', [
            'user_id' => $user->id,
            'source_type' => 'subscription',
            'source_id' => (string) $plan->id,
            'status' => 'active',
        ]);
    }
}
