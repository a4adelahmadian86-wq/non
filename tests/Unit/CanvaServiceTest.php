<?php

namespace Tests\Unit;

use App\Models\CanvaConnection;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CanvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CanvaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_url_uses_pkce_and_server_configuration(): void
    {
        SiteSetting::write('canva_client_id', 'client-123');
        SiteSetting::write('canva_client_secret', 'secret-456', true);
        SiteSetting::write('canva_redirect_uri', 'https://example.test/canva/callback');

        $user = User::factory()->create();
        $data = app(CanvaService::class)->authorizeUrl($user);

        $this->assertStringStartsWith('https://www.canva.com/api/oauth/authorize?', $data['url']);
        $this->assertStringContainsString('code_challenge_method=s256', $data['url']);
        $this->assertStringContainsString('client_id=client-123', $data['url']);
        $this->assertNotEmpty(session('canva_oauth.verifier'));
    }

    public function test_expired_access_token_refreshes_and_persists_rotated_refresh_token(): void
    {
        SiteSetting::write('canva_client_id', 'client-123');
        SiteSetting::write('canva_client_secret', 'secret-456', true);
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://api.canva.com/rest/v1/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 14400,
                'scope' => 'design:meta:read',
            ], 200),
        ]);

        $token = app(CanvaService::class)->token($connection);

        $this->assertSame('new-access-token', $token);
        $connection->refresh();
        $this->assertSame('new-refresh-token', $connection->refresh_token);
        $this->assertSame('connected', $connection->status);
        $this->assertNotNull($connection->last_checked_at);
        $this->assertTrue($connection->expires_at->isFuture());

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.canva.com/rest/v1/oauth/token'
            && ($request->data()['grant_type'] ?? null) === 'refresh_token'
            && ($request->data()['refresh_token'] ?? null) === 'old-refresh-token'
            && $request->hasHeader('Authorization')
        );
    }

    public function test_invalid_refresh_token_marks_connection_for_reauthorization(): void
    {
        SiteSetting::write('canva_client_id', 'client-123');
        SiteSetting::write('canva_client_secret', 'secret-456', true);
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'invalid-refresh-token',
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://api.canva.com/rest/v1/oauth/token' => Http::response([
                'code' => 'invalid_grant',
                'message' => 'Invalid refresh token',
            ], 400),
        ]);

        try {
            app(CanvaService::class)->token($connection);
            $this->fail('An invalid refresh token must require reauthorization.');
        } catch (RuntimeException $exception) {
            $this->assertSame('canva_reauthorization_required', $exception->getMessage());
        }

        $connection->refresh();
        $this->assertSame('reauthorize', $connection->status);
        $this->assertNotNull($connection->last_checked_at);
    }

    public function test_resource_unauthorized_response_marks_connection_for_reauthorization(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'access_token' => 'valid-looking-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://api.canva.com/rest/v1/designs*' => Http::response(['code' => 'invalid_access_token'], 401),
        ]);

        try {
            app(CanvaService::class)->designs($user);
            $this->fail('Unauthorized resource requests must invalidate the connection state.');
        } catch (RuntimeException $exception) {
            $this->assertSame('canva_unauthorized', $exception->getMessage());
        }

        $connection->refresh();
        $this->assertSame('reauthorize', $connection->status);
    }

    public function test_transient_resource_failure_marks_connection_degraded_not_connected(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'access_token' => 'valid-looking-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://api.canva.com/rest/v1/designs*' => Http::response(['code' => 'temporarily_unavailable'], 503),
        ]);

        try {
            app(CanvaService::class)->designs($user);
            $this->fail('Transient API errors must be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('canva_api_http_503', $exception->getMessage());
        }

        $connection->refresh();
        $this->assertSame('degraded', $connection->status);
        $this->assertNotNull($connection->last_checked_at);
    }

    public function test_refresh_network_failure_is_recorded_without_losing_connection_record(): void
    {
        SiteSetting::write('canva_client_id', 'client-123');
        SiteSetting::write('canva_client_secret', 'secret-456', true);
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake(fn () => throw new ConnectionException('network unavailable'));

        try {
            app(CanvaService::class)->token($connection);
            $this->fail('A refresh network failure must be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('canva_token_refresh_unavailable', $exception->getMessage());
        }

        $connection->refresh();
        $this->assertSame('degraded', $connection->status);
        $this->assertNotNull($connection->last_checked_at);
    }

    private function connection(User $user, array $overrides = []): CanvaConnection
    {
        return CanvaConnection::create(array_merge([
            'user_id' => $user->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'scopes' => ['design:meta:read'],
            'status' => 'connected',
            'last_checked_at' => now()->subHour(),
        ], $overrides));
    }
}
