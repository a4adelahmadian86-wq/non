<?php

namespace Tests\Feature;

use App\Http\Controllers\VoiceController;
use Illuminate\Http\Request;
use Tests\TestCase;

class VoiceCredentialIsolationTest extends TestCase
{
    private function token(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    public function test_public_stream_config_never_returns_provider_credentials(): void
    {
        config(['services.voice_stream.gateway_secret' => 'test-gateway-secret']);
        $token = $this->token([
            'uid' => 1,
            'locale' => 'fa-IR',
            'iat' => time(),
            'exp' => time() + 120,
            'nonce' => 'test',
        ]);

        $response = app(VoiceController::class)->streamConfig(Request::create('/editor/voice/stream-config', 'POST', [
            'token' => $token,
        ], [], [], [
            'HTTP_X_FARAST_VOICE_GATEWAY' => hash_hmac('sha256', $token, (string) config('services.voice_stream.gateway_secret','test-gateway-secret')),
        ]));

        $data = $response->getData(true);

        $this->assertTrue($data['ok']);
        $this->assertArrayNotHasKey('credentials', $data);
        $this->assertArrayNotHasKey('api_key', $data);
        $this->assertArrayNotHasKey('private_key', $data);
    }

    public function test_provider_config_requires_gateway_signature(): void
    {
        $token = $this->token([
            'uid' => 1,
            'locale' => 'fa-IR',
            'iat' => time(),
            'exp' => time() + 120,
            'nonce' => 'test',
        ]);

        $request = Request::create('/editor/voice/provider-config', 'POST', ['token' => $token]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(VoiceController::class)->streamProviderConfig($request, app(\App\Services\VoiceProviderRouter::class));
    }
}
