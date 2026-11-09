<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductDocument;
use App\Models\StoreProductIngestion;
use App\Models\StoreProductDocumentPage;
use App\Models\StoreProductImage;
use App\Models\StoreTag;
use App\Models\User;
use App\Services\UploadSecurityService;
use App\Services\ProductKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminStoreProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Store Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function category(): StoreCategory
    {
        return StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => 'test-products',
            'is_active' => true,
        ]);
    }

    private function payload(StoreCategory $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'sku' => 'TEST-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 125000,
            'status' => 'draft',
            'short_description' => 'توضیح کوتاه',
        ], $overrides);
    }

    public function test_new_products_are_always_created_as_private_drafts_and_cover_path_is_not_mass_assignable(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'status' => 'published',
                'cover_path' => 'https://attacker.invalid/fake-cover.jpg',
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-PRODUCT-001')->firstOrFail();

        $response->assertRedirect(route('admin.store.edit', $product));
        $this->assertSame('draft', $product->status);
        $this->assertNull($product->published_at);
        $this->assertNull($product->cover_path);
    }

    public function test_creating_a_course_product_also_creates_its_course_catalog_record(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'title' => 'دوره آزمایشی',
                'sku' => 'TEST-COURSE-001',
                'type' => 'course',
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-COURSE-001')->firstOrFail();

        $response->assertRedirect(route('admin.store.course.edit', $product));
        $this->assertDatabaseHas('store_courses', ['product_id' => $product->id]);
        $this->assertSame('draft', $product->status);
    }

    public function test_product_creation_synchronizes_tags_and_related_products(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $tag = StoreTag::create(['name' => 'طراحی', 'slug' => 'design']);
        $related = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول مکمل',
            'slug' => 'related-product',
            'sku' => 'RELATED-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 50000,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'tag_ids' => [$tag->id],
                'related_product_ids' => [$related->id],
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-PRODUCT-001')->firstOrFail();
        $response->assertRedirect(route('admin.store.edit', $product));
        $this->assertDatabaseHas('store_product_tag', [
            'product_id' => $product->id,
            'tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('store_product_related', [
            'product_id' => $product->id,
            'related_product_id' => $related->id,
        ]);
    }

    public function test_admin_can_create_a_tag_from_the_product_editor(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.store.create'))
            ->post(route('admin.store.tags.store'), [
                'name' => 'بازاریابی',
                'slug' => 'marketing',
            ])
            ->assertRedirect(route('admin.store.create'));

        $this->assertDatabaseHas('store_tags', [
            'name' => 'بازاریابی',
            'slug' => 'marketing',
        ]);
    }

    public function test_product_gallery_upload_is_scanned_and_saved_to_public_storage(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول گالری',
            'slug' => 'gallery-product',
            'sku' => 'GALLERY-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $this->mock(UploadSecurityService::class, function ($mock) {
            $mock->shouldReceive('inspect')->once()->andReturnUsing(function ($path) {
                $checksum = hash_file('sha256', Storage::disk('private')->path($path));

                return [
                    'safe' => true,
                    'mime' => 'image/jpeg',
                    'size' => Storage::disk('private')->size($path),
                    'checksum' => $checksum,
                    'scan' => 'test-clean',
                ];
            });
        });

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->post(route('admin.store.images.upload', $product), [
                'image' => UploadedFile::fake()->image('gallery.jpg', 800, 600),
                'alt' => 'نمای محصول',
            ])
            ->assertRedirect(route('admin.store.edit', $product));

        $image = StoreProductImage::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('نمای محصول', $image->alt);
        $this->assertTrue($image->is_active);
        $this->assertStringStartsWith('storage/store/product-images/'.$product->id.'/', $image->path);
        Storage::disk('public')->assertExists(str_replace('storage/', '', $image->path));
    }

    public function test_gallery_image_deletion_removes_only_the_owned_public_asset(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول گالری',
            'slug' => 'gallery-delete-product',
            'sku' => 'GALLERY-DELETE-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);
        $relativePath = 'store/product-images/'.$product->id.'/gallery.jpg';
        Storage::disk('public')->put($relativePath, 'image-data');
        $image = StoreProductImage::create([
            'product_id' => $product->id,
            'path' => 'storage/'.$relativePath,
            'alt' => 'نمای محصول',
            'kind' => 'image',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->delete(route('admin.store.images.delete', [$product, $image]))
            ->assertRedirect(route('admin.store.edit', $product));

        $this->assertDatabaseMissing('store_product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing($relativePath);
    }

    public function test_compare_at_price_must_be_greater_than_the_sale_price(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)
            ->from(route('admin.store.create'))
            ->post(route('admin.store.store'), $this->payload($category, [
                'compare_at_price_rials' => 125000,
            ]))
            ->assertRedirect(route('admin.store.create'))
            ->assertSessionHasErrors('compare_at_price_rials');

        $this->assertDatabaseMissing('store_products', ['sku' => 'TEST-PRODUCT-001']);
    }

    public function test_last_primary_file_cannot_be_deleted_while_product_is_published(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول منتشرشده',
            'slug' => 'published-product',
            'sku' => 'PUBLISHED-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $path = 'store/products/'.$product->id.'/primary.pdf';
        Storage::disk('private')->put($path, 'safe-test-content');
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'primary.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 17,
            'sha256' => hash('sha256', 'safe-test-content'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'test',
            'scanned_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->delete(route('admin.store.files.delete', [$product, $file]))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseHas('store_product_files', ['id' => $file->id]);
        Storage::disk('private')->assertExists($path);
    }

    public function test_admin_can_poll_document_study_sample_and_audio_status_without_receiving_source_content(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'سند وضعیت پردازش',
            'slug' => 'document-processing-status',
            'sku' => 'DOCUMENT-STATUS-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/products/'.$product->id.'/status.pdf',
            'original_name' => 'status.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);
        StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => str_repeat('a', 64),
            'processing_status' => 'completed',
            'detected_type' => 'pdf',
            'page_count' => 12,
            'word_count' => 2400,
            'character_count' => 15000,
            'structure_json' => [
                'completeness' => [
                    'extraction_complete' => true,
                    'analysis_batches_complete' => true,
                    'analysis_batch_count' => 1,
                    'successful_analysis_batches' => 1,
                ],
                'sample' => ['generated_once' => true, 'selected_pages' => [2, 5, 9]],
                'audio' => ['status' => 'completed', 'duration_ms' => 240000, 'segment_count' => 18],
            ],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.store.files.processing', [$product, $file]))
            ->assertOk()
            ->assertJsonPath('processing_status', 'completed')
            ->assertJsonPath('page_count', 12)
            ->assertJsonPath('sample_status', 'completed')
            ->assertJsonPath('sample_pages', [2, 5, 9])
            ->assertJsonPath('audio_status', 'completed')
            ->assertJsonPath('audio_duration_ms', 240000)
            ->assertJsonMissingPath('content_chunks')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_product_knowledge_uses_only_hash_matched_evidence_backed_facts_and_respects_sample_pages(): void
    {
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'کتاب دانش‌محور',
            'slug' => 'knowledge-book',
            'sku' => 'KNOWLEDGE-BOOK-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);
        $hash = str_repeat('b', 64);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/products/'.$product->id.'/knowledge.pdf',
            'original_name' => 'knowledge.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => $hash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => 'completed',
            'detected_type' => 'pdf',
            'page_count' => 10,
            'structure_json' => [
                'sample' => ['selected_pages' => [2]],
                'completeness' => ['extraction_complete' => true, 'analysis_batches_complete' => true],
                'ai_review' => ['provider_status' => ['groq_text' => 'completed']],
            ],
        ]);
        $document->facts()->createMany([
            [
                'source_sha256' => $hash,
                'fact_type' => 'quantity',
                'fact_key' => 'تعداد فصل‌ها',
                'fact_value' => '۸ فصل',
                'evidence_text' => 'این کتاب شامل ۸ فصل است.',
                'locator_json' => ['page' => 2],
                'verification_status' => 'evidence_backed',
            ],
            [
                'source_sha256' => $hash,
                'fact_type' => 'date',
                'fact_key' => 'تاریخ انتشار',
                'fact_value' => '۱۴۰۴',
                'evidence_text' => 'تاریخ انتشار: ۱۴۰۴',
                'locator_json' => ['page' => 3],
                'verification_status' => 'evidence_backed',
            ],
            [
                'source_sha256' => str_repeat('c', 64),
                'fact_type' => 'quantity',
                'fact_key' => 'اطلاعات قدیمی',
                'fact_value' => '۱۰۰',
                'evidence_text' => 'این مقدار از نسخه قبلی است.',
                'locator_json' => ['page' => 2],
                'verification_status' => 'evidence_backed',
            ],
        ]);

        $service = app(ProductKnowledgeService::class);
        $full = $service->contextForProduct($product);
        $sample = $service->contextForProduct($product, 12000, true);

        $this->assertCount(2, $full['sources'][0]['facts']);
        $this->assertSame('available', $full['knowledge_status']);
        $this->assertTrue($full['sources'][0]['semantic_review_complete']);
        $this->assertCount(1, $sample['sources'][0]['facts']);
        $this->assertSame('تعداد فصل‌ها', $sample['sources'][0]['facts'][0]['key']);
        $this->assertSame('public_sample_only', $sample['access_scope']);
    }

    public function test_published_file_product_requires_an_active_clean_primary_file_on_every_update(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول پیش‌نویس',
            'slug' => 'draft-product',
            'sku' => 'DRAFT-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->payload($category, [
                'title' => $product->title,
                'sku' => $product->sku,
                'status' => 'published',
            ]))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
    }

    public function test_pdf_sample_upload_stores_only_the_configured_pages(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول نمونه PDF',
            'slug' => 'pdf-sample-product',
            'sku' => 'PDF-SAMPLE-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
            'preview_pages' => 2,
        ]);

        $source = new \FPDF();
        $source->SetFont('Arial', '', 14);
        for ($page = 1; $page <= 4; $page++) {
            $source->AddPage();
            $source->Cell(0, 12, 'Private page '.$page);
        }
        $pdfBytes = $source->Output('S');

        $this->mock(UploadSecurityService::class, function ($mock) {
            $mock->shouldReceive('inspect')->once()->andReturnUsing(function ($path) {
                $disk = Storage::disk('private');

                return [
                    'safe' => true,
                    'mime' => 'application/pdf',
                    'size' => $disk->size($path),
                    'checksum' => hash_file('sha256', $disk->path($path)),
                    'scan' => 'test-clean',
                ];
            });
        });

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->post(route('admin.store.previews.upload', $product), [
                'preview' => UploadedFile::fake()->createWithContent('private-document.pdf', $pdfBytes),
                'access_level' => 'sample',
            ])
            ->assertRedirect(route('admin.store.edit', $product));

        $preview = \App\Models\StoreProductPreview::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(4, $preview->source_page_count);
        $this->assertSame(2, $preview->included_page_count);
        Storage::disk('private')->assertExists($preview->path);
        $this->assertSame(2, (new \setasign\Fpdi\Fpdi())->setSourceFile(Storage::disk('private')->path($preview->path)));
    }


    public function test_product_supervisor_snapshot_includes_the_delivery_policy(): void
    {
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول فقط‌خواندنی',
            'slug' => 'snapshot-reader-only',
            'sku' => 'SNAPSHOT-READER-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'download_policy' => 'reader_only',
            'status' => 'draft',
        ]);

        $snapshot = app(\App\Services\ProductSupervisorSnapshotBuilder::class)->build($product, []);

        $this->assertSame('reader_only', $snapshot['download_policy']);
    }

    public function test_delivery_policy_is_exposed_and_reader_only_publication_requires_a_ready_reader(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)
            ->get(route('admin.store.create'))
            ->assertOk()
            ->assertSee('name="download_policy"', false)
            ->assertSee('data-product-progress', false)
            ->assertSee('data-product-step-link', false)
            ->assertSee('فقط مطالعه آنلاین');

        $this->actingAs($admin)->post(route('admin.store.store'), $this->payload($category, [
            'title' => 'محصول سیاست تحویل',
            'sku' => 'DELIVERY-POLICY-001',
            'download_policy' => 'reader_only',
        ]))->assertRedirect();

        $this->assertDatabaseHas('store_products', [
            'sku' => 'DELIVERY-POLICY-001',
            'download_policy' => 'reader_only',
            'status' => 'draft',
        ]);

        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول مطالعه آنلاین',
            'slug' => 'reader-only-product',
            'sku' => 'READER-ONLY-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'download_policy' => 'reader_only',
            'status' => 'draft',
        ]);

        $path = 'store/products/'.$product->id.'/reader-only.pdf';
        $bytes = 'private-reader-source';
        Storage::disk('private')->put($path, $bytes);
        $hash = hash('sha256', $bytes);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'reader-only.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => $hash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => 'extracted',
            'detected_type' => 'pdf',
            'page_count' => 1,
            'structure_json' => ['reader' => ['ready' => false, 'page_count' => 1]],
        ]);
        $publishPayload = $this->payload($category, [
            'title' => $product->title,
            'sku' => $product->sku,
            'status' => 'published',
            'download_policy' => 'reader_only',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $publishPayload)
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);

        $document->update([
            'structure_json' => ['reader' => ['ready' => true, 'page_count' => 1]],
        ]);
        $document->pages()->create([
            'page_number' => 1,
            'page_kind' => 'pdf_page',
            'locator_key' => 'pdf:1',
            'locator_json' => ['page' => 1],
            'content_text' => 'متن صفحه آزمایشی',
            'content_html' => '<p>متن صفحه آزمایشی</p>',
            'content_sha256' => hash('sha256', 'متن صفحه آزمایشی'),
            'has_extracted_text' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $publishPayload)
            ->assertRedirect(route('admin.store.edit', $product));

        $this->assertSame('published', $product->fresh()->status);
        $this->assertSame('reader_only', $product->fresh()->download_policy);
    }

    public function test_replacing_primary_file_unpublishes_reader_only_product_until_reprocessing_finishes(): void
    {
        Storage::fake('private');
        Queue::fake();
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول مطالعه آنلاین منتشرشده',
            'slug' => 'published-reader-only',
            'sku' => 'PUBLISHED-READER-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'download_policy' => 'reader_only',
            'status' => 'published',
            'published_at' => now(),
        ]);
        StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/products/'.$product->id.'/old-primary.pdf',
            'original_name' => 'old-primary.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);

        $newBytes = 'replacement-primary-file';
        $newHash = hash('sha256', $newBytes);
        $stagingPath = 'store/quarantine/'.$product->id.'/replacement.pdf';
        Storage::disk('private')->put($stagingPath, $newBytes);
        $ingestion = StoreProductIngestion::create([
            'id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'user_id' => $admin->id,
            'status' => 'inspected',
            'staging_disk' => 'private',
            'staging_path' => $stagingPath,
            'original_name' => 'replacement.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($newBytes),
            'sha256' => $newHash,
            'inspection_json' => ['safe' => true, 'detected_type' => 'pdf'],
            'is_primary' => true,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->mock(UploadSecurityService::class, function ($mock) use ($stagingPath, $newBytes, $newHash) {
            $mock->shouldReceive('inspect')->once()->with($stagingPath, 'application/pdf', 50 * 1024 * 1024)->andReturn([
                'safe' => true,
                'mime' => 'application/pdf',
                'size' => strlen($newBytes),
                'checksum' => $newHash,
                'scan' => 'test-clean',
                'detected_type' => 'pdf',
                'page_count' => 1,
            ]);
        });

        $response = $this->actingAs($admin)
            ->post(route('admin.store.files.confirm', [$product, $ingestion->id]));

        $response->assertOk()
            ->assertJsonPath('product.status', 'draft')
            ->assertJsonPath('product.requires_republish', true);
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
        $this->assertDatabaseHas('store_product_files', [
            'product_id' => $product->id,
            'sha256' => $newHash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $this->assertDatabaseHas('store_product_ingestions', [
            'id' => $ingestion->id,
            'status' => 'uploaded',
        ]);
        Queue::assertPushed(ProcessProductDocument::class);
    }

}
