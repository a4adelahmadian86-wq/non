<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $source = 'farast_subscriptions';
        $staging = 'farast_subscriptions_phase_20261003_staging';

        // A failed attempt after dropping the source table can resume safely
        // from the verified staging copy instead of silently returning.
        if (! Schema::hasTable($source)) {
            if (Schema::hasTable($staging)) {
                Schema::rename($staging, $source);
            }

            return;
        }

        // The source remains authoritative until the complete copy is verified.
        // A leftover staging table from an earlier failed attempt is disposable.
        Schema::dropIfExists($staging);

        if (! Schema::hasTable('farast_commercial_plans')) {
            throw new RuntimeException('Cannot canonicalize subscriptions: farast_commercial_plans is missing; source rows were preserved.');
        }

        $columns = Schema::getColumnListing($source);
        $rows = DB::table($source)->orderBy('id')->get();
        $normalized = [];
        $knownColumns = [
            'id', 'subscription_id', 'user_id', 'organization_id', 'plan_id',
            'status', 'starts_at', 'ends_at', 'cancelled_at', 'metadata',
            'created_at', 'updated_at',
        ];

        foreach ($rows as $row) {
            $planId = $row->plan_id ?? null;
            if (! $planId || ! DB::table('farast_commercial_plans')->where('id', $planId)->exists()) {
                $planCode = in_array('plan_code', $columns, true) ? trim((string) ($row->plan_code ?? '')) : '';
                $planId = $planCode !== ''
                    ? DB::table('farast_commercial_plans')->where('code', $planCode)->value('id')
                    : null;
            }

            if (! $planId) {
                throw new RuntimeException(
                    'Cannot canonicalize subscriptions: legacy subscription row '.$row->id
                    .' has no resolvable commercial plan. The original table was preserved; reconcile this row before retrying.'
                );
            }

            $subscriptionId = trim((string) ($row->subscription_id ?? ''));
            if ($subscriptionId === '') {
                $subscriptionId = (string) Str::uuid();
            }

            $metadata = $row->metadata ?? null;
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : ($metadata !== '' ? ['legacy_metadata_raw' => $metadata] : []);
            } elseif (! is_array($metadata)) {
                $metadata = [];
            }

            $legacyFields = [];
            foreach (get_object_vars($row) as $field => $value) {
                if (! in_array($field, $knownColumns, true)) {
                    $legacyFields[$field] = $value;
                }
            }
            if ($legacyFields !== []) {
                $existingLegacyFields = is_array($metadata['legacy_fields'] ?? null) ? $metadata['legacy_fields'] : [];
                $metadata['legacy_fields'] = array_merge($existingLegacyFields, $legacyFields);
            }
            if (! isset($metadata['legacy_id'])) {
                $metadata['legacy_id'] = $row->id;
            }

            $normalized[] = [
                'subscription_id' => $subscriptionId,
                'user_id' => $row->user_id ?? null,
                'organization_id' => $row->organization_id ?? null,
                'plan_id' => $planId,
                'status' => (string) ($row->status ?? 'active'),
                'starts_at' => $row->starts_at ?? now(),
                'ends_at' => $row->ends_at ?? null,
                'cancelled_at' => $row->cancelled_at ?? null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ];
        }

        Schema::create($staging, function (Blueprint $table): void {
            $table->id();
            // Keep the historical string contract; do not narrow identifiers
            // that may already be consumed by integrations.
            $table->string('subscription_id', 80)->unique('farast_subs_stg_subscription_id_unique');
            $table->unsignedBigInteger('user_id')->nullable()->index('farast_subs_stg_user_id_index');
            $table->unsignedBigInteger('organization_id')->nullable()->index('farast_subs_stg_org_id_index');
            $table->unsignedBigInteger('plan_id')->index('farast_subs_stg_plan_id_index');
            $table->string('status', 30)->default('active')->index('farast_subs_stg_status_id_index');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        foreach (array_chunk($normalized, 50) as $chunk) {
            if ($chunk !== []) {
                DB::table($staging)->insert($chunk);
            }
        }

        $stagedCount = DB::table($staging)->count();
        if ($stagedCount !== $rows->count()) {
            throw new RuntimeException(
                "Subscription canonicalization verification failed: expected {$rows->count()} rows, copied {$stagedCount}. The original table was preserved."
            );
        }

        $orphans = DB::table($staging.' as child')
            ->leftJoin('farast_commercial_plans as parent', 'child.plan_id', '=', 'parent.id')
            ->whereNull('parent.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Subscription canonicalization verification failed: {$orphans} unresolved plan reference(s). The original table was preserved."
            );
        }

        // Drop the source only after the complete, validated copy exists.
        Schema::drop($source);
        Schema::rename($staging, $source);
    }

    public function down(): void
    {
        // Keep canonical subscription history intact on rollback.
    }
};
