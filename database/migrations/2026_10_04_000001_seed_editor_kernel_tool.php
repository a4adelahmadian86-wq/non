<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('farast_tools') || !Schema::hasTable('farast_tool_versions')) {
            return;
        }

        $capabilityId = Schema::hasTable('farast_capabilities')
            ? DB::table('farast_capabilities')->where('code', 'document.editing')->value('id')
            : null;
        $providerId = Schema::hasTable('farast_providers')
            ? DB::table('farast_providers')->where('code', 'farast_core')->value('id')
            : null;

        $tool = DB::table('farast_tools')->where('code', 'editor.kernel')->first();
        $payload = [
            'name' => 'FARAST Editor Kernel',
            'capability_id' => $capabilityId,
            'provider_id' => $providerId,
            'status' => 'active',
            'risk_level' => 'medium',
            'input_schema' => json_encode([
                'type' => 'object',
                'required' => ['document_id', 'base_revision', 'command'],
                'properties' => [
                    'document_id' => ['type' => 'integer'],
                    'base_revision' => ['type' => 'integer'],
                    'command' => ['type' => 'object'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'output_schema' => json_encode([
                'type' => 'object',
                'required' => ['document_id', 'revision', 'transaction_id', 'command', 'effects'],
            ], JSON_UNESCAPED_UNICODE),
            'permissions' => json_encode(['authenticated' => true], JSON_UNESCAPED_UNICODE),
            'entitlement_policy' => json_encode(['capability' => 'document.editing', 'unit' => 'operation'], JSON_UNESCAPED_UNICODE),
            'usage_meter' => 'operation',
            'reversible' => true,
            'transactional' => true,
            'audit_enabled' => true,
            'timeout_seconds' => 120,
            'retry_count' => 0,
            'provenance' => json_encode(['source' => 'farast-editor-kernel', 'contract_version' => '1.0.0'], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];

        if ($tool) {
            DB::table('farast_tools')->where('id', $tool->id)->update($payload);
            $toolId = (int) $tool->id;
        } else {
            $toolId = DB::table('farast_tools')->insertGetId(array_merge($payload, [
                'code' => 'editor.kernel',
                'created_at' => now(),
            ]));
        }

        $version = DB::table('farast_tool_versions')
            ->where('tool_id', $toolId)
            ->where('version', '1.0.0')
            ->first();

        $versionId = $version?->id;
        if ($version) {
            DB::table('farast_tool_versions')->where('id', $version->id)->update([
                'status' => 'production',
                'evaluation_status' => 'approved',
                'review_status' => 'approved',
                'quality_score' => 1,
                'last_verified_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $versionId = DB::table('farast_tool_versions')->insertGetId([
                'tool_id' => $toolId,
                'version' => '1.0.0',
                'status' => 'production',
                'evaluation_status' => 'approved',
                'review_status' => 'approved',
                'quality_score' => 1,
                'configuration' => json_encode(['handler' => 'editor.kernel'], JSON_UNESCAPED_UNICODE),
                'last_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('farast_tool_applications') && Schema::hasTable('farast_applications')) {
            $applicationId = DB::table('farast_applications')->where('code', 'word_processor')->value('id');
            if ($applicationId) {
                DB::table('farast_tool_applications')->updateOrInsert(
                    ['tool_id' => $toolId, 'application_id' => $applicationId],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('farast_tools')) {
            return;
        }

        $toolId = DB::table('farast_tools')->where('code', 'editor.kernel')->value('id');
        if (!$toolId) {
            return;
        }

        if (Schema::hasTable('farast_tool_applications')) {
            DB::table('farast_tool_applications')->where('tool_id', $toolId)->delete();
        }
        if (Schema::hasTable('farast_tool_versions')) {
            DB::table('farast_tool_versions')->where('tool_id', $toolId)->delete();
        }
        DB::table('farast_tools')->where('id', $toolId)->delete();
    }
};
