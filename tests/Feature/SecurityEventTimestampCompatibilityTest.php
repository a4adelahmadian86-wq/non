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
        $this->assertNotNull($event->updated_at);
    }
}
