<?php

namespace App\Http\Controllers;

use App\Models\StoreDownload;
use App\Models\StoreLibraryItem;
use Illuminate\Http\Request;
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
        abort_unless($libraryItem->file && $libraryItem->file->is_active, 404, 'فایل فعال نیست.');

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
        abort_unless($download->expires_at && $download->expires_at->isFuture(), 410);
        abort_unless($download->user_id === $request->user()->id, 403);
        abort_unless(hash_equals($download->token_hash, hash('sha256', (string) $request->query('token'))), 403);
        abort_unless($download->download_count < 10, 429, 'سقف دریافت این لینک به پایان رسیده است.');

        $download->load(['file', 'libraryItem']);
        abort_unless($download->libraryItem && $download->libraryItem->user_id === $request->user()->id, 403);
        abort_unless($download->file && $download->file->is_active, 404);

        $disk = Storage::disk($download->file->disk ?: 'private');
        abort_unless($disk->exists($download->file->path), 404);

        $download->increment('download_count');

        return $disk->response($download->file->path, $download->file->original_name ?: basename($download->file->path), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment',
        ]);
    }
}
