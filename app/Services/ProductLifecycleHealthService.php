<?php

namespace App\Services;

use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductIngestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductLifecycleHealthService
{
    public function __construct(
        private readonly ProductPublicationReadiness $publicationReadiness,
    ) {
    }

    /**
     * Read-only cross-layer snapshot for the product operations center.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $productCounts = DB::table('store_products')
            ->select('type', 'status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('type', 'status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->type.':'.$row->status => (int) $row->aggregate])
            ->all();

        $published = StoreProduct::query()->where('status', 'published');

        $missingSafePrimaryFile = (clone $published)
            ->where('type', 'digital_file')
            ->whereDoesntHave('files', fn ($query) => $query
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->where('scan_status', 'clean'))
            ->count();

        $coursesWithoutPublishableLessons = (clone $published)
            ->where('type', 'course')
            ->whereDoesntHave('course.sections.lessons', fn ($query) => $query->publishable())
            ->count();

        $readerOnlyWithoutReadyDocument = 0;
        (clone $published)
            ->where('type', 'digital_file')
            ->where('download_policy', 'reader_only')
            ->with(['files.document' => fn ($query) => $query->withCount('pages')])
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$readerOnlyWithoutReadyDocument): void {
                foreach ($products as $product) {
                    if (! $this->publicationReadiness->hasReadyPrimaryReader($product)) {
                        $readerOnlyWithoutReadyDocument++;
                    }
                }
            });

        $notYetLive = (clone $published)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '>', now()))
            ->count();

        $ingestionStats = [];
        $expiredInspections = 0;
        if (Schema::hasTable('store_product_ingestions')) {
            $ingestionStats = StoreProductIngestion::query()
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();

            $expiredInspections = StoreProductIngestion::query()
                ->where('status', 'inspected')
                ->where('expires_at', '<=', now())
                ->count();
        }

        $stalePendingDocuments = 0;
        $staleProcessingDocuments = 0;
        if (Schema::hasTable('store_product_documents')) {
            $stalePendingDocuments = StoreProductDocument::query()
                ->where('processing_status', 'pending')
                ->where('updated_at', '<=', now()->subMinutes(30))
                ->count();

            $staleProcessingDocuments = StoreProductDocument::query()
                ->where('processing_status', 'processing')
                ->where('updated_at', '<=', now()->subMinutes(15))
                ->count();
        }

        return [
            'product_counts' => $productCounts,
            'published_missing_safe_primary_file' => $missingSafePrimaryFile,
            'published_courses_without_publishable_lessons' => $coursesWithoutPublishableLessons,
            'reader_only_without_ready_document' => $readerOnlyWithoutReadyDocument,
            'published_not_yet_live' => $notYetLive,
            'ingestion_stats' => $ingestionStats,
            'expired_inspections' => $expiredInspections,
            'stale_pending_documents' => $stalePendingDocuments,
            'stale_processing_documents' => $staleProcessingDocuments,
        ];
    }
}
