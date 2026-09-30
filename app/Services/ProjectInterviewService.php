<?php

namespace App\Services;

class ProjectInterviewService
{
    public function __construct(private ApplicationRegistry $applications) {}
    public const TYPES = [
        'typing' => ['label' => 'تایپ', 'icon' => 'fa-keyboard', 'available' => true],
        'editing' => ['label' => 'ویرایش', 'icon' => 'fa-pen-to-square', 'available' => false],
        'article' => ['label' => 'مقاله', 'icon' => 'fa-newspaper', 'available' => false],
        'book' => ['label' => 'کتاب', 'icon' => 'fa-book', 'available' => false],
        'resume' => ['label' => 'رزومه', 'icon' => 'fa-id-card', 'available' => false],
        'exam' => ['label' => 'سؤالات امتحان', 'icon' => 'fa-list-check', 'available' => false],
        'contract' => ['label' => 'قرارداد', 'icon' => 'fa-file-signature', 'available' => false],
        'forms' => ['label' => 'فرم‌ها', 'icon' => 'fa-clipboard-list', 'available' => false],
        'presentation' => ['label' => 'ارائه', 'icon' => 'fa-display', 'available' => false],
        'design' => ['label' => 'طراحی', 'icon' => 'fa-palette', 'available' => false],
        'poster_card' => ['label' => 'پوستر / کارت', 'icon' => 'fa-address-card', 'available' => false],
        'programming' => ['label' => 'برنامه‌نویسی', 'icon' => 'fa-code', 'available' => false],
        'other' => ['label' => 'سایر', 'icon' => 'fa-layer-group', 'available' => false],
    ];

    public const TEMPLATES = TemplateRegistry::TEMPLATES;

    public function nextQuestion(array $answers): ?array
    {
        if (empty($answers['project_type'])) return ['key'=>'project_type','title'=>'روی چه کاری می‌خواهید کار کنید؟','type'=>'project_type'];
        if ($answers['project_type'] !== 'typing') return null;
        if (empty($answers['workflow'])) return ['key'=>'workflow','title'=>'چطور می‌خواهید سند را ایجاد کنید؟','type'=>'typing_mode'];
        if ($answers['workflow'] === 'source_file' && empty($answers['source_type'])) return ['key'=>'source_type','title'=>'منبع شما چه نوعی است؟','type'=>'source_type'];
        if ($answers['workflow'] === 'voice' && empty($answers['language'])) return ['key'=>'language','title'=>'زبان گفتار چیست؟','type'=>'language'];
        if (empty($answers['template'])) return ['key'=>'template','title'=>'قالب سند را انتخاب کنید','type'=>'template'];
        return null;
    }

    public function buildContext(array $answers, array $userPreferences = []): array
    {
        $template = (new TemplateRegistry())->get($answers['template'] ?? 'simple_typing');
        $settings = $template['settings'];
        $workflow = $answers['workflow'] ?? 'manual';
        $required = ['document.editing'];
        if ($workflow === 'voice') $required[] = 'speech.transcription';
        if ($workflow === 'source_file') {
            $required[] = 'ocr';
            if (($answers['source_type'] ?? '') === 'handwritten' || ($answers['source_type'] ?? '') === 'mixed') $required[] = 'handwriting.ocr';
        }

        $classification = $this->applications->classify($answers['project_type'] ?? 'typing');
        if (!$classification['available']) {
            throw new \InvalidArgumentException('این نوع پروژه هنوز قابل اجرا نیست.');
        }

        return [
            'project_type' => $answers['project_type'] ?? 'typing',
            'application' => $classification['application'],
            'editor_type' => 'word_processor',
            'workflow' => $workflow,
            'template' => $answers['template'] ?? 'simple_typing',
            'language' => $answers['language'] ?? 'fa',
            'direction' => $settings['direction'],
            'source_type' => $answers['source_type'] ?? null,
            'estimated_pages' => max(1, (int) ($answers['estimated_pages'] ?? 1)),
            'input_format' => $answers['input_format'] ?? null,
            'required_tools' => array_values($answers['required_tools'] ?? []),
            'required_capabilities' => array_values(array_unique($required)),
            'requirements' => ['special' => $answers['special_requirements'] ?? null],
            'special_requirements' => $answers['special_requirements'] ?? null,
            'billing_state' => ['mode' => $workflow === 'manual' ? 'included' : 'usage'],
            'usage_state' => ['estimated_pages' => max(1, (int) ($answers['estimated_pages'] ?? 1))],
            'audit_state' => ['created_from' => 'project_interview'],
            'output_state' => ['status' => 'draft'],
            'template_settings' => $settings,
            'user_preferences_snapshot' => array_intersect_key($userPreferences, array_flip(['language','direction','font_family','font_size'])),
        ];
    }
}
