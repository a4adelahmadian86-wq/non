<?php

namespace App\\Http\\Controllers;

use App\\Models\\StoreProduct;
use App\\Services\\DocumentKnowledgeContextBuilder;
use Illuminate\\Http\\Request;

class InternalDocumentKnowledgeController extends Controller
{
    public function show(Request $request, StoreProduct $product, DocumentKnowledgeContextBuilder $knowledge)
    {
        $expected = (string) config('services.product_supervisor.token', '');
        $provided = (string) $request->bearerToken();
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 401);

        $limit = max(1000, min(30000, (int) $request->query('max_characters', 12000)));
        return response()->json($knowledge->forProduct((int) $product->id, $limit), 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
