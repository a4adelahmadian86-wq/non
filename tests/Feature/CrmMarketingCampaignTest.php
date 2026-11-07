<?php

namespace Tests\Feature;

use App\Models\FarastNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmMarketingCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function user(string $mobile, string $role = User::ROLE_ADMIN): User
    {
        return User::create([
            'name' => 'Marketing '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_segment_preview_and_campaign_send_create_in_app_notifications_once(): void
    {
        $admin = $this->user('09151110001');
        $customer = $this->user('09151110002', User::ROLE_EMPLOYEE);
        $other = $this->user('09151110003', User::ROLE_EMPLOYEE);
        Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 100000, 'discount_rials' => 0, 'tax_rials' => 0,
            'total_rials' => 100000, 'status' => 'paid', 'pricing_snapshot' => ['kind' => 'test'],
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'خریداران', 'filters' => ['paid_customer' => true],
        ])->assertCreated()->assertJsonPath('audience_count', 1);

        $segmentId = DB::table('crm_customer_segments')->value('id');
        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'اطلاع‌رسانی خرید', 'segment_id' => $segmentId,
            'title' => 'خبر جدید', 'body' => 'یک پیام آزمایشی',
        ])->assertCreated()->assertJsonPath('audience_count', 1);

        $campaignId = DB::table('crm_marketing_campaigns')->value('id');
        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')
            ->assertOk()->assertJsonPath('recipient_count', 1);

        $this->assertDatabaseHas('crm_marketing_campaigns', ['id' => $campaignId, 'status' => 'sent']);
        $this->assertDatabaseHas('crm_campaign_recipients', ['campaign_id' => $campaignId, 'user_id' => $customer->id]);
        $this->assertDatabaseMissing('crm_campaign_recipients', ['campaign_id' => $campaignId, 'user_id' => $other->id]);
        $this->assertDatabaseHas('farast_notifications', ['user_id' => $customer->id, 'type' => 'announcement_campaign']);

        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')->assertStatus(409);
        $this->assertSame(1, FarastNotification::where('type', 'announcement_campaign')->count());
    }

    public function test_marketing_segments_and_campaigns_are_scoped_to_their_creator_domain(): void
    {
        $orgA = \App\Models\Organization::create(['name' => 'Campaign Scope A', 'slug' => 'campaign-scope-a', 'status' => 'active']);
        $orgB = \App\Models\Organization::create(['name' => 'Campaign Scope B', 'slug' => 'campaign-scope-b', 'status' => 'active']);

        $adminA = $this->user('09151110007');
        $adminA->organization_id = $orgA->id;
        $adminA->save();
        $adminB = $this->user('09151110008');
        $adminB->organization_id = $orgB->id;
        $adminB->save();

        $customerA = $this->user('09151110009', User::ROLE_EMPLOYEE);
        $customerA->organization_id = $orgA->id;
        $customerA->save();
        $customerB = $this->user('09151110010', User::ROLE_EMPLOYEE);
        $customerB->organization_id = $orgB->id;
        $customerB->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($adminA)->postJson('/admin/crm/marketing/segments', [
            'name' => 'سگمنت سازمان الف',
            'filters' => [],
        ])->assertCreated();

        $segmentId = DB::table('crm_customer_segments')->where('created_by', $adminA->id)->value('id');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'کمپین سازمان الف',
            'segment_id' => $segmentId,
            'title' => 'پیام سازمان الف',
            'body' => 'این پیام فقط برای سازمان الف است.',
        ])->assertCreated();

        $campaignId = DB::table('crm_marketing_campaigns')->where('created_by', $adminA->id)->value('id');

        $this->actingAs($adminB)
            ->getJson('/admin/crm/marketing/segments')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->getJson('/admin/crm/marketing/campaigns')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'استفاده غیرمجاز از سگمنت',
            'segment_id' => $segmentId,
            'title' => 'پیام',
            'body' => 'نباید ساخته شود.',
        ])->assertNotFound();

        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')
            ->assertNotFound();

        $this->assertDatabaseMissing('crm_campaign_recipients', [
            'campaign_id' => $campaignId,
            'user_id' => $customerB->id,
        ]);
        $this->assertDatabaseMissing('farast_notifications', [
            'user_id' => $customerB->id,
            'type' => 'announcement_campaign',
        ]);
    }

    public function test_campaign_recipient_audience_respects_organization_scope(): void
    {
        $orgA = \App\Models\Organization::create(['name' => 'Marketing A', 'slug' => 'marketing-a', 'status' => 'active']);
        $orgB = \App\Models\Organization::create(['name' => 'Marketing B', 'slug' => 'marketing-b', 'status' => 'active']);
        $admin = $this->user('09151110004');
        $admin->organization_id = $orgA->id;
        $admin->save();
        $inside = $this->user('09151110005', User::ROLE_EMPLOYEE);
        $outside = $this->user('09151110006', User::ROLE_EMPLOYEE);
        $inside->organization_id = $orgA->id;
        $inside->save();
        $outside->organization_id = $orgB->id;
        $outside->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'همه مشتریان', 'filters' => [],
        ])->assertCreated()->assertJsonPath('audience_count', 1);
    }
}
