<?php

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Models\FarastDocument;
use App\Models\FarastProject;
use App\Models\AiInteraction;
use App\Models\EditorSession;
use App\Models\Order;
use App\Models\TypingDocument;
use App\Services\CapabilityService;
use App\Services\FreeQuotaService;
use App\Services\GeminiService;
use App\Services\PricingService;
use App\Services\ProjectInterviewService;
use App\Services\FeedbackPipelineService;
use App\Services\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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

    public function create(CapabilityService $capabilities, ProjectInterviewService $interview)
    {
        $project = null;
        $document = null;

        if (request()->filled('document')) {
            $document = TypingDocument::whereKey((int) request('document'))
                ->where('user_id', auth()->id())
                ->firstOrFail();
            if ($document->project_id) {
                $project = FarastProject::whereKey($document->project_id)
                    ->where('user_id', auth()->id())
                    ->first();
            }
        } elseif (request()->filled('project')) {
            $project = FarastProject::whereKey((int) request('project'))->where('user_id', auth()->id())->firstOrFail();
            $document = TypingDocument::where('user_id', auth()->id())
                ->where('project_id', $project->id)
                ->latest('id')
                ->first();
        }

        return view('editor', [
            'capabilities' => $capabilities->forUser(auth()->user()),
            'project' => $project,
            'document' => $document,
            'projectTemplates' => $interview::TEMPLATES,
        ]);
    }

    public function createDocument(Request $request, CapabilityService $capabilities, \App\Services\EditorDocumentService $documents)
    {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');
        $data = $request->validate([
            'title' => ['nullable','string','max:255'],
            'project_id' => ['nullable','integer'],
        ]);
        $project = !empty($data['project_id'])
            ? FarastProject::whereKey((int)$data['project_id'])->where('user_id',$request->user()->id)->firstOrFail()
            : null;
        $settings = $project?->context['template_settings'] ?? $documents->defaultPageSettings();
        $legacy = TypingDocument::create([
            'user_id' => $request->user()->id,
            'project_id' => $project?->id,
            'title' => $data['title'] ?? ($project?->name ?? 'سند جدید'),
            'content' => '<p><br></p>',
            'page_count' => max(1,(int)($project?->estimated_pages ?? 1)),
            'word_count' => 0,
            'language_mix' => ['fa' => true, 'en' => false],
            'status' => 'draft',
            'price_rials' => (int)($project?->estimated_price_rials ?? 0),
        ]);
        $saved = $documents->save($legacy, '<p><br></p>', $legacy->title, null, 'editor', $settings);
        if ($project) {
            FarastDocument::whereKey($legacy->farast_document_id)->where('user_id',$request->user()->id)->update(['project_id'=>$project->id]);
        }
        return response()->json([
            'ok' => true,
            'document_id' => $legacy->id,
            'project_id' => $project?->id,
            'revision' => $saved['revision'] ?? 1,
            'title' => $legacy->title,
            'content' => '<p><br></p>',
            'page_settings' => $saved['page_settings'] ?? $settings,
        ]);
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

    public function upload(Request $request, CapabilityService $capabilities, \App\Services\UploadSecurityService $security)
    {
        $limit = auth()->check() ? (int) $capabilities->forUser(auth()->user())['max_file_mb'] : 50;
        $limit = max(1, min($limit, 2048));
        $request->validate([
            'source' => 'required|file|max:'.($limit * 1024).'|mimes:jpg,jpeg,png,webp,pdf,zip',
            'source_type' => ['nullable','string','in:printed,handwritten,mixed'],
        ]);

        $file = $request->file('source');
        $folder = auth()->check() ? 'typing/'.auth()->id() : 'typing/pending';
        $path = $file->store($folder, 'private');
        try { $security->inspect($path, $file->getMimeType(), $limit * 1024 * 1024); \App\Models\FarastStorageObject::create(['user_id'=>auth()->id(),'disk'=>'private','path'=>$path,'mime'=>$file->getMimeType(),'size'=>Storage::disk('private')->size($path),'checksum'=>hash('sha256',Storage::disk('private')->get($path)),'status'=>'quarantined','classification'=>'upload','provenance'=>['pipeline'=>'upload_security','scan'=>'required']]); } catch (\Throwable $e) { Storage::disk('private')->delete($path); throw $e; }
        $pending = [
            'path' => $path,
            'mime' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
            'source_type' => $request->input('source_type'),
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

    public function uploadAsset(Request $request, CapabilityService $capabilities, \App\Services\UploadSecurityService $security)
    {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');

        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'asset' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif'],
        ]);

        $document = TypingDocument::whereKey((int) $data['document_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $file = $data['asset'];
        $resourceId = 'asset-'.Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'editor-assets/'.$request->user()->id.'/'.$document->id,
            $resourceId.'.'.$extension,
            'private'
        );
        try { $security->inspect($path, $file->getMimeType(), 20 * 1024 * 1024); \App\Models\FarastStorageObject::create(['user_id'=>$request->user()->id,'document_id'=>$document->farast_document_id,'disk'=>'private','path'=>$path,'mime'=>$file->getMimeType(),'size'=>Storage::disk('private')->size($path),'checksum'=>hash('sha256',Storage::disk('private')->get($path)),'status'=>'quarantined','classification'=>'editor_asset','provenance'=>['pipeline'=>'upload_security','scan'=>'required']]); } catch (\Throwable $e) { Storage::disk('private')->delete($path); throw $e; }

        return response()->json([
            'ok' => true,
            'resource' => [
                'id' => $resourceId,
                'type' => 'image',
                'mime' => $file->getMimeType(),
                'name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'url' => '/editor/documents/'.$document->id.'/assets/'.$resourceId,
                'width' => 0,
                'height' => 0,
            ],
        ]);
    }

    public function asset(Request $request, int $document, string $resource)
    {
        $legacy = TypingDocument::whereKey($document)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        abort_unless($legacy->farast_document_id, 404);

        $farast = FarastDocument::whereKey($legacy->farast_document_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $resources = is_array($farast->content_json['resources'] ?? null) ? $farast->content_json['resources'] : [];
        $item = collect($resources)->first(fn ($row) => is_array($row) && ($row['id'] ?? null) === $resource);
        abort_unless(is_array($item) && !empty($item['storage_path']), 404);

        $path = (string) $item['storage_path'];
        abort_unless(Storage::disk('private')->exists($path), 404);

        $stream = Storage::disk('private')->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => (string) ($item['mime'] ?? 'application/octet-stream'),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function analyze(
        Request $request,
        GeminiService $ai,
        PricingService $pricing,
        CapabilityService $capabilities,
        FreeQuotaService $free,
        EntitlementService $entitlements,
        \App\Services\CommerceAuthorizationService $commerce,
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
            'project_id' => 'nullable|integer',
        ]);

        $project = !empty($data['project_id'])
            ? FarastProject::whereKey((int) $data['project_id'])->where('user_id', auth()->id())->firstOrFail()
            : null;
        abort_unless($entitlements->allows($user, 'ocr', $project), 403, 'قابلیت OCR برای این پروژه فعال نیست.');
        if ($project && in_array((string) ($project->context['source_type'] ?? ''), ['handwritten', 'mixed'], true)) {
            abort_unless($entitlements->allows($user, 'handwriting.ocr', $project), 403, 'تشخیص دست‌نویس برای این پروژه فعال نیست.');
        }

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
        $data['mime'] = Storage::disk('private')->mimeType($data['path']) ?: $data['mime'];
        abort_unless(in_array($data['mime'], ['image/jpeg','image/png','image/webp','application/pdf','application/zip'], true), 415, 'نوع فایل برای پردازش پشتیبانی نمی‌شود.');
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

        $ocrReservation = $commerce->reserve(
            $user,
            'ocr',
            max(1, (float)($accepted['pages'] ?? 1)),
            ['project_id'=>$project?->id],
            [
                'policy_code'=>'ocr',
                'unit'=>'page',
                'allow_payg'=>true,
                'idempotency_key'=>$request->header('Idempotency-Key') ?: ('ocr-'.$user->id.'-'.$hash),
            ]
        );

        $ocrJobId = null;
        if (\Illuminate\Schema\Schema::hasTable('farast_ocr_jobs')) {
            $ocrJobId = DB::table('farast_ocr_jobs')->insertGetId([
                'user_id' => auth()->id(), 'provider' => 'gemini', 'status' => 'processing',
                'source_path' => $data['path'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $result = $ai->transcribe($data['mime'], base64_encode($bytes), [
                'user_id' => auth()->id(),
                'source_hash' => $hash,
                'input_bytes' => strlen($bytes),
                'source_name' => $data['source_name'] ?? null,
            ]);
        } catch (\Throwable $e) {
            try { $commerce->release($ocrReservation); } catch (\Throwable) {}
            if ($ocrJobId) DB::table('farast_ocr_jobs')->where('id', $ocrJobId)->update(['status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => now()]);
            Log::warning('farast.editor.ocr_exception', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'ارتباط با هوش مصنوعی برقرار نشد. جزئیات خطا در لاگ ثبت شده است.'], 502);
        }

        $commerce->commit($ocrReservation, [
            'cost_metadata'=>['provider'=>$result['engine'] ?? 'gemini','input_bytes'=>strlen($bytes)],
            'metadata'=>['page_count'=>(int)($result['page_count'] ?? 1),'rejected'=>(bool)($result['rejected'] ?? false),'interaction_id'=>$result['_ai_interaction_id'] ?? null],
        ]);

        if (($result['rejected'] ?? false)) {
            if ($ocrJobId) DB::table('farast_ocr_jobs')->where('id', $ocrJobId)->update(['status' => 'failed', 'error' => (string) ($result['reason'] ?? 'ورودی رد شد'), 'updated_at' => now()]);
            return response()->json([
                'ok' => false,
                'message' => 'این ورودی شامل جدول، نمودار، شکل، فرمول یا محتوای گرافیکی است و برای تایپ دقیق متن عادی پذیرفته نمی‌شود.',
                'reason' => $result['reason'] ?? null,
                'interaction_id' => $result['_ai_interaction_id'] ?? null,
            ], 422);
        }

        if ($ocrJobId) DB::table('farast_ocr_jobs')->where('id', $ocrJobId)->update(['status' => 'completed', 'result_text' => (string) ($result['text'] ?? ''), 'updated_at' => now()]);

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

    public function feedback(Request $request, CapabilityService $capabilities, FeedbackPipelineService $pipeline)
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

        $evidence = $pipeline->record($feedback, 'ai.editor', '1.0.0', [
            'operation' => $feedback->interaction?->operation,
            'document_id' => $doc->id,
            'context' => $feedback->context,
        ], [
            'rating' => $feedback->rating,
            'corrected' => filled($feedback->corrected_text),
        ]);
        $pipeline->queueReview($evidence);

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
