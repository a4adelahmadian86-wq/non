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

    public function test_snapshot_does_not_expose_messaging_counts_to_accounts_without_messenger_access(): void
    {
        $customer = User::factory()->create(['organization_id' => null]);

        $snapshot = app(DashboardMessagingSummaryService::class)->snapshot($customer);

        $this->assertFalse($snapshot['available']);
        $this->assertSame('forbidden', $snapshot['status']);
        $this->assertNull($snapshot['unread_messages']);
    }
}
