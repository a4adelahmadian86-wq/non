<?php

namespace App\Http\Controllers;

use App\Models\StoreDownload;
use App\Models\StoreLibraryItem;
use App\Models\StoreProductFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StoreLibraryController extends Controller
{
    public function index(Request $request)
    {
        $items = StoreLibraryItem::query()
            ->with(['product', 'file'])
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->latest('granted_at')
            ->paginate(24);

        return view('store.library', compact('items'));
    }

    public function issue(Request $request, StoreLibraryItem $libraryItem)
    {
        abort_unless($libraryItem->user_id === $request->user()->id && $libraryItem->revoked_at === null, 404);
        $libraryItem->load(['file', 'product']);
        abort_unless($libraryItem->product && $libraryItem->product->type !== 'course', 422, 'برای ورود به دوره از کتابخانه آموزشی استفاده کنید.');
        abort_unless(
            $libraryItem->file
                && $libraryItem->file->is_active
                && (int) $libraryItem->file->product_id === (int) $libraryItem->product_id
                && $libraryItem->file->purpose === 'product_download',
            404,
            'فایل فعال نیست.'
        );

        $disk = Storage::disk($libraryItem->file->disk ?: 'private');
        abort_unless($disk->exists($libraryItem->file->path), 404, 'فایل پیدا نشد.');

        $token = Str::random(64);
        $download = StoreDownload::create([
            'library_item_id' => $libraryItem->id,
            'user_id' => $request->user()->id,
            'product_file_id' => $libraryItem->file->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(10),
            'download_count' => 0,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
        ]);

        return response()->json([
            'ok' => true,
            'url' => URL::temporarySignedRoute(
                'store.download.stream',
                now()->addMinutes(10),
                ['download' => $download->id, 'token' => $token]
            ),
            'expires_at' => $download->expires_at?->toIso8601String(),
        ]);
    }

    public function stream(Request $request, StoreDownload $download)
    {
        abort_unless($request->hasValidSignature(), 403);

        $file = DB::transaction(function () use ($request, $download) {
            $lockedDownload = StoreDownload::query()->whereKey($download->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDownload->expires_at && $lockedDownload->expires_at->isFuture(), 410);
            abort_unless((int) $lockedDownload->user_id === (int) $request->user()->id, 403);
            abort_unless(
                hash_equals($lockedDownload->token_hash, hash('sha256', (string) $request->query('token'))),
                403
            );
            abort_if($lockedDownload->download_count >= 10, 429, 'سقف دریافت این لینک به پایان رسیده است.');

            // Re-check the entitlement while locked so revocation invalidates issued links.
            $libraryItem = StoreLibraryItem::query()
                ->whereKey($lockedDownload->library_item_id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $libraryItem
                    && (int) $libraryItem->user_id === (int) $request->user()->id
                    && $libraryItem->revoked_at === null
                    && (int) $libraryItem->product_file_id === (int) $lockedDownload->product_file_id,
                403
            );

            $file = StoreProductFile::query()
                ->whereKey($lockedDownload->product_file_id)
                ->where('product_id', $libraryItem->product_id)
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->first();
            abort_unless($file, 404, 'فایل فعال نیست.');

            $disk = Storage::disk($file->disk ?: 'private');
            abort_unless($disk->exists($file->path), 404, 'فایل پیدا نشد.');
            if ($file->sha256) {
                $actualHash = hash_file('sha256', $disk->path($file->path));
                abort_unless(is_string($actualHash) && hash_equals((string) $file->sha256, $actualHash), 409, 'محتوای فایل پس از اسکن یا ثبت سفارش تغییر کرده است.');
            }

            // Serialize increments to enforce the per-link download ceiling.
            $lockedDownload->increment('download_count');

            return $file;
        });

        $disk = Storage::disk($file->disk ?: 'private');

        return $disk->response($file->path, $file->original_name ?: basename($file->path), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment',
        ]);
    }
}
