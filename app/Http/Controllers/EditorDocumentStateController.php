<?php

namespace App\Http\Controllers;

use App\Models\TypingDocument;
use App\Services\CapabilityService;
use App\Services\EditorDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EditorDocumentStateController extends Controller
{
    public function show(
        Request $request,
        int $document,
        EditorDocumentService $documents,
        CapabilityService $capabilities,
    ) {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');

        $legacy = TypingDocument::whereKey($document)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $state = $documents->loadForLegacy($legacy);

        return response()->json([
            'ok' => true,
            'document_id' => $legacy->id,
            'title' => $legacy->title,
            'content' => $state['html'],
            'revision' => $state['revision'],
            'page_settings' => $state['page_settings'],
        ]);
    }

    public function versions(Request $request, int $document, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'دسترسی به تاریخچه سند فعال نیست.');

        $legacy = TypingDocument::whereKey($document)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $legacy->farast_document_id) {
            return response()->json(['ok' => true, 'versions' => []]);
        }

        $versions = DB::table('farast_document_versions')
            ->where('document_id', $legacy->farast_document_id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'label', 'created_at']);

        return response()->json(['ok' => true, 'versions' => $versions]);
    }

    public function restore(
        Request $request,
        int $document,
        int $version,
        EditorDocumentService $documents,
        CapabilityService $capabilities,
    ) {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'بازیابی نسخه برای این حساب فعال نیست.');

        $legacy = TypingDocument::whereKey($document)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        abort_unless($legacy->farast_document_id, 404);

        $versionRow = DB::table('farast_document_versions')
            ->where('id', $version)
            ->where('document_id', $legacy->farast_document_id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($versionRow, 404);

        $result = $documents->save(
            $legacy,
            (string) ($versionRow->content ?? '<p><br></p>'),
            $legacy->title,
            null,
            'recovery',
        );

        return response()->json([
            'ok' => true,
            'message' => 'نسخه انتخاب‌شده بازیابی شد.',
            'revision' => $result['revision'] ?? null,
        ]);
    }
}
