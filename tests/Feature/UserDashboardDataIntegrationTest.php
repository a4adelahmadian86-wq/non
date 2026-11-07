<?php

namespace Tests\Feature;

use App\Models\FarastDocument;
use App\Models\FarastProject;
use App\Models\Ticket;
use App\Models\TypingDocument;
use App\Models\User;
use App\Models\Wallet;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardDataIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_connects_owned_documents_projects_wallet_tickets_and_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $linkedWorkspaceDocument = FarastDocument::create([
            'user_id' => $user->id,
            'title' => 'Linked canonical document',
            'content' => '<p>owned</p>',
            'status' => 'active',
            'is_favorite' => false,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            TypingDocument::create([
                'user_id' => $user->id,
                'title' => 'Editor document '.$i,
                'content' => '',
                'page_count' => 1,
                'word_count' => 0,
                'status' => 'draft',
                'price_rials' => 0,
            ]);
        }

        $editorDocument = TypingDocument::create([
            'user_id' => $user->id,
            'farast_document_id' => $linkedWorkspaceDocument->id,
            'title' => 'My editor document',
            'content' => '',
            'page_count' => 3,
            'word_count' => 20,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        $workspaceOnlyDocument = FarastDocument::create([
            'user_id' => $user->id,
            'title' => 'Workspace-only document',
            'content' => '<p>workspace</p>',
            'status' => 'active',
            'is_favorite' => true,
        ]);

        TypingDocument::create([
            'user_id' => $otherUser->id,
            'title' => 'Another user private document',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        FarastProject::create([
            'user_id' => $user->id,
            'name' => 'My active project',
            'project_type' => 'document',
            'status' => 'active',
            'estimated_pages' => 12,
            'used_pages' => 4,
        ]);

        Wallet::create(['user_id' => $user->id, 'balance_rials' => 125000]);
        Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Need help',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        app(NotificationService::class)->push($user->id, 'ticket_reply', 'پاسخ جدید', 'پیام پشتیبانی');

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertViewHas('documents', function ($documents) {
            return $documents->perPage() === 8 && $documents->total() === 11;
        });
        $response->assertViewHas('workspaceDocuments', function ($documents) use ($workspaceOnlyDocument, $linkedWorkspaceDocument) {
            return $documents->pluck('id')->all() === [$workspaceOnlyDocument->id]
                && ! $documents->contains('id', $linkedWorkspaceDocument->id);
        });
        $response->assertViewHas('documentTotal', 12);
        $response->assertViewHas('projectTotal', 1);
        $response->assertViewHas('openTicketCount', 1);
        $response->assertViewHas('walletBalance', 125000);
        $response->assertViewHas('unreadNotificationCount', 1);

        $response->assertSee(route('editor', ['document' => $editorDocument->id]), false)
            ->assertSee(route('platform.documents'), false)
            ->assertSee('My editor document')
            ->assertSee('Workspace-only document')
            ->assertDontSee('Another user private document');
    }

    public function test_dashboard_document_pagination_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            TypingDocument::create([
                'user_id' => $user->id,
                'title' => 'Owned document '.$i,
                'content' => '',
                'page_count' => 1,
                'word_count' => 0,
                'status' => 'draft',
                'price_rials' => 0,
            ]);
        }

        TypingDocument::create([
            'user_id' => $otherUser->id,
            'title' => 'Private foreign document',
            'content' => '',
            'page_count' => 1,
            'word_count' => 0,
            'status' => 'draft',
            'price_rials' => 0,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->total() === 10 && $documents->count() === 8)
            ->assertDontSee('Private foreign document');
    }
}
