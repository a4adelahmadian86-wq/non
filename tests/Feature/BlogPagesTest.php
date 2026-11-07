<?php

namespace Tests\\Feature;

use Tests\\TestCase;

class BlogPagesTest extends TestCase
{
    public function test_blog_index_displays_the_featured_story_and_article_collection(): void
    {
        $this->get(route('blog'))
            ->assertOk()
            ->assertSee('مجله فراست')
            ->assertSee('از صفحه سفید تا متن نهایی')
            ->assertSee('تازه‌های مجله');
    }

    public function test_blog_search_filters_the_article_collection(): void
    {
        $this->get(route('blog', ['q' => 'OCR']))
            ->assertOk()
            ->assertSee('اسکن تا سند قابل ویرایش')
            ->assertDontSee('از صفحه سفید تا متن نهایی');
    }

    public function test_blog_article_includes_reading_tools(): void
    {
        $this->get(route('blog.show', ['slug' => 'workflow-writing']))
            ->assertOk()
            ->assertSee('حالت مطالعه')
            ->assertSee('ذخیره برای بعد')
            ->assertSee('در این مقاله');
    }

    public function test_unknown_blog_article_returns_not_found(): void
    {
        $this->get('/blog/not-a-real-article')->assertNotFound();
    }
}
