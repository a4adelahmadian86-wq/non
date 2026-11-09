<?php

namespace App\Services;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductContentGenerationService
{
    public function __construct(
        private readonly ProductKnowledgeService $knowledge,
        private readonly EditorAiAssistService $ai,
        private readonly ProductContentPrivacyFilter $privacy,
    ) {}

    public function readiness(StoreProduct $product): array
    {
        $context = $this->knowledge->contentGenerationContext($product);

        return [
            'status' => $context['knowledge_status'],
            'reasons' => $context['reasons'],
            'source_count' => $context['source_count'],
            'facts_included' => $context['facts_included'],
            'lesson_count' => $context['course_lesson_count'],
            'source_fingerprints' => $context['source_fingerprints'],
            'source_signature' => $this->signature($product, $context['source_fingerprints']),
            'stored_content_stale' => $this->storedContentIsStale($product, $context['source_fingerprints']),
        ];
    }

    public function generate(User $user, StoreProduct $product, array $options = []): array
    {
        $knowledge = $this->knowledge->contentGenerationContext($product);
        if ($knowledge['knowledge_status'] !== 'available') {
            $message = collect($knowledge['reasons'])->pluck('message')->filter()->unique()->implode(' ');
            throw ValidationException::withMessages([
                'knowledge' => $message !== '' ? $message : 'دانش کامل و قابل اتکایی برای این محصول آماده نیست.',
            ]);
        }

        $categories = StoreCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name'])
            ->map(fn ($category) => ['id' => (string) $category->id, 'name' => (string) $category->name])
            ->values()
            ->all();

        $sourceIds = collect($knowledge['sources'])->pluck('source_id')->all();
        if ($knowledge['course_lessons'] !== []) $sourceIds[] = 'course:lessons';
        $factIds = collect($knowledge['sources'])->flatMap(fn ($source) => collect($source['facts'])->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        $report = [
            'product' => $knowledge['product_context'],
            'allowed_categories' => $categories,
            'allowed_source_ids' => $sourceIds,
            'allowed_fact_ids' => $factIds,
            'knowledge_report' => [
                'sources' => collect($knowledge['sources'])->map(function (array $source): array {
                    unset($source['file_name'], $source['source_sha256'], $source['storage_path'], $source['path']);
                    return $source;
                })->values()->all(),
                'course_lessons' => $knowledge['course_lessons'],
                'grounding_instruction' => $knowledge['grounding_instruction'],
            ],
            'output_rules' => [
                'language' => 'fa-IR',
                'description_format' => 'plain text with short headings and bullet points; no HTML',
                'seo_title_target_characters' => '45-65',
                'seo_description_target_characters' => '140-170',
                'tag_limit' => 10,
                'secondary_keyword_limit' => 10,
                'never_invent' => ['specifications', 'guarantees', 'certificates', 'prices', 'discounts', 'outcomes', 'compatibility', 'included_items'],
                'category_id_must_match_allowed_categories' => true,
                'cite_only_known_source_ids_and_fact_ids' => true,
            ],
        ];

        // Defense in depth: the external model receives only a redacted, bounded report.
        $report = $this->privacy->sanitize($report);
        $text = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($text) || mb_strlen($text) > 39000) {
            throw ValidationException::withMessages([
                'knowledge' => 'گزارش دانش این محصول برای پردازش تک‌مرحله‌ای بیش از حد بزرگ است؛ باید ابتدا آن را به گزارش‌های کوچک‌تر و کامل تقسیم کنیم.',
            ]);
        }

        $tone = trim((string) ($options['tone'] ?? 'professional'));
        $tone = in_array($tone, ['professional', 'friendly', 'technical'], true) ? $tone : 'professional';
        $focusKeyword = mb_substr(trim(strip_tags((string) ($options['focus_keyword'] ?? ''))), 0, 120);
        $extra = mb_substr(trim(strip_tags((string) ($options['instructions'] ?? ''))), 0, 500);
        $instruction = 'لحن: '.$tone.'. ';
        if ($focusKeyword !== '') $instruction .= 'کلیدواژه کانونی درخواستی: '.$focusKeyword.'. ';
        if ($extra !== '') $instruction .= 'راهنمای تکمیلی کاربر (فقط در صورت سازگاری با شواهد): '.$extra;
        $instruction = $this->privacy->sanitizeText($instruction);

        $ai = $this->ai->assist('product.content.generate', $text, [
            'user' => $user,
            'provider' => 'gemini',
            'processing_mode' => 'external',
            'instruction' => $instruction,
            'idempotency_key' => 'product-content-'.$product->id.'-'.Str::uuid(),
        ]);

        $result = is_array($ai['result'] ?? null) ? $ai['result'] : $ai;
        $factSourceMap = [];
        foreach ($knowledge['sources'] as $source) {
            foreach ($source['facts'] as $fact) {
                $factSourceMap[(int) $fact['id']] = (string) $source['source_id'];
            }
        }
        $clean = $this->normalizeResult($result, $categories, $factIds, $sourceIds, $factSourceMap);
        $clean = $this->privacy->sanitize($clean);
        $clean['slug'] = Str::slug((string) $clean['title']) ?: 'product-'.$product->id;
        $usedFacts = $clean['used_fact_ids'];
        $usedSources = $clean['used_source_ids'];
        if ($usedFacts === [] && $usedSources === []) {
            throw new RuntimeException('ai_product_content_missing_sources');
        }

        return [
            'suggestions' => $clean,
            'sources' => collect($knowledge['sources'])->map(function (array $source) use ($usedFacts): array {
                $usedSourceFacts = collect($source['facts'])
                    ->filter(fn (array $fact) => in_array((int) $fact['id'], $usedFacts, true))
                    ->values()
                    ->all();

                return [
                    'source_id' => $source['source_id'],
                    'name' => $source['file_name'],
                    'file_name' => $source['file_name'],
                    'type' => $source['file_type'],
                    'pages' => $source['page_count'],
                    'facts_total' => $source['facts_total'],
                    'facts' => $usedSourceFacts,
                ];
            })->values()->all(),
            'course_lesson_count' => count($knowledge['course_lessons']),
            'course_lessons' => $knowledge['course_lessons'],
            'source_fingerprints' => $knowledge['source_fingerprints'],
            'source_signature' => $this->signature($product, $knowledge['source_fingerprints']),
            'ai' => $ai['ai'] ?? null,
        ];
    }

    public function currentSourceFingerprints(StoreProduct $product): array
    {
        return $this->knowledge->contentGenerationContext($product)['source_fingerprints'];
    }

    public function signature(StoreProduct $product, array $fingerprints): string
    {
        ksort($fingerprints);
        $payload = $product->id.'|'.json_encode($fingerprints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public function assertFreshProvenance(StoreProduct $product, string $encodedFingerprints, string $signature): array
    {
        $submitted = json_decode($encodedFingerprints, true);
        if (! is_array($submitted) || $submitted === [] || count($submitted) > 100) {
            throw ValidationException::withMessages(['ai_content' => 'منبع پیشنهاد هوشمند معتبر نیست؛ دوباره تولید محتوا را اجرا کن.']);
        }

        $current = $this->knowledge->contentGenerationContext($product);
        if ($current['knowledge_status'] !== 'available') {
            throw ValidationException::withMessages(['ai_content' => 'دانش محصول پس از تولید پیشنهاد تغییر کرده یا دیگر کامل نیست؛ دوباره تولید محتوا را اجرا کن.']);
        }

        ksort($submitted);
        $currentFingerprints = $current['source_fingerprints'];
        ksort($currentFingerprints);
        if ($submitted !== $currentFingerprints || ! hash_equals($this->signature($product, $submitted), $signature)) {
            throw ValidationException::withMessages(['ai_content' => 'فایل یا محتوای دوره پس از تولید پیشنهاد تغییر کرده است؛ پیشنهاد تازه بساز.']);
        }

        return $submitted;
    }

    public function validateFieldEvidence(StoreProduct $product, string $encodedEvidence): array
    {
        $decoded = json_decode($encodedEvidence, true);
        if (! is_array($decoded) || $decoded === [] || count($decoded) > 10) {
            throw ValidationException::withMessages(['ai_content_field_evidence' => 'ارجاع شواهد پیشنهاد هوشمند معتبر نیست. دوباره محتوا را تولید کن.']);
        }

        $knowledge = $this->knowledge->contentGenerationContext($product);
        if ($knowledge['knowledge_status'] !== 'available') {
            throw ValidationException::withMessages(['ai_content_field_evidence' => 'دانش محصول تغییر کرده است؛ ابتدا پیشنهاد تازه بساز.']);
        }

        $validSourceIds = collect($knowledge['sources'])->pluck('source_id')->map(fn ($id) => (string) $id)->all();
        $factSourceMap = [];
        foreach ($knowledge['sources'] as $source) {
            foreach ($source['facts'] as $fact) {
                $factSourceMap[(int) $fact['id']] = (string) $source['source_id'];
            }
        }
        $allowedFields = [
            'title', 'short_description', 'description', 'seo_title', 'seo_description',
            'focus_keyword', 'version', 'license_type', 'secondary_keywords', 'tags', 'category', 'selling_points',
        ];
        $normalized = [];

        foreach ($decoded as $field => $entry) {
            if (! is_string($field) || ! in_array($field, $allowedFields, true) || ! is_array($entry)) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'ساختار ارجاع شواهد معتبر نیست.']);
            }

            $rawFactIds = is_array($entry['fact_ids'] ?? null) ? $entry['fact_ids'] : [];
            $rawSourceIds = is_array($entry['source_ids'] ?? null) ? $entry['source_ids'] : [];
            if (count($rawFactIds) > 40 || count($rawSourceIds) > 20) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'تعداد ارجاع‌های شواهد از سقف مجاز بیشتر است.']);
            }

            $factIds = collect($rawFactIds)
                ->filter(fn ($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && isset($factSourceMap[(int) $id]))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $sourceIds = collect($rawSourceIds)
                ->filter(fn ($id) => is_string($id) && in_array($id, $validSourceIds, true))
                ->unique()->values()->all();

            if (count($factIds) !== count($rawFactIds) || count($sourceIds) !== count($rawSourceIds)) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'یکی از شناسه‌های منبع یا واقعیت دیگر معتبر نیست. دوباره پیشنهاد بساز.']);
            }

            if ($factIds !== [] && $sourceIds !== [] && collect($factIds)->contains(
                fn ($id) => ! in_array((string) $factSourceMap[$id], $sourceIds, true)
            )) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'یک واقعیت به منبع اشتباه نسبت داده شده است. پیشنهاد را دوباره تولید کن.']);
            }

            if ($sourceIds === [] && $factIds !== []) {
                $sourceIds = collect($factIds)
                    ->map(fn ($id) => (string) $factSourceMap[$id])
                    ->unique()->values()->all();
            }

            if ($factIds === [] && $sourceIds === []) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'ارجاع بدون منبع معتبر پذیرفته نمی‌شود.']);
            }

            $normalized[$field] = ['fact_ids' => $factIds, 'source_ids' => $sourceIds];
        }

        foreach (['title', 'short_description', 'description', 'seo_title', 'seo_description'] as $requiredField) {
            if (! isset($normalized[$requiredField])) {
                throw ValidationException::withMessages(['ai_content_field_evidence' => 'برای همه فیلدهای اصلی باید شاهد معتبر ثبت شده باشد.']);
            }
        }

        return $normalized;
    }

    private function storedContentIsStale(StoreProduct $product, array $fingerprints): bool
    {
        $stored = (array) data_get($product->metadata, 'ai_content.source_fingerprints', []);
        if ($stored === []) return false;
        ksort($stored);
        ksort($fingerprints);

        return $stored !== $fingerprints;
    }

    private function normalizeResult(array $result, array $categories, array $validFactIds, array $validSourceIds, array $factSourceMap): array
    {
        $string = static fn ($key, $max) => mb_substr(trim(strip_tags((string) ($result[$key] ?? ''))), 0, $max);
        $list = static function ($key, $maxItems, $maxLength) use ($result) {
            return collect(is_array($result[$key] ?? null) ? $result[$key] : [])
                ->map(fn ($value) => mb_substr(trim(strip_tags((string) $value)), 0, $maxLength))
                ->filter()
                ->unique(fn ($value) => mb_strtolower($value))
                ->take($maxItems)
                ->values()
                ->all();
        };

        $categoryId = trim((string) ($result['category_id'] ?? ''));
        $allowedCategoryIds = array_map('strval', array_column($categories, 'id'));
        if (! in_array($categoryId, $allowedCategoryIds, true)) $categoryId = '';

        $allowedEvidenceFields = [
            'title', 'short_description', 'description', 'seo_title', 'seo_description',
            'focus_keyword', 'version', 'license_type', 'secondary_keywords', 'tags', 'category', 'selling_points',
        ];
        $fieldEvidence = [];
        foreach (is_array($result['field_evidence'] ?? null) ? $result['field_evidence'] : [] as $entry) {
            if (! is_array($entry)) continue;
            $field = trim((string) ($entry['field'] ?? ''));
            if (! in_array($field, $allowedEvidenceFields, true) || isset($fieldEvidence[$field])) continue;
            $rawFactIds = collect(is_array($entry['fact_ids'] ?? null) ? $entry['fact_ids'] : [])
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $rawSourceIds = collect(is_array($entry['source_ids'] ?? null) ? $entry['source_ids'] : [])
                ->map(fn ($id) => trim((string) $id))->unique()->values()->all();
            $sourceIdsForField = collect($rawSourceIds)
                ->filter(fn ($id) => in_array($id, $validSourceIds, true))
                ->values()->all();
            $hasInvalidFact = collect($rawFactIds)->contains(
                fn ($id) => ! in_array($id, $validFactIds, true) || ! isset($factSourceMap[$id])
            );
            $hasMismatchedSource = $rawFactIds !== [] && $sourceIdsForField !== []
                && collect($rawFactIds)->contains(
                    fn ($id) => isset($factSourceMap[$id])
                        && ! in_array((string) $factSourceMap[$id], $sourceIdsForField, true)
                );
            if ($hasInvalidFact || $hasMismatchedSource || count($sourceIdsForField) !== count($rawSourceIds)) {
                continue;
            }
            $factIdsForField = $rawFactIds;
            if ($sourceIdsForField === [] && $factIdsForField !== []) {
                $sourceIdsForField = collect($factIdsForField)
                    ->map(fn ($id) => (string) $factSourceMap[$id])
                    ->unique()->values()->all();
            }
            if ($factIdsForField !== [] || $sourceIdsForField !== []) {
                $fieldEvidence[$field] = [
                    'fact_ids' => $factIdsForField,
                    'source_ids' => $sourceIdsForField,
                ];
            }
        }
        $usedFactIds = collect($fieldEvidence)->flatMap(fn (array $evidence) => $evidence['fact_ids'])->unique()->values()->all();
        $usedSourceIds = collect($fieldEvidence)->flatMap(fn (array $evidence) => $evidence['source_ids'])->unique()->values()->all();

        $normalized = [
            'title' => $string('title', 220),
            'short_description' => $string('short_description', 2000),
            'description' => $string('description', 20000),
            'seo_title' => $string('seo_title', 220),
            'seo_description' => $string('seo_description', 320),
            'focus_keyword' => $string('focus_keyword', 120),
            'version' => $string('version', 60),
            'license_type' => $string('license_type', 60),
            'secondary_keywords' => $list('secondary_keywords', 10, 80),
            'tags' => $list('tags', 10, 80),
            'category_id' => $categoryId,
            'category_reason' => $string('category_reason', 500),
            'selling_points' => $list('selling_points', 8, 180),
            'used_fact_ids' => $usedFactIds,
            'used_source_ids' => $usedSourceIds,
            'field_evidence' => $fieldEvidence,
            'unknowns' => $list('unknowns', 10, 240),
        ];

        foreach (['title', 'short_description', 'description', 'seo_title', 'seo_description'] as $required) {
            if ($normalized[$required] === '' || ! isset($fieldEvidence[$required])) {
                throw new RuntimeException('ai_product_content_invalid');
            }
        }

        foreach ([
            'focus_keyword' => 'focus_keyword',
            'version' => 'version',
            'license_type' => 'license_type',
            'secondary_keywords' => 'secondary_keywords',
            'tags' => 'tags',
            'selling_points' => 'selling_points',
        ] as $outputField => $evidenceField) {
            if ($normalized[$outputField] !== [] && ! isset($fieldEvidence[$evidenceField])) {
                $normalized[$outputField] = [];
            }
        }

        if ($normalized['category_id'] !== '' && ! isset($fieldEvidence['category'])) {
            $normalized['category_id'] = '';
            $normalized['category_reason'] = '';
        }

        return $normalized;
    }
}
