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
        $normalizedSettings = $this->normalizePageSettings($pageSettings ?: (($documentModel['settings'] ?? null) ?: $this->defaultPageSettings()));
        $model = $documentModel ? $this->sanitizeDocumentModel($documentModel) : $this->normalizeHtml($safeHtml);
        $safeHtml = $this->documentModelToHtml($model);
        $model['settings'] = $normalizedSettings;
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
                    'page_settings' => $normalizedSettings,
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
                'page_settings' => $normalizedSettings ?: ($document->page_settings ?: $this->defaultPageSettings()),
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

    public function normalizePageSettings(?array $settings): array
    {
        $defaults = $this->defaultPageSettings();
        $paper = strtoupper((string) ($settings['paper'] ?? $defaults['paper']));
        $orientation = (string) ($settings['orientation'] ?? $defaults['orientation']);
        $direction = (string) ($settings['direction'] ?? $defaults['direction']);
        return [
            'paper' => in_array($paper, ['A4', 'A5', 'LETTER'], true) ? ($paper === 'LETTER' ? 'Letter' : $paper) : 'A4',
            'orientation' => in_array($orientation, ['portrait', 'landscape'], true) ? $orientation : 'portrait',
            'margin_top' => max(5, min(60, (float) ($settings['margin_top'] ?? $defaults['margin_top']))),
            'margin_right' => max(5, min(60, (float) ($settings['margin_right'] ?? $defaults['margin_right']))),
            'margin_bottom' => max(5, min(60, (float) ($settings['margin_bottom'] ?? $defaults['margin_bottom']))),
            'margin_left' => max(5, min(60, (float) ($settings['margin_left'] ?? $defaults['margin_left']))),
            'header_distance' => max(0, min(40, (float) ($settings['header_distance'] ?? $defaults['header_distance']))),
            'footer_distance' => max(0, min(40, (float) ($settings['footer_distance'] ?? $defaults['footer_distance']))),
            'direction' => $direction === 'ltr' ? 'ltr' : 'rtl',
            'font_family' => mb_substr(trim((string) ($settings['font_family'] ?? $defaults['font_family'])), 0, 120) ?: $defaults['font_family'],
            'font_size' => max(8, min(72, (int) ($settings['font_size'] ?? $defaults['font_size']))),
            'header' => mb_substr((string) ($settings['header'] ?? ''), 0, 1000),
            'footer' => mb_substr((string) ($settings['footer'] ?? ''), 0, 1000),
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

    private const CURRENT_SCHEMA = 3;

    private function sanitizeDocumentModel(array $model): array
    {
        $schema = (int) ($model['schema'] ?? $model['schemaVersion'] ?? 1);

        if ($schema < self::CURRENT_SCHEMA) {
            $model = $this->migrateDocumentModel($model, $schema);
        }

        $model['schema'] = self::CURRENT_SCHEMA;
        $model['type'] = 'document';
        $model['id'] = is_string($model['id'] ?? null) && $model['id'] !== '' ? $model['id'] : (string) Str::uuid();
        $model['title'] = mb_substr((string) ($model['title'] ?? ''), 0, 255);
        $model['language'] = mb_substr((string) ($model['language'] ?? 'fa'), 0, 32) ?: 'fa';
        $model['direction'] = (($model['direction'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
        $model['metadata'] = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];
        $model['settings'] = is_array($model['settings'] ?? null) ? $this->normalizePageSettings($model['settings']) : $this->defaultPageSettings();

        $sections = [];
        foreach (($model['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            $section['id'] = is_string($section['id'] ?? null) && $section['id'] !== '' ? $section['id'] : (string) Str::uuid();
            $section['settings'] = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            $section['header'] = is_array($section['header'] ?? null) ? $section['header'] : ['blocks' => []];
            $section['footer'] = is_array($section['footer'] ?? null) ? $section['footer'] : ['blocks' => []];
            $blocks = [];
            foreach (($section['blocks'] ?? []) as $block) {
                $normalized = $this->sanitizeBlock(is_array($block) ? $block : []);
                if ($normalized) $blocks[] = $normalized;
            }
            $section['blocks'] = $blocks;
            $section['footnotes'] = is_array($section['footnotes'] ?? null) ? array_values($section['footnotes']) : [];
            $sections[] = $section;
        }

        if (!$sections) {
            $sections = [[
                'id' => 'section-1',
                'settings' => [],
                'header' => ['blocks' => []],
                'footer' => ['blocks' => []],
                'blocks' => [[
                    'id' => (string) Str::uuid(),
                    'type' => 'paragraph',
                    'runs' => [['text' => '']],
                ]],
                'footnotes' => [],
            ]];
        }

        $model['sections'] = array_values($sections);
        $model['comments'] = $this->sanitizeComments($model['comments'] ?? []);
        $model['reviewChanges'] = $this->sanitizeReviewChanges($model['reviewChanges'] ?? ($model['review'] ?? []));
        $model['review'] = $model['reviewChanges']; // compatibility alias for older clients
        $model['bookmarks'] = $this->sanitizeBookmarks($model['bookmarks'] ?? []);
        $model['resources'] = is_array($model['resources'] ?? null) ? array_values($model['resources']) : [];
        $model['fields'] = is_array($model['fields'] ?? null) ? array_values($model['fields']) : [];
        $model['plain_text'] = $this->plainTextFromModel($model);
        $model['stats'] = [
            'words' => $this->wordCount($model['plain_text']),
            'characters' => mb_strlen($model['plain_text']),
        ];

        return $model;
    }

    private function sanitizeBlock(array $block): ?array
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $allowed = ['paragraph','heading','list','list_item','quote','table','image','page_break','divider'];
        if (!in_array($type, $allowed, true)) return null;

        $normalized = [
            'id' => is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : (string) Str::uuid(),
            'type' => $type,
        ];

        if ($type === 'heading') {
            $normalized['level'] = max(1, min(6, (int) ($block['level'] ?? 2)));
        }

        if ($type === 'page_break' || $type === 'divider') {
            return $normalized;
        }

        if ($type === 'table') {
            $rows = [];
            foreach (($block['rows'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $cells = [];
                foreach (($row['cells'] ?? []) as $cell) {
                    if (!is_array($cell)) continue;
                    $cells[] = [
                        'id' => is_string($cell['id'] ?? null) && $cell['id'] !== '' ? $cell['id'] : (string) Str::uuid(),
                        'rowSpan' => max(1, (int) ($cell['rowSpan'] ?? 1)),
                        'colSpan' => max(1, (int) ($cell['colSpan'] ?? 1)),
                        'runs' => $this->sanitizeRuns($cell['runs'] ?? []),
                    ];
                }
                $rows[] = ['cells' => $cells];
            }
            $normalized['rows'] = $rows;
            return $normalized;
        }

        if ($type === 'image') {
            $normalized['resourceId'] = isset($block['resourceId']) ? mb_substr((string) $block['resourceId'], 0, 120) : null;
            $normalized['width'] = max(1, (int) ($block['width'] ?? 0));
            $normalized['height'] = max(1, (int) ($block['height'] ?? 0));
            $normalized['alt'] = mb_substr((string) ($block['alt'] ?? ''), 0, 1000);
            $normalized['alignment'] = in_array(($block['alignment'] ?? 'center'), ['left','center','right'], true) ? $block['alignment'] : 'center';
            $normalized['wrapping'] = in_array(($block['wrapping'] ?? 'inline'), ['inline','square','tight','top-bottom','behind','in-front'], true) ? $block['wrapping'] : 'inline';
            $normalized['metadata'] = is_array($block['metadata'] ?? null) ? $block['metadata'] : [];
            return $normalized;
        }

        $normalized['runs'] = $this->sanitizeRuns($block['runs'] ?? []);
        if (!$normalized['runs']) {
            $legacyHtml = (string) ($block['html'] ?? '');
            if ($legacyHtml !== '') {
                $normalized['runs'] = $this->runsFromHtml($legacyHtml);
            }
        }
        if (!$normalized['runs']) $normalized['runs'] = [['text' => '']];

        return $normalized;
    }

    private function sanitizeRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $text = $this->normalizePersian((string) ($run['text'] ?? ''));
            if ($text === '' && count($runs) > 1) continue;
            $item = ['text' => $text];
            foreach (['bold','italic','underline','strike'] as $flag) {
                if (array_key_exists($flag, $run)) $item[$flag] = (bool) $run[$flag];
            }
            foreach (['fontFamily','fontSize','color','highlight','direction','language','href'] as $key) {
                if (isset($run[$key]) && is_scalar($run[$key])) {
                    $item[$key] = mb_substr((string) $run[$key], 0, 160);
                }
            }
            $out[] = $item;
        }

        $merged = [];
        foreach ($out as $run) {
            $last = $merged[count($merged) - 1] ?? null;
            if ($last && array_diff_assoc($run, $last) === [] && array_diff_assoc($last, $run) === []) {
                $merged[count($merged) - 1]['text'] .= $run['text'];
            } else {
                $merged[] = $run;
            }
        }
        return $merged;
    }

    private function sanitizeComments(mixed $comments): array
    {
        if (!is_array($comments)) return [];
        return array_values(array_map(function ($comment) {
            if (!is_array($comment)) return null;
            return [
                'id' => (string) ($comment['id'] ?? Str::uuid()),
                'authorId' => $comment['authorId'] ?? $comment['author_id'] ?? null,
                'author' => mb_substr((string) ($comment['author'] ?? ''), 0, 160),
                'timestamp' => (string) ($comment['timestamp'] ?? $comment['created_at'] ?? now()->toIso8601String()),
                'text' => mb_substr((string) ($comment['text'] ?? ''), 0, 5000),
                'anchor' => is_array($comment['anchor'] ?? null) ? $comment['anchor'] : [],
                'resolved' => (bool) ($comment['resolved'] ?? false),
            ];
        }, $comments), fn ($v) => $v !== null);
    }

    private function sanitizeReviewChanges(mixed $changes): array
    {
        if (!is_array($changes)) return [];
        return array_values(array_map(function ($change) {
            if (!is_array($change)) return null;
            $type = (string) ($change['type'] ?? 'formatting');
            if (!in_array($type, ['insertion','deletion','formatting'], true)) return null;
            $status = (string) ($change['status'] ?? 'pending');
            if (!in_array($status, ['pending','accepted','rejected'], true)) $status = 'pending';
            return [
                'id' => (string) ($change['id'] ?? Str::uuid()),
                'authorId' => $change['authorId'] ?? $change['author_id'] ?? null,
                'timestamp' => (string) ($change['timestamp'] ?? $change['created_at'] ?? now()->toIso8601String()),
                'type' => $type,
                'blockId' => (string) ($change['blockId'] ?? $change['block_id'] ?? ''),
                'start' => max(0, (int) ($change['start'] ?? 0)),
                'end' => max(0, (int) ($change['end'] ?? 0)),
                'before' => is_array($change['before'] ?? null) ? $change['before'] : [],
                'after' => is_array($change['after'] ?? null) ? $change['after'] : [],
                'status' => $status,
            ];
        }, $changes), fn ($v) => $v !== null);
    }

    private function sanitizeBookmarks(mixed $bookmarks): array
    {
        if (!is_array($bookmarks)) return [];
        return array_values(array_map(function ($bookmark) {
            if (!is_array($bookmark)) return null;
            return [
                'id' => (string) ($bookmark['id'] ?? Str::uuid()),
                'name' => mb_substr((string) ($bookmark['name'] ?? 'نشانک'), 0, 160),
                'blockId' => (string) ($bookmark['blockId'] ?? $bookmark['block_id'] ?? ''),
                'offset' => max(0, (int) ($bookmark['offset'] ?? 0)),
            ];
        }, $bookmarks), fn ($v) => $v !== null);
    }

    private function migrateDocumentModel(array $model, int $schema): array
    {
        $legacyBlocks = [];
        if (!empty($model['sections']) && is_array($model['sections'])) {
            foreach ($model['sections'] as $section) {
                foreach (($section['blocks'] ?? []) as $block) $legacyBlocks[] = $block;
            }
        } elseif (!empty($model['blocks']) && is_array($model['blocks'])) {
            $legacyBlocks = $model['blocks'];
        }

        $blocks = [];
        foreach ($legacyBlocks as $block) {
            if (!is_array($block)) continue;
            $html = (string) ($block['html'] ?? '');
            $runs = is_array($block['runs'] ?? null) ? $block['runs'] : $this->runsFromHtml($html ?: '<p>'.e((string) ($block['text'] ?? '')).'</p>');
            $new = [
                'id' => (string) ($block['id'] ?? Str::uuid()),
                'type' => in_array(($block['type'] ?? 'paragraph'), ['heading','quote','list','list_item','table','image','page_break','divider'], true) ? $block['type'] : 'paragraph',
            ];
            if ($new['type'] === 'heading') $new['level'] = max(1, min(6, (int) ($block['level'] ?? 2)));
            if (!in_array($new['type'], ['page_break','divider','image','table'], true)) $new['runs'] = $runs;
            elseif ($new['type'] === 'table' && !empty($block['rows'])) $new['rows'] = $block['rows'];
            elseif ($new['type'] === 'image') $new += Arr::only($block, ['resourceId','width','height','alt','alignment','wrapping','metadata']);
            $blocks[] = $new;
        }

        $model['sections'] = [[
            'id' => 'section-1',
            'settings' => [],
            'header' => ['blocks' => []],
            'footer' => ['blocks' => []],
            'blocks' => $blocks,
            'footnotes' => [],
        ]];
        $model['schema'] = self::CURRENT_SCHEMA;
        return $model;
    }

    private function runsFromHtml(string $html): array
    {
        $html = $this->sanitizeHtml($html);
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-run-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $dom->getElementById('farast-run-root');
        if (!$root) return [['text' => '']];

        $walk = function (DOMNode $node, array $style = []) use (&$walk): array {
            $runs = [];
            foreach ($node->childNodes as $child) {
                $next = $style;
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->tagName);
                    if (in_array($tag, ['strong','b'], true)) $next['bold'] = true;
                    if (in_array($tag, ['em','i'], true)) $next['italic'] = true;
                    if ($tag === 'u') $next['underline'] = true;
                    if (in_array($tag, ['s','strike'], true)) $next['strike'] = true;
                    if ($tag === 'a' && $child->hasAttribute('href')) $next['href'] = mb_substr($child->getAttribute('href'), 0, 160);
                    if ($child->hasAttribute('dir')) $next['direction'] = $child->getAttribute('dir') === 'ltr' ? 'ltr' : 'rtl';
                    if ($child->hasAttribute('lang')) $next['language'] = mb_substr($child->getAttribute('lang'), 0, 32);
                    if ($child->hasAttribute('style')) {
                        foreach (explode(';', $child->getAttribute('style')) as $declaration) {
                            [$k,$v] = array_pad(explode(':', $declaration, 2), 2, '');
                            $k = trim($k); $v = trim($v);
                            if ($k === 'font-family') $next['fontFamily'] = mb_substr($v, 0, 120);
                            if ($k === 'font-size') $next['fontSize'] = mb_substr($v, 0, 40);
                            if ($k === 'color') $next['color'] = mb_substr($v, 0, 40);
                            if ($k === 'background-color') $next['highlight'] = mb_substr($v, 0, 40);
                        }
                    }
                }
                if ($child instanceof DOMText) {
                    $text = $this->normalizePersian($child->wholeText);
                    if ($text !== '') $runs[] = ['text' => $text] + $next;
                } else {
                    $runs = array_merge($runs, $walk($child, $next));
                }
            }
            return $runs;
        };

        return $this->sanitizeRuns($walk($root));
    }

    private function documentModelToHtml(array $model): string
    {
        $html = '';
        foreach (($model['sections'][0]['blocks'] ?? []) as $block) {
            $type = $block['type'] ?? 'paragraph';
            if ($type === 'page_break') {
                $html .= '<div class="farast-page-break" data-page-break="true"></div>';
                continue;
            }
            if ($type === 'divider') { $html .= '<hr>'; continue; }
            if ($type === 'image') {
                $src = $block['resourceId'] ?? '';
                $alt = htmlspecialchars((string) ($block['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<p><img src="'.htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'.$alt.'"></p>';
                continue;
            }
            if ($type === 'table') {
                $html .= '<table><tbody>';
                foreach (($block['rows'] ?? []) as $row) {
                    $html .= '<tr>';
                    foreach (($row['cells'] ?? []) as $cell) {
                        $attrs = '';
                        if (($cell['rowSpan'] ?? 1) > 1) $attrs .= ' rowspan="'.(int) $cell['rowSpan'].'"';
                        if (($cell['colSpan'] ?? 1) > 1) $attrs .= ' colspan="'.(int) $cell['colSpan'].'"';
                        $html .= '<td'.$attrs.'>'.$this->runsToHtml($cell['runs'] ?? []).'</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                continue;
            }
            $tag = $type === 'heading' ? 'h'.max(1, min(6, (int) ($block['level'] ?? 2))) : ($type === 'quote' ? 'blockquote' : ($type === 'list_item' ? 'li' : 'p'));
            $html .= '<'.$tag.'>'.$this->runsToHtml($block['runs'] ?? []).'</'.$tag.'>';
        }
        return $html ?: '<p><br></p>';
    }

    private function runsToHtml(array $runs): string
    {
        $html = '';
        foreach ($this->sanitizeRuns($runs) as $run) {
            $text = htmlspecialchars((string) $run['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $attrs = '';
            if (!empty($run['direction'])) $attrs .= ' dir="'.htmlspecialchars($run['direction'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (!empty($run['language'])) $attrs .= ' lang="'.htmlspecialchars($run['language'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if (!empty($run['href'])) $attrs .= ' href="'.htmlspecialchars($run['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            $styles = [];
            foreach (['fontFamily'=>'font-family','fontSize'=>'font-size','color'=>'color','highlight'=>'background-color'] as $key => $css) {
                if (!empty($run[$key])) $styles[] = $css.':'.htmlspecialchars($run[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if ($styles) $attrs .= ' style="'.implode(';', $styles).'"';
            if (!empty($run['href'])) $text = '<a'.$attrs.'>'.$text.'</a>';
            else {
                $text = $attrs ? '<span'.$attrs.'>'.$text.'</span>' : $text;
            }
            if (!empty($run['strike'])) $text = '<s>'.$text.'</s>';
            if (!empty($run['underline'])) $text = '<u>'.$text.'</u>';
            if (!empty($run['italic'])) $text = '<em>'.$text.'</em>';
            if (!empty($run['bold'])) $text = '<strong>'.$text.'</strong>';
            $html .= $text;
        }
        return $html ?: '<br>';
    }

    private function plainTextFromModel(array $model): string
    {
        $text = '';
        foreach (($model['sections'] ?? []) as $section) {
            foreach (($section['blocks'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'table') {
                    foreach (($block['rows'] ?? []) as $row) foreach (($row['cells'] ?? []) as $cell) {
                        $text .= implode('', array_map(fn ($r) => $r['text'] ?? '', $cell['runs'] ?? [])) . "\n";
                    }
                } else {
                    $text .= implode('', array_map(fn ($r) => $r['text'] ?? '', $block['runs'] ?? [])) . "\n";
                }
            }
        }
        return $this->normalizePersian(trim($text));
    }

    private function normalizePersian(string $text): string
    {
        return preg_replace(['/[يى]/u','/ك/u','/ۀ/u','/ـ/u','/\x{200C}{2,}/u'], ['ی','ک','هٔ','','\x{200C}'], $text) ?? $text;
    }

    private function wordCount(string $text): int
    {
        if ($text === '') return 0;
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
