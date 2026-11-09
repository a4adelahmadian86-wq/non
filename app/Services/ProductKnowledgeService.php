<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductKnowledgeService
{
    /**
     * Build a source-grounded context for any AI feature. Never infer facts from missing,
     * stale, or only partially extracted documents.
     */
    public function contextForProduct(StoreProduct|int $product, int $maxCharacters = 12000, bool $sampleOnly = false): array
    {
        $product = $product instanceof StoreProduct
            ? $product
            : StoreProduct::query()->findOrFail($product);

        $maxCharacters = max(1000, min(120000, $maxCharacters));
        $product->loadMissing(['files.document.chunks', 'files.document.artifacts', 'files.document.facts']);

        $sources = [];
        $remaining = $maxCharacters;

        foreach ($product->files->where('is_active', true)->sortByDesc('is_primary') as $file) {
            $document = $file->document;
            if (! $document || ! in_array($document->processing_status, ['completed', 'extracted', 'partial'], true)) {
                continue;
            }
            if (! hash_equals((string) $file->sha256, (string) $document->source_sha256)) {
                continue;
            }

            $samplePages = array_values(array_filter(
                (array) data_get($document->structure_json, 'sample.selected_pages', []),
                static fn ($page) => is_int($page) && $page > 0
            ));
            if ($sampleOnly && $samplePages === []) {
                continue;
            }

            $chunks = [];
            foreach ($document->chunks as $chunk) {
                if ($remaining <= 0) break;
                $locator = (array) ($chunk->locator_json ?? []);
                if ($sampleOnly) {
                    $chunkPages = array_filter(array_merge(
                        isset($locator['page']) ? [(int) $locator['page']] : [],
                        is_array($locator['pages'] ?? null) ? $locator['pages'] : [],
                        isset($locator['page_start'], $locator['page_end']) ? range((int) $locator['page_start'], (int) $locator['page_end']) : []
                    ), static fn ($page) => is_int($page) || ctype_digit((string) $page));
                    if (array_intersect($samplePages, array_map('intval', $chunkPages)) === []) {
                        continue;
                    }
                }
                $text = mb_substr((string) $chunk->content_text, 0, $remaining);
                if ($text === '') continue;
                $chunks[] = [
                    'chunk_type' => $chunk->chunk_type,
                    'locator' => $locator,
                    'content' => $text,
                    'content_sha256' => $chunk->content_sha256,
                ];
                $remaining -= mb_strlen($text);
            }

            $facts = [];
            foreach ($document->facts as $fact) {
                if (! hash_equals((string) $file->sha256, (string) $fact->source_sha256)
                    || $fact->verification_status !== 'evidence_backed') {
                    continue;
                }
                $factLocator = (array) ($fact->locator_json ?? []);
                if ($sampleOnly) {
                    $factPages = array_filter([
                        isset($factLocator['page']) ? (int) $factLocator['page'] : null,
                        ...(is_array($factLocator['pages'] ?? null) ? array_map('intval', $factLocator['pages']) : []),
                    ], static fn ($page) => is_int($page) && $page > 0);
                    if (array_intersect($samplePages, $factPages) === []) {
                        continue;
                    }
                }
                $factPayload = [
                    'type' => (string) $fact->fact_type,
                    'key' => (string) $fact->fact_key,
                    'value' => (string) $fact->fact_value,
                    'evidence' => (string) $fact->evidence_text,
                    'locator' => $factLocator,
                    'verification_status' => 'evidence_backed',
                ];
                $cost = mb_strlen($factPayload['key']) + mb_strlen($factPayload['value']) + mb_strlen($factPayload['evidence']);
                if ($cost > $remaining) break;
                $facts[] = $factPayload;
                $remaining -= $cost;
                if (count($facts) >= 60) break;
            }

            $review = (array) data_get($document->structure_json, 'ai_review', []);
            $completeness = (array) data_get($document->structure_json, 'completeness', []);
            $sources[] = [
                'source_file_id' => (int) $file->id,
                'source_sha256' => (string) $document->source_sha256,
                'original_name' => (string) $file->original_name,
                'source_type' => (string) ($document->detected_type ?: $file->mime),
                'processing_status' => (string) $document->processing_status,
                'semantic_review_complete' => (
                    data_get($review, 'provider_status.groq_text') === 'completed'
                    || data_get($review, 'provider_status.gemini') === 'completed'
                ) && (bool) ($completeness['analysis_batches_complete'] ?? false),
                'extraction_complete' => (bool) ($completeness['extraction_complete'] ?? false),
                'analysis_batch_count' => (int) ($completeness['analysis_batch_count'] ?? data_get($review, 'analysis_batch_count', 0)),
                'successful_analysis_batches' => (int) ($completeness['successful_analysis_batches'] ?? data_get($review, 'successful_analysis_batches', 0)),
                'page_count' => $document->page_count,
                'word_count' => $document->word_count,
                'character_count' => $document->character_count,
                'summary' => $sampleOnly ? null : data_get($review, 'summary'),
                'findings' => $sampleOnly ? [] : data_get($review, 'findings', []),
                'facts' => $facts,
                'fact_count' => count($facts),
                'chunks' => $chunks,
                'audio' => $sampleOnly ? null : data_get($document->structure_json, 'audio.status'),
                'sample_pages' => $samplePages,
            ];

            if ($remaining <= 0) break;
        }

        $hasIncompleteSource = collect($sources)->contains(function (array $source): bool {
            $expected = (int) ($source['analysis_batch_count'] ?? 0);
            $successful = (int) ($source['successful_analysis_batches'] ?? 0);

            return ($source['processing_status'] ?? null) !== 'completed'
                || ! ($source['semantic_review_complete'] ?? false)
                || ! ($source['extraction_complete'] ?? false)
                || ($expected > 0 && $successful < $expected);
        });
        $knowledgeStatus = $sources === []
            ? 'not_available'
            : ($remaining <= 0 ? 'context_truncated' : ($hasIncompleteSource ? 'partial' : 'available'));

        return [
            'product_id' => (int) $product->id,
            'product_title' => (string) $product->title,
            'product_type' => (string) $product->type,
            'download_policy' => (string) $product->download_policy,
            'knowledge_status' => $knowledgeStatus,
            'source_count' => count($sources),
            'remaining_character_budget' => $remaining,
            'access_scope' => $sampleOnly ? 'public_sample_only' : 'purchased_full_document',
            'sources' => $sources,
            'grounding_instruction' => 'Use only the supplied product record, evidence-backed facts, and source-located chunks. Every factual claim must be supported by its exact evidence and locator. Do not infer missing pages, features, lessons, specifications, or benefits. If evidence is absent or processing is incomplete, state that it is unknown.',
        ];
    }

    public function contentGenerationContext(StoreProduct|int $product): array
    {
        $product = $product instanceof StoreProduct ? $product : StoreProduct::query()->findOrFail($product);
        $product->loadMissing([
            'category',
            'tags',
            'files.document.facts',
            'course.sections.lessons.section',
            'course.sections.lessons.resources.document.facts',
        ]);

        $purpose = $product->type === 'course' ? 'lesson_resource' : 'product_download';
        $files = $product->files
            ->where('is_active', true)
            ->where('purpose', $purpose)
            ->sortByDesc('is_primary')
            ->values();

        $reasons = [];
        $sources = [];
        $fingerprints = [];
        $factsIncluded = 0;
        $factLimit = 40;

        if ($product->type === 'digital_file' && ! $files->contains(
            fn ($file) => $file->is_primary && $file->scan_status === 'clean'
        )) {
            $reasons[] = ['code' => 'primary_file_missing', 'message' => 'فایل اصلی سالم و فعال برای تحلیل دانش محصول وجود ندارد.'];
        }

        foreach ($files as $file) {
            if ($file->scan_status !== 'clean') {
                $reasons[] = ['code' => 'file_not_clean', 'message' => 'یکی از فایل‌های فعال هنوز تأیید امنیتی نشده است.'];
                continue;
            }

            $document = $file->document;
            if (! $document
                || (int) $document->product_id !== (int) $product->id
                || (int) $document->product_file_id !== (int) $file->id
                || ! hash_equals((string) $file->sha256, (string) $document->source_sha256)) {
                $reasons[] = ['code' => 'document_missing_or_stale', 'message' => 'گزارش یکی از فایل‌ها وجود ندارد یا با نسخه فعلی فایل تطبیق ندارد.'];
                continue;
            }

            $structure = (array) ($document->structure_json ?? []);
            $completeness = (array) data_get($structure, 'completeness', []);
            $review = (array) data_get($structure, 'ai_review', []);
            $providers = (array) data_get($review, 'provider_status', []);
            $expected = (int) ($completeness['analysis_batch_count'] ?? $review['analysis_batch_count'] ?? 0);
            $successful = (int) ($completeness['successful_analysis_batches'] ?? $review['successful_analysis_batches'] ?? 0);
            $extractionComplete = (bool) ($completeness['extraction_complete'] ?? false)
                && ! (bool) data_get($structure, 'inspection.extraction_truncated', false)
                && ! (bool) data_get($structure, 'inspection.transcription_truncated', false);
            $textReviewComplete = $expected > 0
                && $successful >= $expected
                && ($providers['groq_text'] ?? null) === 'completed'
                && (bool) ($completeness['analysis_batches_complete'] ?? false);
            $visualReviewComplete = $expected === 0 && ($providers['groq_vision'] ?? null) === 'completed';
            $semanticReviewComplete = $textReviewComplete || $visualReviewComplete;

            if ($document->processing_status !== 'completed' || ! $extractionComplete || ! $semanticReviewComplete) {
                $reasons[] = [
                    'code' => 'analysis_incomplete',
                    'message' => 'تحلیل کامل یکی از فایل‌ها هنوز تأیید نشده است؛ تولید محتوا تا تکمیل همه مراحل غیرفعال می‌ماند.',
                ];
                continue;
            }

            // Video review currently samples a bounded audio segment and a single frame.
            // It must never be presented as a complete source review.
            if (($document->detected_type ?: '') === 'video') {
                $reasons[] = ['code' => 'video_review_partial', 'message' => 'تحلیل کامل ویدئو در حال حاضر پشتیبانی نمی‌شود؛ فقط نمونه محدودی بررسی می‌شود.'];
                continue;
            }

            $summary = trim((string) ($review['summary'] ?? ''));
            $visualReport = trim((string) data_get($review, 'media_reports.visual', ''));
            if ($summary === '' || str_contains($summary, 'بررسی ساختاری') && $visualReport !== '') {
                $summary = $visualReport;
            }

            $sourceFacts = [];
            foreach ($document->facts as $fact) {
                if ($factsIncluded >= $factLimit) break;
                if (! hash_equals((string) $file->sha256, (string) $fact->source_sha256)
                    || $fact->verification_status !== 'evidence_backed'
                    || trim((string) $fact->evidence_text) === '') {
                    continue;
                }
                $sourceFacts[] = [
                    'id' => (int) $fact->id,
                    'type' => (string) $fact->fact_type,
                    'key' => mb_substr((string) $fact->fact_key, 0, 100),
                    'value' => mb_substr((string) $fact->fact_value, 0, 320),
                    'evidence' => mb_substr((string) $fact->evidence_text, 0, 260),
                    'locator' => (array) ($fact->locator_json ?? []),
                ];
                $factsIncluded++;
            }

            if ($summary === '' && $sourceFacts === []) {
                $reasons[] = ['code' => 'knowledge_report_empty', 'message' => 'گزارش قابل اتکایی برای تولید محتوای این فایل ثبت نشده است.'];
                continue;
            }

            $fingerprints['file:'.$file->id] = (string) $file->sha256;
            $sources[] = [
                'source_id' => 'file:'.$file->id,
                'file_name' => (string) $file->original_name,
                'file_type' => (string) ($document->detected_type ?: $file->mime),
                'page_count' => $document->page_count,
                'word_count' => $document->word_count,
                'character_count' => $document->character_count,
                'summary' => mb_substr($summary, 0, 2200),
                'findings' => collect($review['findings'] ?? [])->take(8)->map(fn ($finding) => [
                    'severity' => (string) data_get($finding, 'severity', ''),
                    'title' => mb_substr((string) data_get($finding, 'title', ''), 0, 140),
                    'detail' => mb_substr((string) data_get($finding, 'detail', ''), 0, 300),
                ])->values()->all(),
                'facts' => $sourceFacts,
                'facts_total' => $document->facts->where('verification_status', 'evidence_backed')->count(),
            ];
        }

        $courseLessons = [];
        if ($product->type === 'course') {
            $lessons = $product->course?->sections?->flatMap(fn ($section) => $section->lessons) ?? collect();
            if ($lessons->isEmpty()) {
                $reasons[] = ['code' => 'course_lessons_missing', 'message' => 'برای دوره هنوز محتوای درس قابل استفاده ثبت نشده است.'];
            }

            $lessonFingerprintData = [];
            foreach ($lessons as $lesson) {
                $lessonText = trim(preg_replace('/\\s+/u', ' ', html_entity_decode(strip_tags((string) $lesson->content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                $lessonSummary = trim(preg_replace('/\\s+/u', ' ', (string) $lesson->summary) ?? '');
                $hasResource = $lesson->resources->contains(fn ($resource) => $resource->is_active);
                if (filled($lesson->video_url)) {
                    $reasons[] = ['code' => 'course_video_untranscribed', 'message' => 'یکی از درس‌های دوره ویدئوی بدون متنِ کاملِ قابل استناد دارد.'];
                } elseif ($lesson->is_published && $lessonText === '' && $lessonSummary === '' && ! $hasResource) {
                    $reasons[] = ['code' => 'course_lesson_content_missing', 'message' => 'یکی از درس‌های منتشرشده متن یا منبع پردازش‌شده ندارد.'];
                }

                if ($lessonText !== '' || $lessonSummary !== '') {
                    $courseLessons[] = [
                        'section' => mb_substr((string) $lesson->section?->title, 0, 120),
                        'title' => mb_substr((string) $lesson->title, 0, 180),
                        'type' => (string) $lesson->lesson_type,
                        'summary' => mb_substr($lessonSummary, 0, 700),
                        'content' => mb_substr($lessonText, 0, 1200),
                        'published' => (bool) $lesson->is_published,
                    ];
                }
                $lessonFingerprintData[] = [
                    'id' => (int) $lesson->id,
                    'title' => (string) $lesson->title,
                    'type' => (string) $lesson->lesson_type,
                    'summary' => $lessonSummary,
                    'content_hash' => hash('sha256', $lessonText),
                    'video_url_present' => filled($lesson->video_url),
                ];
            }

            if ($lessonFingerprintData !== []) {
                $fingerprints['course:lessons'] = hash('sha256', json_encode($lessonFingerprintData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        $hasKnowledge = $sources !== [] || $courseLessons !== [];
        $status = ! $hasKnowledge ? 'not_available' : ($reasons === [] ? 'available' : 'partial');
        $fingerprints = collect($fingerprints)->sortKeys()->all();

        return [
            'product_id' => (int) $product->id,
            'product_type' => (string) $product->type,
            'knowledge_status' => $status,
            'reasons' => $reasons,
            'source_count' => count($sources),
            'facts_included' => $factsIncluded,
            'sources' => $sources,
            'course_lessons' => $courseLessons,
            'course_lesson_count' => count($courseLessons),
            'source_fingerprints' => $fingerprints,
            'product_context' => [
                'title' => (string) $product->title,
                'short_description' => (string) $product->short_description,
                'description' => mb_substr((string) $product->description, 0, 1800),
                'category' => (string) $product->category?->name,
                'tags' => $product->tags->pluck('name')->map(fn ($name) => (string) $name)->values()->all(),
                'course_subtitle' => (string) $product->course?->subtitle,
                'course_level' => (string) $product->course?->level,
                'course_language' => (string) $product->course?->language,
            ],
            'grounding_instruction' => 'این داده فقط گزارش دانش پایگاه داده، واقعیت‌های دارای شاهد و متن درس‌های ثبت‌شده است؛ فایل خام، مسیر ذخیره‌سازی و محتوای کامل قطعه‌های استخراج‌شده ارسال نشده است. فقط ادعاهایی را بنویس که از این گزارش پشتیبانی می‌شوند. هر ویژگی نامعلوم را حذف کن و در unknowns بیاور.',
        ];
    }


}
