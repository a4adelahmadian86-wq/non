<?php

namespace App\Http\Controllers;

use App\Models\StoreLibraryItem;
use App\Models\StoreProductDocumentAudio;
use Illuminate\Support\Facades\Storage;

class StoreProductDocumentAudioController extends Controller
{
    public function stream(StoreProductDocumentAudio $audio)
    {
        $audio->load('document.product');
        $document = $audio->document;
        $product = $document?->product;
        $user = request()->user();

        abort_unless(
            $user
                && $product
                && $product->status === 'published'
                && $product->published_at?->lte(now())
                && ($product->download_policy ?? 'signed') === 'reader_only'
                && $audio->state === 'ready'
                && (int) $audio->content_version === (int) $document->content_version
                && StoreLibraryItem::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->whereNull('revoked_at')
                    ->exists(),
            403
        );

        $disk = Storage::disk($audio->storage_disk ?: 'private');
        abort_unless($disk->exists($audio->storage_path), 404);

        return $disk->response($audio->storage_path, null, [
            'Content-Type' => $audio->mime ?: 'audio/wav',
            'Content-Disposition' => 'inline; filename="farast-reader-audio.wav"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
