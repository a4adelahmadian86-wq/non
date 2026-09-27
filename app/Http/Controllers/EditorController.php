<?php

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Models\AiInteraction;
use App\Models\EditorSession;
use App\Models\Order;
use App\Models\TypingDocument;
use App\Services\CapabilityService;
use App\Services\FreeQuotaService;
use App\Services\GeminiService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EditorController extends Controller
{
    public function pricing()
    {
        return view('pricing');
    }

    public function dashboard(CapabilityService $capabilities)
    {
        return view('dashboard', [
            'documents' => auth()->user()->documents()->latest()->get(),
            'capabilities' => $capabilities->forUser(auth()->user()),
        ]);
    }

    public function create(CapabilityService $capabilities)
    {
        return view('editor', ['capabilities' => $capabilities->forUser(auth()->user())]);
    }

    public function pending(Request $request)
    {
        $pending = $request->session()->get('pending_upload');
        if (! $pending || ! Storage::disk('private')->exists($pending['path'] ?? '')) {
            return response()->json(['ok' => true, 'pending' => null, 'authenticated' => auth()->check()]);
        }

        return response()->json([
            'ok' => true,
            'authenticated' => auth()->check(),
            'pending' => $pending,
        ]);
    }

    public function upload(Request $request, CapabilityService $capabilities)
    {
        $limit = auth()->check() ? (int) $capabilities->forUser(auth()->user())['max_file_mb'] : 50;
        $limit = max(1, min($limit, 2048));
        $request->validate([
            'source' => 'required|file|max:'.($limit * 1024).'|mimes:jpg,jpeg,png,webp,pdf,zip',
        ]);

        $file = $request->file('source');
        $folder = auth()->check() ? 'typing/'.auth()->id() : 'typing/pending';
        $path = $file->store($folder, 'private');
        $pending = [
            'path' => $path,
            'mime' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
        ];
        $request->session()->put('pending_upload', $pending);
        $request->session()->forget(['typing_preflight_quote', 'typing_preflight_accepted', 'typing_preflight_deposit_order']);

        return response()->json([
            'ok' => true,
            'path' => $path,
            'mime' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
            'requires_login' => ! auth()->check(),
        ]);
    }

    public function analyze(
        Request $request,
        GeminiService $ai,
        PricingService $pricing,
        CapabilityService $capabilities,
        FreeQuotaService $free,
    ) {
        $user = auth()->user();
        $caps = $capabilities->forUser($user);
        abort_unless($caps['active'] && $caps['can_ai'], 403, 'قابلیت پردازش هوش مصنوعی برای این حساب فعال نیست.');

        if (! $caps['unlimited']) {
            $used = AiInteraction::where('user_id', auth()->id())->whereDate('created_at', today())->count();
            abort_if($used >= (int) $caps['daily_ai_requests'], 429, 'سقف روزانه پردازش هوش مصنوعی شما تکمیل شده است.');
        }

        $data = $request->validate([
            'path' => 'required|string',
            'mime' => 'required|string',
            'source_name' => 'nullable|string|max:255',
        ]);

        $pending = $request->session()->get('pending_upload');
        if ($pending && hash_equals((string) ($pending['path'] ?? ''), (string) $data['path'])) {
            $path = $pending['path'];
            $newPath = 'typing/'.auth()->id().'/'.basename($path);
            if ($path !== $newPath && Storage::disk('private')->exists($path)) {
                Storage::disk('private')->move($path, $newPath);
                $path = $newPath;
            }
            $data['path'] = $path;
        }

        abort_unless(Str::startsWith($data['path'], 'typing/'.auth()->id().'/'), 403);
        abort_unless(Storage::disk('private')->exists($data['path']), 404);
        $bytes = Storage::disk('private')->get($data['path']);
        $maxBytes = (int) $caps['max_file_mb'] * 1024 * 1024;
        abort_unless($caps['unlimited'] || strlen($bytes) <= $maxBytes, 413, 'حجم فایل برای حساب شما بیشتر از سقف مجاز است.');
        $hash = hash('sha256', $bytes);

        $accepted = $request->session()->get('typing_preflight_accepted');
        abort_unless(
            is_array($accepted)
            && hash_equals((string) ($accepted['path'] ?? ''), (string) $data['path'])
            && hash_equals((string) ($accepted['hash'] ?? ''), $hash),
            428,
            'پیش از شروع تایپ، برآورد اولیه فایل را تأیید کنید.'
        );

        try {
            $result = $ai->transcribe($data['mime'], base64_encode($bytes), [
                'user_id' => auth()->id(),
                'source_hash' => $hash,
                'input_bytes' => strlen($bytes),
                'source_name' => $data['source_name'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('farast.editor.ocr_exception', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'ارتباط با هوش مصنوعی برقرار نشد. جزئیات خطا در لاگ ثبت شده است.'], 502);
        }

        if (($result['rejected'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => 'این ورودی شامل جدول، نمودار، شکل، فرمول یا محتوای گرافیکی است و برای تایپ دقیق متن عادی پذیرفته نمی‌شود.',
                'reason' => $result['reason'] ?? null,
                'interaction_id' => $result['_ai_interaction_id'] ?? null,
            ], 422);
        }

        $blocks = $result['blocks'] ?? [];
        $html = $this->blocksToHtml($blocks);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '</li>', '<br>'], ["\n", "\n", "\n"], $html))));
        $pages = max(1, (int) ($result['page_count'] ?? 1));
        $quote = $pricing->quote($text, $pages);
        $price = $quote['price_rials'];
        $freePages = ($caps['unlimited'] || ! $user->is_verified)
            ? 0
            : $free->availablePages($user, (int) $caps['weekly_free_pages']);
        $freePreview = min(1, $freePages, $pages);

        if ($caps['unlimited']) $price = 0;
        elseif ($freePreview > 0) $price = max(0, $price - (int) $quote['free_page_value_rials']);

        $doc = TypingDocument::create([
            'user_id' => auth()->id(),
            'title' => $data['source_name'] ? pathinfo($data['source_name'], PATHINFO_FILENAME) : 'سند جدید',
            'content' => $html,
            'source_path' => $data['path'],
            'source_hash' => $hash,
            'page_count' => $pages,
            'word_count' => $quote['word_count'],
            'language_mix' => [
                'fa' => preg_match('/[\x{0600}-\x{06FF}]/u', $text) > 0,
                'en' => preg_match('/[A-Za-z]/', $text) > 0,
            ],
            'status' => 'draft',
            'price_rials' => $price,
            'expires_at' => now()->addDays((int) env('FILES_TTL_DAYS', 14)),
        ]);

        if (! empty($result['_ai_interaction_id'])) {
            AiInteraction::whereKey($result['_ai_interaction_id'])->update(['document_id' => $doc->id]);
        }

        $depositOrderId = (int) $request->session()->get('typing_preflight_deposit_order', 0);
        if ($depositOrderId > 0) {
            $depositOrder = Order::whereKey($depositOrderId)
                ->where('user_id', auth()->id())
                ->where('status', 'deposit_paid')
                ->whereNull('document_id')
                ->first();
            if ($depositOrder && ($depositOrder->pricing_snapshot['source_hash'] ?? null) === $hash) {
                $depositOrder->update(['document_id' => $doc->id]);
            }
        }

        $request->session()->forget([
            'pending_upload',
            'typing_preflight_quote',
            'typing_preflight_accepted',
            'typing_preflight_deposit_order',
        ]);

        return response()->json([
            'ok' => true,
            'document_id' => $doc->id,
            'html' => $html,
            'text' => $text,
            'pages' => $pages,
            'price' => $price,
            'estimate' => $quote['price_rials'],
            'free_page_value' => $quote['free_page_value_rials'],
            'free_pages_available' => $freePages,
            'free_pages_preview' => $freePreview,
            'issues' => $this->flattenIssues($blocks),
            'breakdown' => $quote['breakdown'],
            'interaction_id' => $result['_ai_interaction_id'] ?? null,
            'request_id' => $result['_request_id'] ?? null,
        ]);
    }

    public function save(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');
        $data = $request->validate([
            'document_id' => 'required|integer',
            'content' => 'required|string|max:4000000',
        ]);
        $doc = TypingDocument::whereKey($data['document_id'])->where('user_id', auth()->id())->firstOrFail();
        $html = $this->sanitizeEditorHtml($data['content']);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
        $doc->update([
            'content' => $html,
            'word_count' => count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY)),
            'status' => $doc->status === 'paid' ? 'paid' : 'draft',
        ]);

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    public function feedback(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_feedback'), 403, 'ثبت بازخورد برای این حساب فعال نیست.');
        $data = $request->validate([
            'document_id' => 'required|integer',
            'ai_interaction_id' => 'nullable|integer',
            'type' => 'required|string|in:word_correction,wrong_output,rating,formatting,other',
            'rating' => 'nullable|integer|min:1|max:5',
            'category' => 'nullable|string|max:80',
            'original_text' => 'nullable|string|max:1000',
            'corrected_text' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:3000',
            'context' => 'nullable|array',
        ]);
        $doc = TypingDocument::whereKey($data['document_id'])->where('user_id', auth()->id())->firstOrFail();
        if (! empty($data['ai_interaction_id'])) {
            $data['ai_interaction_id'] = AiInteraction::whereKey($data['ai_interaction_id'])->where('user_id', auth()->id())->value('id');
        }
        $feedback = AiFeedback::create([
            'user_id' => auth()->id(),
            'document_id' => $doc->id,
            'ai_interaction_id' => $data['ai_interaction_id'] ?? null,
            'type' => $data['type'],
            'rating' => $data['rating'] ?? null,
            'category' => $data['category'] ?? null,
            'original_text' => $data['original_text'] ?? null,
            'corrected_text' => $data['corrected_text'] ?? null,
            'note' => $data['note'] ?? null,
            'context' => $data['context'] ?? null,
        ]);
        Log::info('farast.ai.feedback', ['feedback_id' => $feedback->id, 'user_id' => auth()->id(), 'document_id' => $doc->id, 'type' => $feedback->type]);

        return response()->json(['ok' => true, 'feedback_id' => $feedback->id]);
    }

    public function heartbeat(Request $request)
    {
        $token = $request->session()->get('editor_session_token');
        $session = EditorSession::where('token', $token)->where('user_id', auth()->id())->first();
        if ($session) $session->update(['last_seen_at' => now(), 'expires_at' => now()->addMinutes(20)]);
        return response()->json(['ok' => true]);
    }

    private function blocksToHtml(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $type = $block['type'] ?? 'paragraph';
            $text = (string) ($block['text'] ?? '');
            $uncertain = $block['uncertain'] ?? [];
            $safe = e($text);
            foreach ($uncertain as $u) {
                $word = (string) ($u['text'] ?? '');
                if ($word === '') continue;
                $suggestions = json_encode(array_values($u['suggestions'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $replacement = '<span class="ai-uncertain" data-original="'.e($word).'" data-suggestions="'.e($suggestions).'">'.e($word).'</span>';
                $safe = preg_replace('/'.preg_quote(e($word), '/').'/u', $replacement, $safe, 1) ?? $safe;
            }
            $safe = nl2br($safe, false);
            $level = min(3, max(1, (int) ($block['level'] ?? 2)));
            if ($type === 'heading') $html .= '<h'.$level.'>'.$safe.'</h'.$level.'>';
            elseif ($type === 'list_item') $html .= '<p class="ai-list-item">• '.$safe.'</p>';
            elseif ($type === 'quote') $html .= '<blockquote>'.$safe.'</blockquote>';
            elseif ($type === 'blank') $html .= '<p><br></p>';
            else $html .= '<p>'.$safe.'</p>';
        }
        return $html !== '' ? $html : '<p><br></p>';
    }

    private function flattenIssues(array $blocks): array
    {
        $issues = [];
        foreach ($blocks as $block) {
            foreach (($block['uncertain'] ?? []) as $u) {
                $issues[] = ['word' => $u['text'] ?? '', 'suggestions' => $u['suggestions'] ?? []];
            }
        }
        return $issues;
    }

    private function sanitizeEditorHtml(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><s><ol><ul><li><blockquote><h1><h2><h3><span><div>';
        $html = strip_tags($html, $allowed);
        if (! class_exists(\DOMDocument::class)) return $html;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $dom->getElementById('farast-root');
        if (! $root) return $html;
        $allowedAttrs = ['class', 'dir', 'data-original', 'data-suggestions'];
        $walker = function ($node) use (&$walker, $allowedAttrs) {
            if ($node instanceof \DOMElement) {
                foreach (iterator_to_array($node->attributes) as $attr) {
                    if (! in_array($attr->name, $allowedAttrs, true)) $node->removeAttribute($attr->name);
                }
            }
            foreach (iterator_to_array($node->childNodes) as $child) $walker($child);
        };
        $walker($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $dom->saveHTML($child);
        return $out ?: '<p><br></p>';
    }
}
