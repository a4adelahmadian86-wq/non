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
    ): array {
        $model = $this->normalizeHtml($html);
        $now = now();

        return $this->db->transaction(function () use ($legacy, $html, $model, $title, $expectedRevision, $source, $pageSettings, $now) {
            $document = $legacy->farast_document_id
                ? FarastDocument::whereKey($legacy->farast_document_id)->where('user_id', $legacy->user_id)->lockForUpdate()->first()
                : null;

            if (! $document) {
                $document = FarastDocument::create([
                    'user_id' => $legacy->user_id,
                    'title' => $title ?: $legacy->title ?: 'سند جدید',
                    'content' => $html,
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
                'content' => $html,
                'content_json' => $model,
                'page_settings' => $pageSettings ?: ($document->page_settings ?: $this->defaultPageSettings()),
                'revision' => $nextRevision,
                'last_saved_at' => $now,
            ])->save();

            if ($nextRevision === 1 || $source !== 'autosave') {
                $document->versions()->create([
                    'user_id' => $legacy->user_id,
                    'content' => $html,
                    'label' => $source === 'ai' ? 'تغییر هوش مصنوعی' : ($source === 'manual' ? 'ذخیره دستی' : 'نسخه '.$nextRevision),
                ]);
            }

            $plain = $model['plain_text'] ?? '';
            $legacy->update([
                'content' => $html,
                'title' => $title ?: $legacy->title,
                'word_count' => $this->wordCount($plain),
            ]);

            return [
                'ok' => true,
                'conflict' => false,
                'document_id' => $document->id,
                'revision' => $nextRevision,
                'saved_at' => $now->toIso8601String(),
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
                'page_settings' => $this->defaultPageSettings(),
            ];
        }

        $document = FarastDocument::whereKey($legacy->farast_document_id)
            ->where('user_id', $legacy->user_id)
            ->first();

        return [
            'html' => $document?->content ?: ($legacy->content ?: '<p><br></p>'),
            'revision' => (int) ($document?->revision ?? 0),
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
