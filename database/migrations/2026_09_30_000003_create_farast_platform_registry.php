<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('farast_applications')) {
            Schema::create('farast_applications', function (Blueprint $t) {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('status', 30)->default('active');
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_capabilities')) {
            Schema::create('farast_capabilities', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100)->unique();
                $t->string('name', 160);
                $t->string('billing_mode', 40)->default('included');
                $t->string('unit', 40)->nullable();
                $t->string('status', 30)->default('active');
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_providers')) {
            Schema::create('farast_providers', function (Blueprint $t) {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('kind', 40);
                $t->string('status', 30)->default('active');
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_tools')) {
            Schema::create('farast_tools', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100)->unique();
                $t->string('name', 160);
                $t->unsignedBigInteger('capability_id')->nullable();
                $t->unsignedBigInteger('provider_id')->nullable();
                $t->string('status', 30)->default('active');
                $t->string('risk_level', 20)->default('low');
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->index(['capability_id', 'status']);
            });
        }

        if (! Schema::hasTable('farast_tool_versions')) {
            Schema::create('farast_tool_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tool_id');
                $t->string('version', 80);
                $t->string('status', 30)->default('candidate');
                $t->string('evaluation_status', 30)->default('pending');
                $t->string('review_status', 30)->default('pending');
                $t->decimal('quality_score', 6, 3)->nullable();
                $t->json('configuration')->nullable();
                $t->timestamp('last_verified_at')->nullable();
                $t->timestamps();
                $t->unique(['tool_id', 'version']);
            });
        }

        if (! Schema::hasTable('farast_tool_applications')) {
            Schema::create('farast_tool_applications', function (Blueprint $t) {
                $t->unsignedBigInteger('tool_id');
                $t->unsignedBigInteger('application_id');
                $t->primary(['tool_id', 'application_id']);
            });
        }

        if (! Schema::hasTable('farast_entitlements')) {
            Schema::create('farast_entitlements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('project_id')->nullable();
                $t->string('source_type', 40)->default('manual');
                $t->string('source_id', 80)->nullable();
                $t->string('capability_code', 100)->nullable();
                $t->string('status', 30)->default('active');
                $t->unsignedInteger('quantity')->nullable();
                $t->timestamp('starts_at')->nullable();
                $t->timestamp('ends_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_pricing_policies')) {
            Schema::create('farast_pricing_policies', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100)->unique();
                $t->string('capability_code', 100)->nullable();
                $t->string('unit', 40)->default('operation');
                $t->unsignedBigInteger('base_price_rials')->default(0);
                $t->unsignedInteger('subscription_allowance')->default(0);
                $t->unsignedBigInteger('additional_price_rials')->default(0);
                $t->unsignedInteger('payg_multiplier_percent')->default(100);
                $t->unsignedInteger('discount_percent')->default(0);
                $t->unsignedBigInteger('fee_rials')->default(0);
                $t->boolean('active')->default(true);
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_feedback_evidence')) {
            Schema::create('farast_feedback_evidence', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('source_type', 40)->nullable();
                $t->string('source_id', 80)->nullable();
                $t->string('kind', 40)->default('general');
                $t->text('body')->nullable();
                $t->json('payload')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_review_cases')) {
            Schema::create('farast_review_cases', function (Blueprint $t) {
                $t->id();
                $t->string('code', 80)->nullable();
                $t->string('status', 30)->default('open');
                $t->json('payload')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_approved_knowledge')) {
            Schema::create('farast_approved_knowledge', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100)->nullable();
                $t->string('title', 200)->nullable();
                $t->text('body')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_evaluation_datasets')) {
            Schema::create('farast_evaluation_datasets', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100)->nullable();
                $t->string('name', 160)->nullable();
                $t->json('payload')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('farast_evaluation_cases')) {
            Schema::create('farast_evaluation_cases', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('dataset_id')->nullable();
                $t->string('status', 30)->default('pending');
                $t->json('payload')->nullable();
                $t->timestamps();
            });
        }

        $this->seedRegistryIfEmpty();
    }

    private function seedRegistryIfEmpty(): void
    {
        if (! Schema::hasTable('farast_applications')) {
            return;
        }

        if (DB::table('farast_applications')->count() > 0) {
            return;
        }

        $apps = [
            ['code' => 'word_processor', 'name' => 'پردازشگر متن'],
            ['code' => 'reader', 'name' => 'خواننده'],
            ['code' => 'presentation', 'name' => 'ارائه'],
            ['code' => 'design', 'name' => 'طراحی'],
        ];
        foreach ($apps as $app) {
            DB::table('farast_applications')->insert(array_merge($app, [
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $caps = [
            ['code' => 'ai.assist', 'name' => 'دستیار هوش مصنوعی', 'unit' => 'operation'],
            ['code' => 'ocr', 'name' => 'OCR', 'unit' => 'page'],
            ['code' => 'speech', 'name' => 'گفتار به متن', 'unit' => 'minute'],
            ['code' => 'human', 'name' => 'پشتیبانی انسانی', 'unit' => 'ticket'],
        ];
        foreach ($caps as $cap) {
            DB::table('farast_capabilities')->insert(array_merge($cap, [
                'billing_mode' => 'included',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $providers = [
            ['code' => 'internal', 'name' => 'داخلی', 'kind' => 'system'],
            ['code' => 'openai', 'name' => 'OpenAI', 'kind' => 'ai'],
            ['code' => 'groq', 'name' => 'Groq', 'kind' => 'ai'],
        ];
        foreach ($providers as $p) {
            DB::table('farast_providers')->insert(array_merge($p, [
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $capIds = DB::table('farast_capabilities')->pluck('id', 'code');
        $providerIds = DB::table('farast_providers')->pluck('id', 'code');

        $tools = [
            ['code' => 'ai.editor', 'name' => 'ویرایشگر هوشمند', 'capability' => 'ai.assist', 'provider' => 'internal'],
            ['code' => 'ocr.document', 'name' => 'OCR سند', 'capability' => 'ocr', 'provider' => 'internal'],
            ['code' => 'ocr.handwriting', 'name' => 'OCR دست‌نویس', 'capability' => 'ocr', 'provider' => 'internal'],
            ['code' => 'speech.transcription', 'name' => 'رونویسی صوت', 'capability' => 'speech', 'provider' => 'internal'],
            ['code' => 'human.assistance', 'name' => 'پشتیبانی انسانی', 'capability' => 'human', 'provider' => 'internal'],
        ];

        foreach ($tools as $row) {
            $toolId = DB::table('farast_tools')->insertGetId([
                'code' => $row['code'],
                'name' => $row['name'],
                'capability_id' => $capIds[$row['capability']] ?? null,
                'provider_id' => $providerIds[$row['provider']] ?? null,
                'status' => 'active',
                'risk_level' => 'low',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('farast_tool_versions')->insert([
                'tool_id' => $toolId,
                'version' => '1.0.0',
                'status' => $row['code'] === 'human.assistance' ? 'candidate' : 'production',
                'evaluation_status' => $row['code'] === 'human.assistance' ? 'pending' : 'approved',
                'review_status' => $row['code'] === 'human.assistance' ? 'pending' : 'approved',
                'quality_score' => $row['code'] === 'human.assistance' ? null : 1,
                'last_verified_at' => $row['code'] === 'human.assistance' ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $appIds = DB::table('farast_applications')->pluck('id', 'code');
        $toolIds = DB::table('farast_tools')->pluck('id', 'code');
        $links = [
            ['ai.editor', ['word_processor', 'reader', 'presentation', 'design']],
            ['ocr.document', ['word_processor', 'reader']],
            ['ocr.handwriting', ['word_processor', 'reader']],
            ['speech.transcription', ['word_processor']],
            ['human.assistance', ['word_processor', 'reader', 'presentation', 'design']],
        ];
        foreach ($links as [$tool, $appsList]) {
            foreach ($appsList as $app) {
                if (isset($toolIds[$tool], $appIds[$app])) {
                    DB::table('farast_tool_applications')->insert([
                        'tool_id' => $toolIds[$tool],
                        'application_id' => $appIds[$app],
                    ]);
                }
            }
        }

        if (Schema::hasTable('pricing_rules') && Schema::hasTable('farast_pricing_policies')) {
            $unitMap = [
                'typing_manual_page' => 'page',
                'typing_voice_page' => 'page',
                'typing_printed_page' => 'page',
                'typing_handwritten_page' => 'page',
                'typing_mixed_page' => 'page',
                'typing_voice_minute' => 'minute',
                'page_base' => 'page',
                'formula_unit' => 'operation',
                'english_multiplier' => 'page',
                'arabic_multiplier' => 'page',
                'mixed_multiplier' => 'page',
                'dense_page_multiplier' => 'page',
            ];
            foreach (DB::table('pricing_rules')->where('active', true)->get() as $rule) {
                $existing = DB::table('farast_pricing_policies')->where('code', $rule->key)->first();
                if (! $existing) {
                    DB::table('farast_pricing_policies')->insert([
                        'code' => $rule->key,
                        'capability_code' => null,
                        'unit' => $unitMap[$rule->key] ?? 'operation',
                        'base_price_rials' => (int) $rule->value,
                        'subscription_allowance' => 0,
                        'additional_price_rials' => (int) $rule->value,
                        'payg_multiplier_percent' => $rule->key === 'typing_payg_multiplier' ? (int) $rule->value : 100,
                        'discount_percent' => 0,
                        'fee_rials' => 0,
                        'active' => true,
                        'metadata' => json_encode(['legacy_pricing_rule_id' => $rule->id]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'farast_evaluation_cases',
            'farast_evaluation_datasets',
            'farast_approved_knowledge',
            'farast_review_cases',
            'farast_feedback_evidence',
            'farast_pricing_policies',
            'farast_entitlements',
            'farast_tool_applications',
            'farast_tool_versions',
            'farast_tools',
            'farast_providers',
            'farast_capabilities',
            'farast_applications',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
