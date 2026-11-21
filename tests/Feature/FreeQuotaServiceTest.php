<?php

namespace Tests\\Feature;

use App\\Models\\FreeCredit;
use App\\Models\\User;
use App\\Services\\FreeQuotaService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class FreeQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_allowance_is_created_once_and_consumed_without_overdraw(): void
    {
        $user = User::factory()->create();
        $quota = app(FreeQuotaService::class);

        $this->assertSame(3, $quota->availablePages($user, 3));
        $this->assertSame(2, $quota->consume($user, 2, 3));
        $this->assertSame(1, $quota->availablePages($user, 3));
        $this->assertSame(1, $quota->consume($user, 5, 3));
        $this->assertSame(0, $quota->availablePages($user, 3));

        $this->assertSame(1, FreeCredit::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('free_credits', [
            'user_id' => $user->id,
            'granted_pages' => 3,
            'consumed_pages' => 3,
        ]);
    }

    public function test_configured_grant_changes_do_not_reset_consumed_pages(): void
    {
        $user = User::factory()->create();
        $quota = app(FreeQuotaService::class);

        $this->assertSame(4, $quota->availablePages($user, 4));
        $this->assertSame(2, $quota->consume($user, 2, 4));

        $this->assertSame(3, $quota->availablePages($user, 5));
        $this->assertSame(0, $quota->availablePages($user, 1));

        $this->assertDatabaseHas('free_credits', [
            'user_id' => $user->id,
            'granted_pages' => 1,
            'consumed_pages' => 2,
        ]);

        $this->assertSame(1, $quota->availablePages($user, 3));
    }

    public function test_non_positive_allowances_and_consumption_do_not_create_credit_rows(): void
    {
        $user = User::factory()->create();
        $quota = app(FreeQuotaService::class);

        $this->assertSame(0, $quota->availablePages($user, 0));
        $this->assertSame(0, $quota->consume($user, 0, 1));
        $this->assertSame(0, $quota->consume($user, 1, 0));
        $this->assertDatabaseCount('free_credits', 0);
    }
}
