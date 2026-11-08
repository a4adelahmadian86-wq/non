<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use App\Services\DocumentKnowledgeContextBuilder;
use Illuminate\Http\Request;

class InternalDocumentKnowledgeController extends Controller
{
    public function show(Request $request, StoreProduct $product, DocumentKnowledgeContextBuilder $knowledge)
    {
        $expected = (string) config('services.product_supervisor.token', '');
        $provided = (string) $request->bearerToken();
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 401);

        $limit = max(1000, min(30000, (int) $request->query('max_characters', 12000)));
        $fileId = filter_var($request->query('file_id'), FILTER_VALIDATE_INT);
        $query = trim(mb_substr((string) $request->query('q', ''), 0, 160));
        $context = $fileId
            ? $knowledge->forFile((int) $fileId, $limit)
            : ($query !== '' ? $knowledge->searchProduct((int) $product->id, $query, $limit) : $knowledge->forProduct((int) $product->id, $limit));
        abort_if(isset($context['product_id']) && (int) $context['product_id'] !== (int) $product->id, 404);
        return response()->json($context, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
