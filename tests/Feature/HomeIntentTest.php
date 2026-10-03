<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProjectInterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeIntentTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = new User();
        $user->name = 'Homepage QA';
        $user->email = 'home-'.uniqid().'@example.test';
        $user->mobile = '09'.str_pad((string) random_int(100000000, 999999999), 9, '0');
        $user->password = bcrypt('secret123');
        $user->save();

        return $user;
    }

    public function test_homepage_is_renderable_and_exposes_three_primary_intents(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('چه کاری می‌خواهید انجام دهید؟')
            ->assertSee('فایل دارم')
            ->assertSee('از صفر شروع می‌کنم')
            ->assertSee('دنبال یک سرویس هستم')
            ->assertSee('هنوز مطمئن نیستم');
    }

    public function test_word_processor_project_intents_classify_and_suggest_templates(): void
    {
        $interview = app(ProjectInterviewService::class);

        $article = $interview->buildContext([
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template' => 'academic_typing',
        ]);

        $this->assertSame('word_processor', $article['application']);
        $this->assertSame('academic_typing', $article['template']);

        $handwritten = $interview->buildContext([
            'project_type' => 'typing',
            'workflow' => 'source_file',
            'source_type' => 'handwritten',
        ]);

        $this->assertSame('word_processor', $handwritten['application']);
        $this->assertSame('source_file', $handwritten['workflow']);
        $this->assertContains('ocr', $handwritten['required_capabilities']);
        $this->assertContains('handwriting.ocr', $handwritten['required_capabilities']);
    }

    public function test_authenticated_project_creation_routes_to_word_processor(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/projects', [
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template' => 'official_document',
            'language' => 'fa',
            'estimated_pages' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('context.application', 'word_processor')
            ->assertJsonPath('context.workflow', 'manual')
            ->assertJsonPath('context.template', 'official_document');

        $this->assertDatabaseHas('farast_projects', [
            'id' => $response->json('project_id'),
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template_code' => 'official_document',
        ]);
    }

    public function test_progressive_interview_does_not_require_unrelated_answers(): void
    {
        $interview = app(ProjectInterviewService::class);

        $this->assertSame('workflow', $interview->nextQuestion(['project_type' => 'typing'])['key']);
        $this->assertSame('source_type', $interview->nextQuestion([
            'project_type' => 'typing',
            'workflow' => 'source_file',
        ])['key']);
        $this->assertSame('template', $interview->nextQuestion([
            'project_type' => 'typing',
            'workflow' => 'manual',
        ])['key']);
        $this->assertNull($interview->nextQuestion([
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template' => 'simple_typing',
        ]));
    }
}
