<?php

namespace App\Services;

class StoreProductDocumentPageBuilder
{
    /**
     * Build reader-ready HTML once during ingestion. The original document is never
     * needed by the browser for this representation.
     */
    public function build(array $chunks, array $inspection = []): array
    {
        $groups = [];
        $fallbackIndex = 0;

        foreach (array_slice($chunks, 0, 300) as $chunkIndex => $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $locator = is_array($chunk['locator'] ?? null) ? $chunk['locator'] : [];
            $kind = 'section';
            $key = 'section:'.(int) floor($fallbackIndex / 4 + 1);
            $label = 'بخش '.(int) floor($fallbackIndex / 4 + 1);
            $originalNumber = null;

            if (isset($locator['archive_entry']) && is_string($locator['archive_entry']) && isset($locator['page']) && is_numeric($locator['page']) && (int) $locator['page'] > 0) {
                $kind = 'archive_entry';
                $originalNumber = (int) $locator['page'];
                $key = 'archive:'.$locator['archive_entry'].':page:'.$originalNumber;
                $label = $locator['archive_entry'].' · صفحه '.$originalNumber;
            } elseif (isset($locator['archive_entry']) && is_string($locator['archive_entry'])) {
                $kind = 'archive_entry';
                $key = 'archive:'.$locator['archive_entry'];
                $label = $locator['archive_entry'];
            } elseif (isset($locator['page']) && is_numeric($locator['page']) && (int) $locator['page'] > 0) {
                $kind = 'pdf_page';
                $originalNumber = (int) $locator['page'];
                $key = 'page:'.$originalNumber;
                $label = 'صفحه '.$originalNumber;
            } elseif (isset($locator['slide']) && is_numeric($locator['slide']) && (int) $locator['slide'] > 0) {
                $kind = 'slide';
                $originalNumber = (int) $locator['slide'];
                $key = 'slide:'.$originalNumber;
                $label = 'اسلاید '.$originalNumber;
            } elseif (isset($locator['sheet']) && is_string($locator['sheet']) && trim($locator['sheet']) !== '') {
                $kind = 'sheet';
                $key = 'sheet:'.$locator['sheet'];
                $label = 'برگه '.$locator['sheet'];
            } elseif (isset($locator['media']) && is_string($locator['media'])) {
                $kind = 'media';
                $key = 'media:'.$locator['media'];
                $label = 'محتوای چندرسانه‌ای';
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'kind' => $kind,
                    'label' => $label,
                    'original_number' => $originalNumber,
                    'locator' => $locator,
                    'chunks' => [],
                ];
            }

            $groups[$key]['chunks'][] = [
                'chunk_index' => (int) $chunkIndex,
                'chunk_type' => (string) ($chunk['chunk_type'] ?? 'text'),
                'locator' => $locator,
                'content_text' => mb_substr((string) ($chunk['content_text'] ?? ''), 0, 1800),
            ];
            $fallbackIndex++;
        }

        $detectedType = strtolower((string) ($inspection['detected_type'] ?? ''));
        $declaredPages = max(0, min(300, (int) ($inspection['page_count'] ?? 0)));
        if ($detectedType === 'pdf' && $declaredPages > 0) {
            for ($page = 1; $page <= $declaredPages; $page++) {
                $key = 'page:'.$page;
                $groups[$key] ??= [
                    'key' => $key,
                    'kind' => 'pdf_page',
                    'label' => 'صفحه '.$page,
                    'original_number' => $page,
                    'locator' => ['page' => $page],
                    'chunks' => [],
                ];
            }
        }

        $ordered = array_values($groups);
        if ($ordered !== [] && collect($ordered)->every(fn (array $group) => in_array($group['kind'], ['pdf_page', 'slide'], true))) {
            usort($ordered, fn (array $left, array $right) => (int) $left['original_number'] <=> (int) $right['original_number']);
        }

        $pages = [];
        $chunkLocators = [];
        foreach ($ordered as $index => $group) {
            $pageNumber = $group['kind'] === 'pdf_page' && $group['original_number']
                ? (int) $group['original_number']
                : $index + 1;

            $textParts = [];
            foreach ($group['chunks'] as $chunk) {
                $text = trim((string) $chunk['content_text']);
                if ($text !== '') {
                    $textParts[] = $text;
                }
                $locator = (array) $chunk['locator'];
                $locator['reader_page'] = $pageNumber;
                $chunkLocators[$chunk['chunk_index']] = $locator;
            }

            $contentText = implode("\n\n", $textParts);
            $htmlParagraphs = [];
            foreach (preg_split('/(?:\r?\n){2,}/u', $contentText) ?: [] as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') continue;
                $sentenceHtml = [];
                foreach (preg_split('/(?<=[.!؟۔؛])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [$paragraph] as $sentence) {
                    $safeSentence = htmlspecialchars(trim($sentence), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if ($safeSentence !== '') {
                        $sentenceHtml[] = '<span class="reader-document-sentence" role="button" tabindex="0">'.$safeSentence.'</span>';
                    }
                }
                if ($sentenceHtml !== []) {
                    $htmlParagraphs[] = '<p class="reader-document-paragraph" tabindex="0" role="button">'.implode(' ', $sentenceHtml).'</p>';
                }
            }
            if ($htmlParagraphs === []) {
                $htmlParagraphs[] = '<p class="reader-document-empty">متن قابل استخراجی برای این بخش موجود نیست؛ محتوای بصری آن هنوز در نسخه خوانش متنی آماده نشده است.</p>';
            }

            $safeLabel = htmlspecialchars((string) $group['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $contentHtml = '<section class="farast-document-html-page" dir="auto">'
                .'<div class="farast-document-html-heading">'.$safeLabel.'</div>'
                .'<div class="farast-pdf-text-layer" style="position:relative;display:block;inset:auto;overflow:visible;opacity:1;line-height:1.95;user-select:text;-webkit-user-select:text;">'
                .implode("\n", $htmlParagraphs)
                .'</div></section>';

            $pages[] = [
                'page_number' => $pageNumber,
                'page_kind' => $group['kind'],
                'locator_key' => mb_strlen($group['key']) > 190
                    ? mb_substr($group['key'], 0, 165).'#'.substr(hash('sha256', $group['key']), 0, 16)
                    : $group['key'],
                'locator_json' => array_merge((array) $group['locator'], ['reader_page' => $pageNumber]),
                'content_text' => $contentText,
                'content_html' => $contentHtml,
                'content_sha256' => hash('sha256', $contentText),
                'has_extracted_text' => $contentText !== '',
            ];
        }

        return [
            'pages' => $pages,
            'chunk_locators' => $chunkLocators,
            'page_count' => count($pages),
        ];
    }
}
