<?php

namespace App\Http\Controllers;

use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminProductSupervisorController extends Controller
{
    public function review(Request $request, StoreProduct $product, ProductSupervisorClient $supervisor, ProductSupervisorSnapshotBuilder $snapshotBuilder)
    {
        $settings = data_get($product->metadata, 'ai_supervisor', []);
        abort_unless((bool) data_get($settings, 'enabled', false), 409, 'ناظر هوشمند برای این محصول فعال نیست.');
        abort_unless($supervisor->configured(), 503, 'سرویس مستقل ناظر هنوز متصل نشده است.');

        $snapshot = $snapshotBuilder->build($product, $settings);
        if ($snapshot['checks'] === []) {
            return back()->withErrors(['ai_supervisor' => 'حداقل یک حوزه بررسی را فعال کنید.']);
        }
        $snapshotHash = $snapshotBuilder->fingerprint($snapshot);

        try {
            $review = $supervisor->review($snapshot);
            DB::transaction(function () use ($product, $review, $snapshotHash) {
                $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                $supervisorMeta = data_get($metadata, 'ai_supervisor', []);
                $review['snapshot_hash'] = $snapshotHash;
                $supervisorMeta['last_review'] = $review;
                $supervisorMeta['last_reviewed_at'] = now()->toISOString();
                $metadata['ai_supervisor'] = $supervisorMeta;
                $locked->update(['metadata' => $metadata]);

                app(AuditEventService::class)->record(
                    'store.product.ai_supervisor_reviewed',
                    auth()->id(),
                    null,
                    'store_product',
                    $locked->id,
                    [
                        'review_id' => $review['review_id'],
                        'verdict' => $review['verdict'],
                        'score' => $review['score'],
                        'model' => $review['model'] ?? null,
                    ],
                    'admin_console'
                );
            });
        } catch (Throwable $exception) {
            Log::warning('Product supervisor review failed', [
                'product_id' => $product->id,
                'exception' => class_basename($exception),
            ]);

            return back()->withErrors(['ai_supervisor' => 'بررسی ناظر انجام نشد؛ اتصال سرویس و تنظیمات آن را بررسی و دوباره تلاش کنید.']);
        }

        return back()->with('status', 'بررسی ناظر هوشمند تکمیل شد؛ نتیجه برای بازبینی مدیر ذخیره شد.');
    }
}
