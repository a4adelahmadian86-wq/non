<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityEventTimestampCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_session_tracking_can_write_security_events_with_updated_at(): void
    {
        $this->assertTrue(Schema::hasColumn('farast_security_events', 'created_at'));
        $this->assertTrue(Schema::hasColumn('farast_security_events', 'updated_at'));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('platform.security'))
            ->assertOk();

        $event = DB::table('farast_security_events')
            ->where('user_id', $user->id)
            ->where('event', 'login_session_started')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->created_at);

        // Older middleware versions also write updated_at; the compatibility
        // migration keeps those writes safe while current events remain append-only.
        $legacyWriteId = DB::table('farast_security_events')->insertGetId([
            'user_id' => $user->id,
            'event' => 'legacy_timestamp_compatibility',
            'ip' => '127.0.0.1',
            'user_agent' => 'Compatibility test',
            'meta' => json_encode(['source' => 'test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull(DB::table('farast_security_events')->where('id', $legacyWriteId)->value('updated_at'));
    }
}
