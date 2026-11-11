<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Schema;
use Tests\\TestCase;

class FarastFeedbackEvidenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_evidence_schema_matches_the_pipeline_contract(): void
    {
        $table = 'farast_feedback_evidence';

        $this->assertTrue(Schema::hasTable($table));

        foreach ([
            'feedback_id',
            'user_id',
            'project_id',
            'source',
            'tool_id',
            'tool_version_id',
            'evidence_type',
            'input_meta',
            'output_meta',
            'confidence',
            'frequency',
            'independent_confirmations',
            'status',
            'reviewer_notes',
            'checksum',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "The {$table}.{$column} column is required by the feedback evidence pipeline."
            );
        }

        $this->assertTrue(Schema::hasIndex($table, ['project_id', 'status']));
        $this->assertTrue(Schema::hasIndex($table, ['tool_id', 'tool_version_id', 'status']));
        $this->assertTrue(Schema::hasIndex($table, ['feedback_id', 'status']));
    }
}
