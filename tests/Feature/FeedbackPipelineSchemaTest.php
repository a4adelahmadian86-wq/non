<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\User;
use App\Services\FeedbackPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeedbackPipelineSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_review_knowledge_and_evaluation_schema_matches_service_writes(): void
    {
        $requiredColumns = [
            'farast_feedback_evidence' => [
                'feedback_id', 'user_id', 'project_id', 'source', 'tool_id',
                'tool_version_id', 'evidence_type', 'input_meta', 'output_meta',
                'confidence', 'frequency', 'independent_confirmations',
                'status', 'reviewer_notes', 'checksum',
            ],
            'farast_review_cases' => [
                'evidence_id', 'reviewer_id', 'decision', 'reviewed_at', 'notes',
            ],
            'farast_approved_knowledge' => [
                'review_case_id', 'capability_code', 'knowledge_version', 'content',
                'status', 'approved_by', 'approved_at', 'checksum',
            ],
            'farast_evaluation_datasets' => ['version', 'status', 'metadata'],
            'farast_evaluation_cases' => [
                'dataset_id', 'tool_id', 'tool_version_id', 'input_payload',
                'expected_payload', 'status',
            ],
        ];

        foreach ($requiredColumns as $table => $columns) {
            $actual = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $actual,
                    "Missing schema column [{$table}.{$column}]."
                );
            }
        }

        $reviewer = User::factory()->create();
        $feedback = AiFeedback::create([
            'user_id' => $reviewer->id,
            'type' => 'rating',
            'rating' => 5,
            'category' => 'quality',
            'note' => 'schema contract',
        ]);

        $service = app(FeedbackPipelineService::class);
        $evidence = $service->record(
            $feedback,
            'ai.editor',
            '1.0.0',
            ['prompt' => 'sample input'],
            ['response' => 'sample output']
        );

        $this->assertDatabaseHas('farast_feedback_evidence', [
            'id' => $evidence->id,
            'feedback_id' => $feedback->id,
            'status' => 'raw',
            'frequency' => 1,
        ]);

        $approvedReviewId = $service->queueReview($evidence);
        $service->approve(
            $approvedReviewId,
            $reviewer->id,
            'ai.assist',
            ['answer' => 'approved knowledge']
        );

        $this->assertDatabaseHas('farast_review_cases', [
            'id' => $approvedReviewId,
            'evidence_id' => $evidence->id,
            'reviewer_id' => $reviewer->id,
            'decision' => 'approved',
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('farast_approved_knowledge', [
            'review_case_id' => $approvedReviewId,
            'capability_code' => 'ai.assist',
            'knowledge_version' => '1.0.0',
            'status' => 'approved',
            'approved_by' => $reviewer->id,
        ]);

        $rejectedReviewId = $service->queueReview($evidence);
        $service->reject($rejectedReviewId, $reviewer->id, 'not suitable');
        $this->assertDatabaseHas('farast_review_cases', [
            'id' => $rejectedReviewId,
            'decision' => 'rejected',
            'status' => 'rejected',
            'notes' => 'not suitable',
        ]);

        $evaluationCaseId = $service->createEvaluationCase(
            'feedback-schema-contract',
            ['input' => 'sample'],
            ['expected' => 'sample']
        );
        $this->assertDatabaseHas('farast_evaluation_cases', [
            'id' => $evaluationCaseId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('farast_evaluation_datasets', [
            'code' => 'feedback-schema-contract',
            'version' => '1.0.0',
            'status' => 'draft',
        ]);

        $toolVersionId = DB::table('farast_tool_versions')
            ->where('version', '1.0.0')
            ->whereIn('tool_id', DB::table('farast_tools')->select('id')->where('code', 'ai.editor'))
            ->value('id');

        $this->assertNotNull($toolVersionId);
        $service->validateToolVersion((int) $toolVersionId, 0.9);
        $service->promoteToolVersion((int) $toolVersionId);

        $this->assertDatabaseHas('farast_tool_versions', [
            'id' => $toolVersionId,
            'status' => 'production',
            'evaluation_status' => 'approved',
        ]);
        $this->assertSame(
            ['answer' => 'approved knowledge'],
            $service->productionKnowledge('ai.assist')[0]
        );
    }
}
