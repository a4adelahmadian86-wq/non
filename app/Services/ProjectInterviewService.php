<?php

namespace App\Services;

use App\Models\FarastProject;

class ProjectInterviewService
{
    public const TYPES = [
        'typing' => ['label' => 'تایپ', 'icon' => 'fa-keyboard', 'available' => true],
        'editing' => ['label' => 'ویرایش', 'icon' => 'fa-pen-to-square', 'available' => false],
        'article' => ['label' => 'مقاله', 'icon' => 'fa-newspaper', 'available' => false],
        'book' => ['label' => 'کتاب', 'icon' => 'fa-book', 'available' => false],
        'presentation' => ['label' => 'ارائه', 'icon' => 'fa-display', 'available' => false],
        'design' => ['label' => 'پوستر / طراحی', 'icon' => 'fa-palette', 'available' => false],
        'programming' => ['label' => 'برنامه‌نویسی', 'icon' => 'fa-code', 'available' => false],
        'contract' => ['label' => 'قرارداد', 'icon' => 'fa-file-signature', 'available' => false],
        'exam' => ['label' => 'سؤالات امتحان', 'icon' => 'fa-list-check', 'available' => false],
        'forms' => ['label' => 'فرم‌ها', 'icon' => 'fa-clipboard-list', 'available' => false],
        'pricing_sheet' => ['label' => 'برگه قیمت', 'icon' => 'fa-tags', 'available' => false],
        'other' => ['label' => 'سایر', 'icon' => 'fa-layer-group', 'available' => false],
    ];

    public const TEMPLATES = [
        'simple_typing' => [
            'name' => 'تایپ ساده',
            'description' => 'صفحه A4، راست‌به‌چپ و تایپوگرافی استاندارد فارسی.',
            'settings' => [
                'paper' => 'A4','orientation' => 'portrait',
                'margin_top' => 25,'margin_right' => 25,'margin_bottom' => 25,'margin_left' => 25,
                'header_distance' => 12,'footer_distance' => 12,
                'direction' => 'rtl','font_family' => 'B Nazanin','font_size' => 16,
                'line_height' => 1.8,'paragraph_spacing' => 8,'page_border' => true,
            ],
        ],
        'academic_typing' => [
            'name' => 'تایپ دانشگاهی',
            'description' => 'قالب رسمی برای متون دانشگاهی با صفحه‌بندی فارسی.',
            'settings' => [
                'paper' => 'A4','orientation' => 'portrait',
                'margin_top' => 30,'margin_right' => 30,'margin_bottom' => 25,'margin_left' => 25,
                'header_distance' => 12,'footer_distance' => 12,
                'direction' => 'rtl','font_family' => 'B Nazanin','font_size' => 16,
                'line_height' => 1.8,'paragraph_spacing' => 10,'page_border' => false,
            ],
        ],
        'official_document' => [
            'name' => 'سند رسمی',
            'description' => 'قالب رسمی با حاشیه و فاصله‌گذاری متعارف.',
            'settings' => [
                'paper' => 'A4','orientation' => 'portrait',
                'margin_top' => 25,'margin_right' => 25,'margin_bottom' => 25,'margin_left' => 25,
                'header_distance' => 12,'footer_distance' => 12,
                'direction' => 'rtl','font_family' => 'B Nazanin','font_size' => 16,
                'line_height' => 1.8,'paragraph_spacing' => 8,'page_border' => true,
            ],
        ],
    ];

    public function nextQuestion(array $answers): ?array
    {
        if (empty($answers['project_type'])) return ['key'=>'project_type','title'=>'روی چه کاری می‌خواهید کار کنید؟','type'=>'project_type'];
        if ($answers['project_type'] !== 'typing') return null;
        if (empty($answers['template'])) return ['key'=>'template','title'=>'قالب سند را انتخاب کنید','type'=>'template'];
        if (empty($answers['workflow'])) return ['key'=>'workflow','title'=>'چطور می‌خواهید تایپ کنید؟','type'=>'typing_mode'];
        if ($answers['workflow'] === 'source_file' && empty($answers['source_type'])) return ['key'=>'source_type','title'=>'منبع شما چه نوعی است؟','type'=>'source_type'];
        if ($answers['workflow'] === 'voice' && empty($answers['language'])) return ['key'=>'language','title'=>'زبان گفتار چیست؟','type'=>'language'];
        return null;
    }

    public function buildContext(array $answers, array $userPreferences = []): array
    {
        $template = self::TEMPLATES[$answers['template'] ?? 'simple_typing'] ?? self::TEMPLATES['simple_typing'];
        $settings = $template['settings'];
        $context = [
            'project_type' => $answers['project_type'] ?? 'typing',
            'workflow' => $answers['workflow'] ?? 'manual',
            'template' => $answers['template'] ?? 'simple_typing',
            'language' => $answers['language'] ?? 'fa',
            'direction' => $settings['direction'],
            'source_type' => $answers['source_type'] ?? null,
            'estimated_pages' => max(1, (int) ($answers['estimated_pages'] ?? 1)),
            'input_format' => $answers['input_format'] ?? null,
            'required_tools' => array_values($answers['required_tools'] ?? []),
            'special_requirements' => $answers['special_requirements'] ?? null,
            'template_settings' => $settings,
            'user_preferences_snapshot' => array_intersect_key($userPreferences, array_flip(['language','direction','font_family','font_size'])),
        ];
        return $context;
    }
}
