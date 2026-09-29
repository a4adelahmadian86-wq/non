<?php

namespace App\Services;

use App\Models\FarastDocument;
use App\Models\TypingDocument;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EditorDocumentService
{
    public function __construct(private DatabaseManager $db) {}

    public function save(
        TypingDocument $legacy,
        string $html,
        ?string $title = null,
        ?int $expectedRevision = null,
        string $source = 'editor',
        ?array $pageSettings = null,
        ?array $documentModel = null,
    ): array {
        $safeHtml = $this->sanitizeHtml($html);
        $model = $documentModel ? $this->sanitizeDocumentModel($documentModel) : $this->normalizeHtml($safeHtml);
        $now = now();

        return $this->db->transaction(function () use ($legacy, $safeHtml, $model, $title, $expectedRevision, $source, $pageSettings, $now) {
            $document = $legacy->farast_document_id
                ? FarastDocument::whereKey($legacy->farast_document_id)->where('user_id', $legacy->user_id)->lockForUpdate()->first()
                : null;

            if (! $document) {
                $document = FarastDocument::create([
                    'user_id' => $legacy->user_id,
                    'title' => $title ?: $legacy->title ?: 'سند جدید',
                    'content' => $safeHtml,
                    'content_json' => $model,
                    'document_format' => 'farast-v1',
                    'page_settings' => $pageSettings ?: $this->defaultPageSettings(),
                    'revision' => 0,
                    'last_saved_at' => $now,
                    'status' => $legacy->status === 'trashed' ? 'trashed' : 'active',
                ]);
                $legacy->forceFill(['farast_document_id' => $document->id])->saveQuietly();
            }

            if ($expectedRevision !== null && $expectedRevision !== (int) $document->revision) {
                return [
                    'ok' => false,
                    'conflict' => true,
                    'revision' => (int) $document->revision,
                    'document_id' => $document->id,
                    'content' => $document->content,
                ];
            }

            $nextRevision = (int) $document->revision + 1;
            $document->fill([
                'title' => $title ?: $document->title,
                'content' => $safeHtml,
                'content_json' => $model,
                'page_settings' => $pageSettings ?: ($document->page_settings ?: ($model['settings'] ?? $this->defaultPageSettings())),
                'revision' => $nextRevision,
                'last_saved_at' => $now,
            ])->save();

            if ($nextRevision === 1 || $source !== 'autosave') {
                $document->versions()->create([
                    'user_id' => $legacy->user_id,
                    'content' => $safeHtml,
                    'label' => $source === 'ai' ? 'تغییر هوش مصنوعی' : ($source === 'manual' ? 'ذخیره دستی' : 'نسخه '.$nextRevision),
                ]);
            }

            $plain = $model['plain_text'] ?? '';
            $legacy->update([
                'content' => $safeHtml,
                'title' => $title ?: $legacy->title,
                'word_count' => $this->wordCount($plain),
            ]);

            return [
                'ok' => true,
                'conflict' => false,
                'document_id' => $document->id,
                'revision' => $nextRevision,
                'saved_at' => $now->toIso8601String(),
                'page_settings' => $document->page_settings,
                'word_count' => $this->wordCount($plain),
            ];
        }, 3);
    }

    public function loadForLegacy(TypingDocument $legacy): array
    {
        if (! $legacy->farast_document_id) {
            return [
                'html' => $legacy->content ?: '<p><br></p>',
                'revision' => 0,
                'document_model' => $this->normalizeHtml($legacy->content ?: '<p><br></p>'),
                'page_settings' => $this->defaultPageSettings(),
            ];
        }

        $document = FarastDocument::whereKey($legacy->farast_document_id)
            ->where('user_id', $legacy->user_id)
            ->first();

        return [
            'html' => $document?->content ?: ($legacy->content ?: '<p><br></p>'),
            'revision' => (int) ($document?->revision ?? 0),
            'document_model' => $document?->content_json ?: $this->normalizeHtml($document?->content ?: $legacy->content ?: '<p><br></p>'),
            'page_settings' => $document?->page_settings ?: $this->defaultPageSettings(),
        ];
    }

    public function defaultPageSettings(): array
    {
        return [
            'paper' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 25,
            'margin_right' => 25,
            'margin_bottom' => 25,
            'margin_left' => 25,
            'header_distance' => 12,
            'footer_distance' => 12,
            'direction' => 'rtl',
            'font_family' => 'B Nazanin',
            'font_size' => 16,
        ];
    }

    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '<p><br></p>';
        $dom = new \\DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $dom->getElementById('farast-root');
        if (!$root) return '<p><br></p>';
        $allowedTags = ['div','p','br','span','strong','b','em','i','u','s','strike','sub','sup','h1','h2','h3','h4','h5','h6','blockquote','ul','ol','li','table','thead','tbody','tfoot','tr','th','td','img','a','hr'];
        $allowedAttrs = ['id','class','style','dir','title','alt','width','height','colspan','rowspan','href','target','rel','src'];
        $walk = function(\\DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs): void {
            for ($child = $node->firstChild; $child; ) {
                $next = $child->nextSibling;
                if ($child instanceof \\DOMElement) {
                    $tag = strtolower($child->tagName);
                    if (!in_array($tag, $allowedTags, true)) { $node->removeChild($child); $child = $next; continue; }
                    foreach (iterator_to_array($child->attributes) as $attr) {
                        $name = strtolower($attr->name);
                        $value = trim($attr->value);
                        if (!in_array($name, $allowedAttrs, true) || str_starts_with($name, 'on')) { $child->removeAttribute($attr->name); continue; }
                        if (in_array($name, ['href','src'], true) && preg_match('/^\\s*(?:javascript:|vbscript:|data:text\\/html)/iu', $value)) { $child->removeAttribute($attr->name); continue; }
                        if ($name === 'style' && preg_match('/(?:expression\\s*\\(|url\\s*\\(\\s*["\\']?\\s*(?:javascript:|data:text\\/html))/iu', $value)) { $child->removeAttribute($attr->name); }
                    }
                    if ($tag === 'a' && $child->hasAttribute('target')) $child->setAttribute('rel', 'noopener noreferrer');
                    $walk($child);
                }
                $child = $next;
            }
        };
        $walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $dom->saveHTML($child);
        return $out !== '' ? $out : '<p><br></p>';
    }

    private function sanitizeDocumentModel(array $model): array
    {
        $model['schema'] = 2;
        $model['type'] = 'document';
        $model['direction'] = (($model['direction'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
        $model['settings'] = is_array($model['settings'] ?? null) ? $model['settings'] : $this->defaultPageSettings();
        foreach (($model['sections'] ?? []) as $si => $section) {
            $blocks = [];
            foreach (($section['blocks'] ?? []) as $block) {
                if (!is_array($block)) continue;
                $block['id'] = is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : (string) Str::uuid();
                $block['html'] = $this->sanitizeHtml((string) ($block['html'] ?? '<p><br></p>'));
                $block['text'] = trim(preg_replace('/\\s+/u', ' ', strip_tags($block['html'])) ?? '');
                $blocks[] = $block;
            }
            $model['sections'][$si]['blocks'] = $blocks;
        }
        $model['sections'] = array_values(array_filter($model['sections'] ?? [], 'is_array'));
        if (!$model['sections']) $model['sections'] = [['id' => 'section-1', 'blocks' => []]];
        $model['comments'] = is_array($model['comments'] ?? null) ? $model['comments'] : [];
        $model['review'] = is_array($model['review'] ?? null) ? $model['review'] : [];
        return $model;
    }

    public function normalizeHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            $html = '<p><br></p>';
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '</li>', '<br>'], ["\n", "\n", "\n"], $html))) ?? '');
        $blocks = [];

        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            @$dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $root = $dom->getElementById('farast-root');

            if ($root) {
                foreach ($root->childNodes as $node) {
                    if (! $node instanceof \DOMElement) continue;
                    $tag = strtolower($node->tagName);
                    $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
                    $blocks[] = [
                        'id' => (string) Str::uuid(),
                        'type' => in_array($tag, ['h1','h2','h3','h4','h5','h6'], true) ? 'heading' : ($tag === 'blockquote' ? 'quote' : (in_array($tag, ['ul','ol'], true) ? 'list' : 'paragraph')),
                        'level' => in_array($tag, ['h1','h2','h3','h4','h5','h6'], true) ? (int) substr($tag, 1) : null,
                        'text' => $text,
                        'html' => $dom->saveHTML($node),
                    ];
                }
            }
        }

        return [
            'schema' => 1,
            'direction' => 'rtl',
            'blocks' => $blocks,
            'plain_text' => $plain,
            'stats' => [
                'words' => $this->wordCount($plain),
                'characters' => mb_strlen($plain),
            ],
        ];
    }

    private function wordCount(string $text): int
    {
        if ($text === '') return 0;
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
