<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use App\Services\ProductContentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminProductContentController extends Controller
{
    public function generate(Request $request, StoreProduct $product, ProductContentGenerationService $generator)
    {
        $options = $request->validate([
            'tone' => 'nullable|in:professional,friendly,technical',
            'focus_keyword' => 'nullable|string|max:120',
            'instructions' => 'nullable|string|max:500',
        ]);

        try {
            $result = $generator->generate($request->user(), $product, $options);

            return response()->json([
                'ok' => true,
                ...$result,
            ])->header('Cache-Control', 'no-store, private');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $code = $exception instanceof RuntimeException && preg_match('/^ai_[a-z0-9_]+$/', $exception->getMessage())
                ? $exception->getMessage()
                : 'ai_product_content_failed';

            return response()->json([
                'ok' => false,
                'message' => match ($code) {
                    'ai_product_content_missing_sources' => 'مدل نتوانست منابع معتبر گزارش دانش را به پیشنهادها متصل کند؛ دوباره تلاش کن.',
                    'ai_product_content_invalid' => 'پاسخ مدل ساختار معتبر نداشت؛ هیچ فیلدی تغییر نکرد.',
                    default => 'تولید محتوا انجام نشد. وضعیت سرویس هوش مصنوعی و سهمیه را بررسی کن.',
                },
                'error_code' => $code,
            ], $code === 'ai_product_content_failed' ? 503 : 422)->header('Cache-Control', 'no-store, private');
        }
    }
}
