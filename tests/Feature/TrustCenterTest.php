<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustCenterTest extends TestCase
{
    public function test_trust_center_is_public_and_does_not_claim_unregistered_badges(): void
    {
        $response = $this->get('/trust');

        $response->assertOk()
            ->assertSee('اعتبار و نمادهای اعتماد فراست')
            ->assertSee('نمادها و مجوزهای رسمی')
            ->assertSee('هنوز نمادی ثبت نشده')
            ->assertSee('هیچ نشان نمونه یا غیرواقعی در این صفحه قرار نگرفته است.')
            ->assertSee('farast-footer-trust', false)
            ->assertSee('مشاهده همه نمادها و مجوزها')
            ->assertDontSee('نماد اعتماد الکترونیکی فعال');
    }
}
