<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeedbackTrustSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_trust_schema_contains_the_columns_used_by_services(): void
    {
        $expected = [
            'farast_feedback_evidence' => [
                'feedback_id', 'user_id', 'project_id', 'source', 'tool_id', 'tool_version_id',
                'evidence_type', 'input_meta', 'output_meta', 'confidence', 'frequency',
                'independent_confirmations', 'status', 'reviewer_notes', 'checksum',
            ],
            'farast_review_cases' => ['evidence_id', 'reviewer_id', 'decision', 'notes', 'reviewed_at', 'status'],
            'farast_approved_knowledge' => [
                'review_case_id', 'capability_code', 'knowledge_version', 'content',
                'status', 'approved_by', 'approved_at', 'checksum',
            ],
            'farast_evaluation_datasets' => ['version', 'status', 'metadata'],
            'farast_evaluation_cases' => [
                'dataset_id', 'tool_id', 'tool_version_id', 'input_payload', 'expected_payload', 'status',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist.");
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Expected {$table}.{$column} to exist."
                );
            }
        }
    }

    public function test_review_and_approved_knowledge_references_are_retained_as_nullable_links(): void
    {
        $evidenceId = DB::table('farast_feedback_evidence')->insertGetId([
            'user_id' => null,
            'source_type' => 'test',
            'source_id' => 'schema-integrity',
            'kind' => 'general',
            'body' => 'evidence',
            'payload' => json_encode(['test' => true]),
            'feedback_id' => null,
            'project_id' => null,
            'source' => 'test',
            'tool_id' => null,
            'tool_version_id' => null,
            'evidence_type' => 'test',
            'input_meta' => null,
            'output_meta' => null,
            'confidence' => null,
            'frequency' => 1,
            'independent_confirmations' => 0,
            'status' => 'raw',
            'reviewer_notes' => null,
            'checksum' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reviewCaseId = DB::table('farast_review_cases')->insertGetId([
            'code' => 'schema-review-'.$evidenceId,
            'status' => 'open',
            'payload' => json_encode([]),
            'evidence_id' => $evidenceId,
            'reviewer_id' => null,
            'decision' => null,
            'notes' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $knowledgeId = DB::table('farast_approved_knowledge')->insertGetId([
            'code' => 'schema-knowledge-'.$evidenceId,
            'title' => 'Schema integrity test',
            'body' => null,
            'metadata' => json_encode([]),
            'review_case_id' => $reviewCaseId,
            'capability_code' => 'schema.test',
            'knowledge_version' => '1.0.0',
            'content' => json_encode(['safe' => true]),
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
            'checksum' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('farast_feedback_evidence')->where('id', $evidenceId)->delete();
        $this->assertDatabaseHas('farast_review_cases', [
            'id' => $reviewCaseId,
            'evidence_id' => null,
        ]);

        DB::table('farast_review_cases')->where('id', $reviewCaseId)->delete();
        $this->assertDatabaseHas('farast_approved_knowledge', [
            'id' => $knowledgeId,
            'review_case_id' => null,
        ]);
    }
}
