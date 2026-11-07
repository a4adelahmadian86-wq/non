<?php

namespace Tests\Feature;

use App\Models\FarastDocument;
use App\Models\TypingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAnalyticsDocumentMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_counts_canonical_documents_and_unlinked_legacy_documents_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create();

        $linked = FarastDocument::create([
            'user_id' => $owner->id,
            'title' => 'Canonical linked document',
            'content' => '',
            'status' => 'active',
            'is_favorite' => false,
        ]);

        TypingDocument::create([
            'user_id' => $owner->id,
            'farast_document_id' => $linked->id,
            'title' => 'Linked editor adapter',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            FarastDocument::create([
                'user_id' => $owner->id,
                'title' => 'Workspace document '.$i,
                'content' => '',
                'status' => 'active',
                'is_favorite' => false,
            ]);

            TypingDocument::create([
                'user_id' => $owner->id,
                'title' => 'Legacy-only document '.$i,
                'content' => '',
                'page_count' => 1,
                'word_count' => 0,
                'status' => 'draft',
                'price_rials' => 0,
            ]);
        }

        $trashed = FarastDocument::create([
            'user_id' => $owner->id,
            'title' => 'Trashed workspace document',
            'content' => '',
            'status' => 'trashed',
            'trashed_at' => now(),
            'is_favorite' => false,
        ]);

        TypingDocument::create([
            'user_id' => $owner->id,
            'farast_document_id' => $trashed->id,
            'title' => 'Trashed editor adapter',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['documents'] === 5);
    }
}
