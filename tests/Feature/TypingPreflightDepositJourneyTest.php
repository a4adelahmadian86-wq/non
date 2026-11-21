<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UploadSecurityService;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TypingPreflightDepositJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_pdf_can_be_estimated_accepted_paid_and_opened_in_editor(): void
    {
        Storage::fake('private');

        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'balance_rials' => 10000000,
        ]);

        $security = Mockery::mock(UploadSecurityService::class);
        $security->shouldReceive('inspect')->once()->andReturn([]);
        $this->app->instance(UploadSecurityService::class, $security);

        $workflow = Mockery::mock(WorkflowEngine::class);
        $workflow->shouldReceive('enqueuePaidOrder')->once();
        $this->app->instance(WorkflowEngine::class, $workflow);

        $file = UploadedFile::fake()->createWithContent(
            'source.pdf',
            str_repeat("/Type /Page\n", 8)
        );

        $upload = $this->actingAs($user)->postJson(route('editor.upload'), [
            'source' => $file,
            'source_type' => 'printed',
        ]);

        $upload->assertOk()->assertJsonPath('ok', true);
        $path = (string) $upload->json('path');
        $this->assertStringStartsWith('typing/'.$user->id.'/', $path);

        $estimate = $this->postJson(route('editor.preflight.estimate'), []);
        $estimate->assertOk()
            ->assertJsonPath('pages', 8)
            ->assertJsonPath('deposit_percent', 5)
            ->assertJsonPath('free_page_applied', 1)
            ->assertJsonPath('mode', 'deposit');

        $accept = $this->postJson(route('editor.preflight.accept'), [
            'accept' => '1',
        ]);
        $accept->assertOk()->assertJsonPath('action', 'deposit');

        $order = Order::query()->findOrFail((int) $accept->json('order_id'));
        $this->assertSame('deposit_pending', $order->status);
        $this->assertSame('typing_deposit', $order->pricing_snapshot['kind']);
        $this->assertSame($path, $order->pricing_snapshot['source_path']);

        $payment = $this->post(route('checkout.wallet', $order), [
            'accept_terms' => '1',
        ]);
        $payment->assertRedirect(route('editor'));

        $order->refresh();
        $this->assertSame('deposit_paid', $order->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'status' => 'paid',
        ]);
        $this->assertSessionHas('typing_preflight_accepted.path', $path);
        $this->assertSessionHas('typing_preflight_deposit_order', $order->id);

        $this->get(route('editor'))->assertOk();
    }
}
