<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeConversionExperienceTest extends TestCase
{
    public function test_homepage_explains_value_and_links_to_discovery_destinations(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('مزیت‌های استفاده از فراست')
            ->assertSee('مسیر روشن برای شروع')
            ->assertSee('از فایل تا سند نهایی')
            ->assertSee('انتخاب با کنترل شما')
            ->assertSee('برای قدم بعدی آماده‌اید؟')
            ->assertSee(route('store'), false)
            ->assertSee(route('blog'), false);
    }

    public function test_homepage_includes_shareable_search_metadata(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('property="og:title"', false)
            ->assertSee('name="twitter:card"', false)
            ->assertSee('فراست؛ از ایده تا نتیجه');
    }
}
