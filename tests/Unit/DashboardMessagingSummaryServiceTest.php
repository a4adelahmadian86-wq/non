<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DashboardMessagingSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardMessagingSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_counts_only_unread_messages_for_the_current_participant(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sender = User::factory()->create();
        $conversationId = DB::table('conversations')->insertGetId([
            'type' => 'direct',
            'created_by' => $admin->id,
            'last_message_preview' => 'سلام',
            'last_message_at' => now(),
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('conversation_participants')->insert([
            [
                'conversation_id' => $conversationId,
                'user_id' => $admin->id,
                'last_read_message_id' => null,
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'conversation_id' => $conversationId,
                'user_id' => $sender->id,
                'last_read_message_id' => null,
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $messageId = DB::table('conversation_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'user_id' => $sender->id,
            'body' => 'سلام',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(DashboardMessagingSummaryService::class);
        $this->assertSame(1, $service->snapshot($admin)['unread_messages']);

        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $admin->id)
            ->update(['last_read_message_id' => $messageId]);

        $this->assertSame(0, $service->snapshot($admin)['unread_messages']);
    }

    public function test_non_admin_summary_excludes_conversations_with_foreign_organization_members(): void
    {
        $organizationA = DB::table('organizations')->insertGetId([
            'name' => 'Organization A',
            'slug' => 'dashboard-org-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $organizationB = DB::table('organizations')->insertGetId([
            'name' => 'Organization B',
            'slug' => 'dashboard-org-b',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'organization_id' => $organizationA,
        ]);
        $colleague = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'organization_id' => $organizationA,
        ]);
        $foreignMember = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'organization_id' => $organizationB,
        ]);
        $unsupportedSameOrg = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'organization_id' => $organizationA,
        ]);

        $createConversation = function (User $other, string $slug) use ($actor): int {
            $conversationId = DB::table('conversations')->insertGetId([
                'type' => 'direct',
                'created_by' => $actor->id,
                'last_message_preview' => $slug,
                'last_message_at' => now(),
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('conversation_participants')->insert([
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $actor->id,
                    'last_read_message_id' => null,
                    'archived_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $other->id,
                    'last_read_message_id' => null,
                    'archived_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            DB::table('conversation_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $other->id,
                'body' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $conversationId;
        };

        $createConversation($colleague, 'same-organization');
        $createConversation($foreignMember, 'foreign-organization');
        $createConversation($unsupportedSameOrg, 'unsupported-role-same-organization');

        $snapshot = app(DashboardMessagingSummaryService::class)->snapshot($actor);

        $this->assertTrue($snapshot['available']);
        $this->assertSame(1, $snapshot['unread_messages']);
    }

    public function test_snapshot_does_not_expose_messaging_counts_to_accounts_without_messenger_access(): void
    {
        $customer = User::factory()->create(['organization_id' => null]);

        $snapshot = app(DashboardMessagingSummaryService::class)->snapshot($customer);

        $this->assertFalse($snapshot['available']);
        $this->assertSame('forbidden', $snapshot['status']);
        $this->assertNull($snapshot['unread_messages']);
    }
}
