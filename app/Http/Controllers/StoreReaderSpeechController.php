<?php

namespace App\\Http\\Controllers;

use App\\Models\\StoreLibraryItem;
use App\\Models\\StoreProduct;
use App\\Models\\StoreProductFile;
use App\\Services\\ProductDocumentAudioService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Storage;
use RuntimeException;

class StoreReaderSpeechController extends Controller
{
    public function speak(Request $request, StoreProduct $product, ProductDocumentAudioService $audio)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:8000'],
            'page' => ['required', 'integer', 'min:1', 'max:300'],
        ]);
        $text = trim((string) $validated['text']);
        abort_if($text === '' || mb_strlen($text, 'UTF-8') > 2000, 422);

        $user = $request->user();
        abort_unless($user && $product->status === 'published'
            && $product->published_at?->lte(now())
            && $product->type === 'digital_file'
            && ($product->download_policy ?? 'signed') === 'reader_only'
            && StoreLibraryItem::query()->where('user_id', $user->id)
                ->where('product_id', $product->id)->whereNull('revoked_at')->exists(), 403);

        $file = StoreProductFile::query()->where('product_id', $product->id)
            ->where('purpose', 'product_download')->where('is_primary', true)
            ->where('is_active', true)->where('scan_status', 'clean')->firstOrFail();
        $document = $file->document;
        abort_unless($document && hash_equals((string) $file->sha256, (string) $document->source_sha256)
            && $document->processing_status === 'completed'
            && (bool) data_get($document->structure_json, 'reader.ready', false), 409);

        $page = $document->pages()->where('page_number', (int) $validated['page'])->firstOrFail();
        abort_unless($page->has_extracted_text, 422);

        $normalize = static function (string $value): string {
            $value = str_replace(['ي', 'ى', 'ك', "\\u{0640}"], ['ی', 'ی', 'ک', ''], mb_strtolower($value, 'UTF-8'));
            $value = preg_replace('/[\\x{064B}-\\x{065F}\\x{0670}\\x{200C}\\x{200F}\\x{202A}-\\x{202E}]/u', '', $value) ?? $value;
            return trim(preg_replace('/[\\p{P}\\p{Z}\\s]+/u', ' ', $value) ?? $value);
        };
        $needle = $normalize($text);
        $haystack = $normalize((string) $page->content_text);
        abort_unless($needle !== '' && str_contains($haystack, $needle), 422, 'متن انتخاب‌شده با محتوای همین صفحه مطابقت ندارد.');
        abort_unless($audio->configured(), 503);

        $cachePath = 'store/products/'.$product->id.'/reader-snippets/'.$document->source_sha256.'/'.hash('sha256', $text).'.wav';
        $disk = Storage::disk('private');
        if ($disk->exists($cachePath)) {
            $wav = $disk->get($cachePath);
        } else {
            try {
                $wav = $audio->synthesizeSnippet($text);
            } catch (RuntimeException $exception) {
                report($exception);
                abort(502, 'تولید صدای انتخاب‌شده موقتاً ممکن نشد.');
            }
            abort_unless(strlen($wav) <= 8 * 1024 * 1024, 502);
            $disk->put($cachePath, $wav);
        }

        return response($wav, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => (string) strlen($wav),
            'Content-Disposition' => 'inline; filename="farast-reader-selection.wav"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
