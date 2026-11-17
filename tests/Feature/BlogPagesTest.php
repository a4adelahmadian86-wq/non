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

        $response->assertSee('farast-blog-hero.svg')
            ->assertSee('og:image', false)
            ->assertSee('twitter:image', false)
            ->assertSee('summary_large_image', false)
            ->assertSee('canonical', false);
        $this->assertSame(1, substr_count($response->getContent(), '/js/blog.js?v=20261117social1'));
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
            ->assertSee('images/farast-blog-hero.svg', false)
            ->assertSee('?v=20261117social1', false);
    }

    public function test_each_article_uses_an_existing_local_hero_artwork_asset(): void
    {
        $articleArtwork = [
            'workflow-writing' => 'farast-blog-hero.svg',
            'document-digitization' => 'farast-service-ocr.svg',
            'voice-to-text' => 'farast-service-voice.svg',
            'clean-workspace' => 'farast-service-edit.svg',
            'ai-assisted-editing' => 'farast-home-hero.svg',
            'digital-products' => 'farast-store-hero.svg',
        ];

        foreach ($articleArtwork as $slug => $filename) {
            $path = public_path('images/'.$filename);

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));

            $this->get(route('blog.show', ['slug' => $slug]))
                ->assertOk()
                ->assertSee('/images/'.$filename, false)
                ->assertSee('width="', false)
                ->assertSee('height="', false);
        }
    }

    public function test_article_image_alt_text_describes_the_actual_artwork(): void
    {
        $articleAltText = [
            'document-digitization' => 'سند تصویری و متن استخراج‌شده با فناوری OCR',
            'clean-workspace' => 'نمایی از محیط ویرایش و قالب‌بندی یک سند دیجیتال',
            'digital-products' => 'مجموعه جلدهای رنگی فایل‌ها و منابع دیجیتال',
            'workflow-writing' => 'دفتر یادداشت باز، ابزار نوشتن و نشانه بصری یک ایده در فضای تحریریه',
        ];

        foreach ($articleAltText as $slug => $alt) {
            $this->get(route('blog.show', ['slug' => $slug]))
                ->assertOk()
                ->assertSee('alt="'.$alt.'"', false);
        }
    }

    public function test_unknown_blog_article_returns_not_found(): void
    {
        $this->get('/blog/not-a-real-article')->assertNotFound();
    }
}
