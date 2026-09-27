<?php

namespace Tests\Unit;

use App\Services\AI\AiContextEngine;
use App\Services\AI\AiOperationRegistry;
use App\Services\AI\AiPrivacyPolicy;
use App\Services\AI\AiProviderRegistry;
use App\Services\AI\Providers\GeminiEditorProvider;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiCorePolicyTest extends TestCase
{
    public function test_legacy_operations_resolve_to_canonical_names(): void
    {
        $registry = new AiOperationRegistry();
        $this->assertSame('selection.format_suggest', $registry->resolve('selection')['name']);
        $this->assertSame('selection.spellcheck', $registry->resolve('word')['name']);
        $this->assertSame('selection.punctuation', $registry->resolve('punctuation')['name']);
    }

    public function test_automatic_privacy_mode_is_deterministic_and_local_does_not_fallback(): void
    {
        $policy = new AiPrivacyPolicy();
        $this->assertSame('external', $policy->resolve('automatic', ['external']));
        $this->expectException(ValidationException::class);
        $policy->resolve('local', ['external']);
    }

    public function test_context_engine_only_keeps_whitelisted_context(): void
    {
        $operation = (new AiOperationRegistry())->resolve('word');
        $payload = (new AiContextEngine())->build($operation, 'سلام', ['sentence'=>'سلام دنیا','whole_document'=>'do-not-send','secret'=>'do-not-send']);
        $this->assertSame(['sentence'=>'سلام دنیا'], $payload['context']);
    }

    public function test_large_document_is_rejected_instead_of_truncated(): void
    {
        $operation = (new AiOperationRegistry())->resolve('document.summarize');
        $this->expectException(ValidationException::class);
        (new AiContextEngine())->build($operation, str_repeat('ا', 60001));
    }

    public function test_existing_gemini_is_reached_through_common_provider_registry(): void
    {
        $operations = new AiOperationRegistry();
        $operation = $operations->resolve('selection.proofread');
        $providers = new AiProviderRegistry(new GeminiEditorProvider());
        $provider = $providers->forOperation($operation);

        $this->assertSame('gemini', $provider->name());
        $this->assertTrue($provider->supports($operation));
        $this->assertTrue($providers->capabilities()['gemini']['text']);
    }
}
