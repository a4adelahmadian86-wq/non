<?php

namespace TestsFeature;

use AppModelsFarastDocument;
use AppModelsTypingDocument;
use AppModelsUser;
use AppServicesEditorKernelToolHandler;
use IlluminateFoundationTestingRefreshDatabase;
use TestsTestCase;

class VoiceKernelRebaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_rebases_a_stale_anchor_without_reloading_or_overwriting_concurrent_text(): void
    {
        $user = User::factory()->create();

        $model = [
            'schema' => 3,
            'type' => 'document',
            'direction' => 'rtl',
            'sections' => [[
                'id' => 'section-1',
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'paragraph',
                    'runs' => [['id' => 'r1', 'text' => 'سلام دنیا']],
                ]],
            ]],
            'comments' => [],
            'reviewChanges' => [],
            'bookmarks' => [],
            'resources' => [],
            'fields' => [],
            'plain_text' => 'سلام دنیا',
        ];

        $legacy = TypingDocument::create([
            'user_id' => $user->id,
            'title' => 'Voice rebase',
            'content' => '<p>سلام دنیا</p>',
            'status' => 'draft',
        ]);

        $document = FarastDocument::create([
            'user_id' => $user->id,
            'title' => 'Voice rebase',
            'content' => '<p>سلام دنیا</p>',
            'content_json' => $model,
            'document_format' => 'farast-v1',
            'page_settings' => app(AppServicesEditorDocumentService::class)->defaultPageSettings(),
            'revision' => 1,
            'status' => 'active',
        ]);
        $legacy->update(['farast_document_id' => $document->id]);

        $concurrent = $model;
        $concurrent['sections'][0]['blocks'][0]['runs'][0]['text'] = 'سلام عزیز دنیا';
        $concurrent['plain_text'] = 'سلام عزیز دنیا';
        $document->update([
            'content' => '<p>سلام عزیز دنیا</p>',
            'content_json' => $concurrent,
            'revision' => 2,
        ]);

        $result = app(EditorKernelToolHandler::class)->handle($user, [
            'document_id' => $document->id,
            'base_revision' => 1,
            'command' => [
                'name' => 'InsertText',
                'input' => [
                    'text' => ' فراست',
                    'range' => [
                        'start' => ['blockId' => 'b1', 'offset' => 10],
                        'end' => ['blockId' => 'b1', 'offset' => 10],
                    ],
                    'anchor' => [
                        'blockId' => 'b1',
                        'offset' => 10,
                        'before' => 'سلام ',
                        'after' => 'دنیا',
                    ],
                ],
            ],
        ], ['source' => 'voice']);

        $fresh = $document->fresh();

        $this->assertSame(3, $result['revision']);
        $this->assertSame('سلام عزیز دنیا فراست', $fresh->content_json['plain_text']);
        $this->assertSame('سلام عزیز دنیا فراست', $fresh->content_json['sections'][0]['blocks'][0]['runs'][0]['text']);
        $this->assertNotNull($result['document_model']);
    }
}
