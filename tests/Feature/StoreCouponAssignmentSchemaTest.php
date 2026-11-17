<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Schema;
use Tests\\TestCase;

class StoreCouponAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_expiry_uses_datetime_for_mysql_compatibility(): void
    {
        $this->assertTrue(Schema::hasColumn('store_coupon_assignments', 'expires_at'));
        $this->assertSame('datetime', Schema::getColumnType('store_coupon_assignments', 'expires_at'));
    }
}
