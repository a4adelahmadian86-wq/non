<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('farast_tools') || !Schema::hasTable('farast_tool_versions')) return;

        $capabilityId = DB::table('farast_capabilities')->where('code', 'document.editing')->value('id');
        if (!$capabilityId) {
            $capabilityId = DB::table('farast_capabilities')->insertGetId([
                'code' => 'document.editing',
                'name' => 'ویرایش سند',
                'billing_mode' => 'included',
                'unit' => 'transaction',
                'status' => 'active',
                'metadata' => json_encode(['source' => 'editor-kernel'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $toolId = DB::table('farast_tools')->where('code', 'editor.kernel')->value('id');
        $toolData = [
            'name' => 'FARAST Editor Kernel',
            'capability_id' => $capabilityId,
            'status' => 'active',
            'risk_level' => 'medium',
            'metadata' => json_encode(['purpose' => 'server_authoritative_document_mutation'], JSON_UNESCAPED_UNICODE),
            'stable_id' => (string) Str::uuid(),
            'input_schema' => json_encode([
                'type' => 'object',
                'required' => ['document_id', 'base_revision', 'command'],
                'properties' => [
                    'document_id' => ['type' => 'integer'],
                    'base_revision' => ['type' => 'integer', 'minimum' => 0],
                    'command' => ['type' => 'object'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'output_schema' => json_encode(['type' => 'object', 'required' => ['document_id', 'revision', 'transaction_id']], JSON_UNESCAPED_UNICODE),
            'permissions' => json_encode(['authenticated' => true, 'permission' => 'documents.edit']),
            'entitlement_policy' => json_encode(['capability' => 'document.editing', 'unit' => 'transaction']),
            'usage_meter' => 'transaction',
            'reversible' => true, 'transactional' => true, 'audit_enabled' => true,
            'timeout_seconds' => 30, 'retry_count' => 0,
            'provenance' => json_encode(['source' => 'editor-kernel', 'contract_version' => '1.0.0'], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];

        if (!$toolId) {
            $toolId = DB::table('farast_tools')->insertGetId(array_merge($toolData, ['code' => 'editor.kernel', 'created_at' => now()]));
        } else {
            DB::table('farast_tools')->where('id', $toolId)->update($toolData);
        }

        if (!DB::table('farast_tool_versions')->where('tool_id', $toolId)->where('version', '1.0.0')->exists()) {
            DB::table('farast_tool_versions')->insert([
                'tool_id' => $toolId, 'version' => '1.0.0', 'status' => 'production',
                'evaluation_status' => 'approved', 'review_status' => 'approved', 'quality_score' => 1,
                'configuration' => json_encode(['server_authoritative' => true], JSON_UNESCAPED_UNICODE),
                'last_verified_at' => now(), 'stable_id' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $applicationId = DB::table('farast_applications')->where('code', 'word_processor')->value('id');
        if ($applicationId && !DB::table('farast_tool_applications')->where('tool_id', $toolId)->where('application_id', $applicationId)->exists()) {
            DB::table('farast_tool_applications')->insert([
                'tool_id' => $toolId, 'application_id' => $applicationId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $toolId = DB::table('farast_tools')->where('code', 'editor.kernel')->value('id');
        if (!$toolId) return;
        DB::table('farast_tool_applications')->where('tool_id', $toolId)->delete();
        DB::table('farast_tool_versions')->where('tool_id', $toolId)->delete();
        DB::table('farast_tools')->where('id', $toolId)->delete();
    }
};
