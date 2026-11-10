<?php

namespace App\Http\Controllers;

use App\Models\StoreProductDocument;
use App\Services\ProductDocumentRetryService;
use App\Services\ProductSupervisorClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class AdminProductOperationsController extends Controller
{
    public function index(ProductSupervisorClient $supervisor)
    {
        $statuses = ['pending', 'processing', 'completed', 'extracted', 'partial', 'failed', 'stale', 'awaiting_provider'];
        $documentStats = array_fill_keys($statuses, 0);

        foreach (DB::table('store_product_documents')
            ->select('processing_status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('processing_status')
            ->get() as $row) {
            if (array_key_exists($row->processing_status, $documentStats)) {
                $documentStats[$row->processing_status] = (int) $row->aggregate;
            }
        }

        $documents = StoreProductDocument::query()
            ->with(['product:id,title,slug', 'file:id,product_id,original_name,size_bytes,scan_status,is_active'])
            ->whereIn('processing_status', ['failed', 'stale', 'awaiting_provider'])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        $queueStats = [
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->whereIn('queue', ['documents', 'documents-audio', 'default'])->count() : null,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];

        $privateRoot = (string) config('filesystems.disks.private.root');
        $audioConfigured = filled(config('services.document_audio.url'))
            && filled(config('services.document_audio.token'));

        return view('admin.store.operations', [
            'documentStats' => $documentStats,
            'documents' => $documents,
            'queueStats' => $queueStats,
            'supervisorConfigured' => $supervisor->configured(),
            'audioConfigured' => $audioConfigured,
            'queueDriver' => config('queue.default'),
            'scheduledWorker' => (bool) config('queue.scheduled_worker', true),
            'privateStorageWritable' => $privateRoot !== '' && is_dir($privateRoot) && is_writable($privateRoot),
            'databaseConnection' => config('database.default'),
        ]);
    }

    public function retry(Request $request, StoreProductDocument $document, ProductDocumentRetryService $retryService)
    {
        $retryService->retry((int) $document->id, (int) $request->user()->id);

        return back()->with('status', 'سند پس از تأیید سلامت فایل و ثبت رویداد ممیزی دوباره وارد صف شد.');
    }
}
