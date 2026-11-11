<?php

namespace App\Http\Controllers;

use App\Models\TypingDocument;
use App\Services\CapabilityService;
use App\Services\EditorDocumentService;
use Illuminate\Http\Request;

class EditorSaveController extends Controller
{
    public function __invoke(
        Request $request,
        CapabilityService $capabilities,
        EditorDocumentService $documents,
    ) {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');

        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'content' => ['required', 'string', 'max:4000000'],
            'title' => ['nullable', 'string', 'max:255'],
            'revision' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'string', 'in:editor,manual,autosave,ai,ai-agent-context,recovery'],
            'page_settings' => ['nullable', 'array'],
            'document_model' => ['nullable', 'array'],
        ]);

        $document = TypingDocument::whereKey($data['document_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $documents->save(
            $document,
            $data['content'],
            $data['title'] ?? null,
            array_key_exists('revision', $data) ? (int) $data['revision'] : null,
            $data['source'] ?? 'editor',
            $data['page_settings'] ?? null,
            $data['document_model'] ?? null,
        );

        if (($result['conflict'] ?? false) === true) {
            return response()->json([
                'ok' => false,
                'conflict' => true,
                'message' => 'این سند در پنجره یا دستگاه دیگری تغییر کرده است. ابتدا نسخه جدید را بررسی کنید.',
                'revision' => $result['revision'],
                'document_id' => $result['document_id'],
            ], 409);
        }

        return response()->json($result);
    }
}
