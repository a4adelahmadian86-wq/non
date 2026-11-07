<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSingleEditor;
use App\Models\EditorSession;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureSingleEditorTest extends TestCase
{
    public function test_first_editor_request_creates_one_session_and_reuses_its_token(): void
    {
        $user = User::factory()->create();
        $session = app('session')->driver();
        $session->flush();

        $firstRequest = Request::create('/editor', 'GET');
        $firstRequest->setUserResolver(fn () => $user);
        $firstRequest->setLaravelSession($session);

        $firstResponse = (new EnsureSingleEditor())->handle(
            $firstRequest,
            fn () => response('ok')
        );

        $token = $session->get('editor_session_token');

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertSame(1, EditorSession::where('user_id', $user->id)->count());

        $secondRequest = Request::create('/editor/document', 'GET');
        $secondRequest->setUserResolver(fn () => $user);
        $secondRequest->setLaravelSession($session);

        $secondResponse = (new EnsureSingleEditor())->handle(
            $secondRequest,
            fn () => response('ok')
        );

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertSame($token, $session->get('editor_session_token'));
        $this->assertSame(1, EditorSession::where('user_id', $user->id)->count());
    }

    public function test_another_browser_session_cannot_join_an_active_editor_session(): void
    {
        $user = User::factory()->create();
        EditorSession::create([
            'user_id' => $user->id,
            'token' => str_repeat('a', 64),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(20),
        ]);

        $session = app('session')->driver();
        $session->flush();

        $request = Request::create('/editor', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($session);

        $response = (new EnsureSingleEditor())->handle(
            $request,
            fn () => response('should not reach editor')
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, EditorSession::where('user_id', $user->id)->count());
        $this->assertNull($session->get('editor_session_token'));
    }
}
