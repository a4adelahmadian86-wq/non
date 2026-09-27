<?php

namespace App\Http\Controllers;

use App\Models\TypingDocument;
use App\Services\CapabilityService;
use Illuminate\Http\Request;

class EditorSaveController extends Controller
{
    public function __invoke(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed($request->user(), 'can_type'), 403, 'ویرایش برای این حساب فعال نیست.');
        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'content' => ['required', 'string', 'max:4000000'],
        ]);

        $document = TypingDocument::whereKey($data['document_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $html = $this->sanitize($data['content']);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        $document->update([
            'content' => $html,
            'word_count' => count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY)),
            'status' => $document->status === 'paid' ? 'paid' : 'draft',
        ]);

        return response()->json(['ok' => true, 'saved_at' => now()->toIso8601String()]);
    }

    private function sanitize(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><s><sup><sub><ol><ul><li><blockquote><h1><h2><h3><span><div><font><table><thead><tbody><tfoot><tr><td><th><hr><a>';
        $html = strip_tags($html, $allowed);
        if (! class_exists(\DOMDocument::class)) return $html;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="farast-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $dom->getElementById('farast-root');
        if (! $root) return '<p><br></p>';

        $walker = function ($node) use (&$walker) {
            if ($node instanceof \DOMElement) {
                foreach (iterator_to_array($node->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $value = (string) $attr->value;
                    if (in_array($name, ['class', 'dir', 'data-original', 'data-suggestions'], true)) {
                        continue;
                    }
                    if ($name === 'style') {
                        $safe = $this->safeStyle($value);
                        if ($safe === '') $node->removeAttribute($name); else $node->setAttribute('style', $safe);
                        continue;
                    }
                    if ($node->tagName === 'font' && in_array($name, ['face', 'size', 'color'], true)) {
                        if ($name === 'face') $node->setAttribute($name, mb_substr(preg_replace('/[^\p{L}\p{N}\s,_-]/u', '', $value) ?? '', 0, 80));
                        elseif ($name === 'size' && preg_match('/^[1-7]$/', $value) !== 1) $node->removeAttribute($name);
                        elseif ($name === 'color' && preg_match('/^(#[0-9a-f]{3,8}|rgb\([0-9,\s]+\))$/i', $value) !== 1) $node->removeAttribute($name);
                        continue;
                    }
                    if (in_array($node->tagName, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan'], true)) {
                        $n = max(1, min(50, (int) $value));
                        $node->setAttribute($name, (string) $n);
                        continue;
                    }
                    if ($node->tagName === 'a' && $name === 'href') {
                        if (! preg_match('/^(https?:|mailto:|#)/i', trim($value))) $node->removeAttribute($name);
                        continue;
                    }
                    $node->removeAttribute($name);
                }
            }
            foreach (iterator_to_array($node->childNodes) as $child) $walker($child);
        };
        $walker($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $dom->saveHTML($child);
        return $out ?: '<p><br></p>';
    }

    private function safeStyle(string $style): string
    {
        if (preg_match('/url\s*\(|expression\s*\(|javascript\s*:|@import/i', $style)) return '';
        $allowed = [
            'text-align','color','background-color','font-family','font-size','font-weight','font-style',
            'text-decoration','line-height','direction','margin-left','margin-right','padding-left','padding-right',
        ];
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if (! in_array($property, $allowed, true)) continue;
            if ($value === '' || preg_match('/[<>\\]/', $value)) continue;
            $safe[] = $property.':'.mb_substr($value, 0, 120);
        }
        return implode(';', $safe);
    }
}
