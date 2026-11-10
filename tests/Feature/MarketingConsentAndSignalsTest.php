<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\SiteSetting;
use App\Services\CrmAudienceService;
use App\Services\MarketingSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingConsentAndSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile, bool $consent = false): User
    {
        $user = User::create([
            'name' => 'Marketing '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
            'marketing_consent' => $consent,
        ]);

        if ($consent) {
            $user->forceFill(['marketing_consent_updated_at' => now()])->save();
        }

        return $user;
    }

    public function test_marketing_signals_are_not_recorded_without_explicit_consent(): void
    {
        $user = $this->user('09151112001');

        $this->assertFalse(app(MarketingSignalService::class)->record($user, 'product_viewed', 42));
        $this->assertDatabaseMissing('farast_workflow_events', ['user_id' => $user->id]);
    }

    public function test_consented_first_party_signals_are_idempotent_per_minute_and_product(): void
    {
        $user = $this->user('09151112002', true);
        $signals = app(MarketingSignalService::class);

        $this->assertTrue($signals->record($user, 'product_viewed', 42));
        $this->assertFalse($signals->record($user, 'product_viewed', 42));
        $this->assertTrue($signals->record($user, 'product_viewed', 43));
        $this->assertSame(2, DB::table('farast_workflow_events')->where('user_id', $user->id)->where('event_type', 'marketing.product_viewed')->count());
    }

    public function test_marketing_audiences_exclude_users_without_consent(): void
    {
        $optedIn = $this->user('09151112003', true);
        $this->user('09151112004', false);

        $this->assertSame([$optedIn->id], app(CrmAudienceService::class)->query([])->pluck('id')->all());
    }

    public function test_marketing_signal_retention_command_prunes_only_old_marketing_events(): void
    {
        $user = $this->user('09151112006', true);
        SiteSetting::write('marketing_signal_retention_days', 30);
        DB::table('farast_workflow_events')->insert([
            [
                'event_type' => 'marketing.product_viewed',
                'idempotency_key' => 'retention-old-'.$user->id,
                'user_id' => $user->id,
                'context' => json_encode(['product_id' => 12]),
                'matched_workflows' => 0,
                'processed_at' => null,
                'created_at' => now()->subDays(31),
                'updated_at' => now()->subDays(31),
            ],
            [
                'event_type' => 'marketing.product_viewed',
                'idempotency_key' => 'retention-recent-'.$user->id,
                'user_id' => $user->id,
                'context' => json_encode(['product_id' => 13]),
                'matched_workflows' => 0,
                'processed_at' => null,
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
        ]);

        \Illuminate\Support\Facades\Artisan::call('store:marketing:prune-signals --limit=100');

        $this->assertDatabaseMissing('farast_workflow_events', ['idempotency_key' => 'retention-old-'.$user->id]);
        $this->assertDatabaseHas('farast_workflow_events', ['idempotency_key' => 'retention-recent-'.$user->id]);
    }

    public function test_user_can_withdraw_consent_and_existing_marketing_signals_are_deleted(): void
    {
        $user = $this->user('09151112005', true);
        app(MarketingSignalService::class)->record($user, 'product_viewed', 42);

        $this->actingAs($user)->put(route('account.marketing-preferences.update'), ['marketing_consent' => 0])
            ->assertRedirect(route('account.marketing-preferences.edit'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'marketing_consent' => false]);
        $this->assertDatabaseMissing('farast_workflow_events', ['user_id' => $user->id, 'event_type' => 'marketing.product_viewed']);
    }
}
