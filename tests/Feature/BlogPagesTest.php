<?php

namespace Tests\Feature;

use Tests\TestCase;

class BlogPagesTest extends TestCase
{
    public function test_blog_index_displays_the_featured_story_and_article_collection(): void
    {
        $response = $this->get(route('blog'));

        $response->assertOk()
            ->assertSee('مجله فراست')
            ->assertSee('از صفحه سفید تا متن نهایی')
            ->assertSee('تازه‌های مجله');

        $this->assertSame(1, substr_count($response->getContent(), '/js/blog.js?v=20261107b'));
        $response->assertSee('fetchpriority="high" decoding="async"', false)
            ->assertSee('loading="lazy" decoding="async"', false);
    }

    public function test_blog_search_filters_the_article_collection(): void
    {
        $this->get(route('blog', ['q' => 'OCR']))
            ->assertOk()
            ->assertSee('اسکن تا سند قابل ویرایش')
            ->assertDontSee('از صفحه سفید تا متن نهایی');
    }

    public function test_blog_article_includes_reading_tools_and_complete_social_image_metadata(): void
    {
        $this->get(route('blog.show', ['slug' => 'workflow-writing']))
            ->assertOk()
            ->assertSee('حالت مطالعه')
            ->assertSee('ذخیره برای بعد')
            ->assertSee('در این مقاله')
            ->assertSee('summary_large_image', false)
            ->assertSee('twitter:image:alt', false)
            ->assertSee('og:image:alt', false)
            ->assertSee('?v=20261117social1', false);
    }

    public function test_unknown_blog_article_returns_not_found(): void
    {
        $this->get('/blog/not-a-real-article')->assertNotFound();
    }
}
