<?php

namespace Tests\Feature;

use Tests\TestCase;

class EditorRuntimeContractTest extends TestCase
{
    public function test_editor_core_is_a_real_local_kernel_not_a_runtime_loader(): void
    {
        $core = file_get_contents(base_path('public/js/editor-core.js'));

        $this->assertIsString($core);
        $this->assertGreaterThan(50000, strlen($core));
        $this->assertStringNotContainsString('jsDelivr', $core);
        $this->assertStringNotContainsString('raw.githubusercontent.com', $core);
        $this->assertStringNotContainsString('PLACEHOLDER', $core);
        $this->assertStringNotContainsString('eval(', $core);
        $this->assertStringContainsString('window.FarastEditor=', $core);
        $this->assertStringContainsString('const transaction=', $core);
        $this->assertStringContainsString("bind('InsertText'", $core);
        $this->assertStringContainsString("bind('voice'", $core);
        $this->assertStringNotContainsString('document.execCommand', $core);
        $this->assertStringNotContainsString('window.find', $core);
    }

    public function test_editor_has_one_authoritative_ribbon_and_no_secondary_editor_runtime(): void
    {
        $blade = file_get_contents(base_path('resources/views/editor.blade.php'));
        $layout = file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $core = file_get_contents(base_path('public/js/editor-core.js'));

        $this->assertSame(1, substr_count($blade, 'id="ribbon"'));
        $this->assertStringContainsString('/js/editor-core.js', $layout);
        $this->assertStringNotContainsString('editor-tools-complete.js', $blade.$layout);
        $this->assertStringNotContainsString('editor-redesign.js', $blade.$layout);
        $this->assertStringNotContainsString('editor-pro.js', $blade.$layout);

        preg_match('/const toolbar=(.*?);const syncActiveEditorModel=/s', $core, $match);
        $toolbar = $match[1] ?? '';
        foreach (['track','accept','reject','footnote','watermark'] as $fakeCommand) {
            $this->assertStringNotContainsString("'$fakeCommand'", $toolbar);
        }
    }

    public function test_core_clipboard_and_voice_commands_share_kernel_transaction_path(): void
    {
        $core = file_get_contents(base_path('public/js/editor-core.js'));

        $this->assertStringContainsString("bind('cut'", $core);
        $this->assertStringContainsString("bind('copy'", $core);
        $this->assertStringContainsString("bind('paste'", $core);
        $this->assertStringContainsString("bind('undo'", $core);
        $this->assertStringContainsString("bind('redo'", $core);
        $this->assertStringContainsString("window.FarastVoiceRuntime?.open?.()", $core);
        $this->assertStringContainsString("command:'InsertText'", $core);
        $this->assertStringContainsString('scheduleSave()', $core);
    }
}
