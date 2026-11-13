<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FarastNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CommunicationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_communication_center_aggregates_existing_ticket_and_notification_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        Ticket::create([
            'user_id' => $admin->id,
            'subject' => 'پیگیری سفارش آزمایشی',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
        ]);
        FarastNotification::create([
            'user_id' => $admin->id,
            'type' => 'ticket_created_staff',
            'title' => 'تیکت تازه',
            'body' => 'یک درخواست پشتیبانی ثبت شد.',
        ]);

        $this->actingAs($admin)->get(route('admin.communications.index'))
            ->assertOk()
            ->assertSee('مرکز ارتباطات فراست')
            ->assertSee('پیگیری سفارش آزمایشی')
            ->assertSee('تیکت تازه')
            ->assertSee('میز تیکت‌ها')
            ->assertSee('پیام‌رسان داخلی');
    }

    public function test_customer_can_use_shared_center_without_internal_messenger_access(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        FarastNotification::create([
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
            'title' => 'پاسخ پشتیبانی مشتری',
            'body' => 'پاسخ جدید برای درخواست شما ثبت شد.',
        ]);

        $this->actingAs($customer)->get(route('platform.communications'))
            ->assertOk()
            ->assertSee('مرکز ارتباطات فراست')
            ->assertSee('پاسخ پشتیبانی مشتری')
            ->assertDontSee('پیام‌رسان داخلی')
            ->assertDontSee('گفت‌وگوهای داخلی');
    }

    public function test_workspace_navigation_exposes_shared_center_but_hides_internal_messenger_from_customers(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        $groups = app(\App\Services\DashboardNavigationService::class)->forUser($customer);
        $items = collect($groups)->flatMap(fn ($group) => $group['items']);

        $this->assertTrue($items->contains(fn ($item) => $item['href'] === route('platform.communications')));
        $this->assertFalse($items->contains(fn ($item) => $item['href'] === route('messenger.index')));
    }

    public function test_admin_search_finds_existing_ticket_messages_without_creating_a_parallel_store(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        $ticket = Ticket::create([
            'user_id' => $admin->id,
            'subject' => 'درخواست پشتیبانی',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $admin->id,
            'body' => 'شناسه پیگیری یکتای blue-orchid-927',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['q' => 'blue-orchid-927']))
            ->assertOk()
            ->assertSee('شناسه پیگیری یکتای blue-orchid-927')
            ->assertSee('پیام در '.$ticket->displayCode());
    }

    public function test_admin_search_finds_messages_inside_existing_internal_conversations(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        $peer = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        $participants = collect([$admin->id, $peer->id])->sort()->values();
        $conversation = Conversation::create([
            'type' => 'direct',
            'created_by' => $admin->id,
            'direct_key' => hash('sha256', $participants->implode(':')),
        ]);
        $conversation->participants()->attach([
            $admin->id => ['role' => 'owner'],
            $peer->id => ['role' => 'member'],
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $peer->id,
            'body' => 'متن محرمانه تیمی silver-maple-482',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['q' => 'silver-maple-482']))
            ->assertOk()
            ->assertSee('متن محرمانه تیمی silver-maple-482')
            ->assertSee('پیام در گفت‌وگوی خصوصی');
    }

    public function test_single_character_search_is_rejected_without_scanning_domain_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);

        $this->actingAs($admin)
            ->get(route('admin.communications.index', ['q' => 'x']))
            ->assertOk()
            ->assertSee('برای جست‌وجو حداقل دو نویسه وارد کنید.');
    }

    public function test_customer_search_does_not_expose_another_users_notifications_or_internal_ticket_notes(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        $other = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        FarastNotification::create([
            'user_id' => $other->id,
            'type' => 'ticket_reply',
            'title' => 'private-orchid-notice',
            'body' => 'اطلاعیه خصوصی',
        ]);

        $ownTicket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'تیکت خود کاربر',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        TicketMessage::create([
            'ticket_id' => $ownTicket->id,
            'user_id' => $other->id,
            'body' => 'private-orchid-note',
            'is_internal' => true,
            'is_staff' => true,
        ]);
        TicketMessage::create([
            'ticket_id' => $ownTicket->id,
            'user_id' => $other->id,
            'body' => 'public-orchid-reply',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $otherTicket = Ticket::create([
            'user_id' => $other->id,
            'subject' => 'تیکت کاربر دیگر',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        TicketMessage::create([
            'ticket_id' => $otherTicket->id,
            'user_id' => $other->id,
            'body' => 'other-customer-orchid-note',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('platform.communications', ['q' => 'private-orchid-notice']))
            ->assertOk()
            ->assertDontSee('<strong>private-orchid-notice</strong>', false);

        $this->get(route('platform.communications', ['q' => 'private-orchid-note']))
            ->assertOk()
            ->assertDontSee('<strong>پیام در '.$ownTicket->displayCode(), false)
            ->assertDontSee('<strong>private-orchid-note</strong>', false);

        $this->get(route('platform.communications', ['q' => 'other-customer-orchid-note']))
            ->assertOk()
            ->assertDontSee('<strong>other-customer-orchid-note</strong>', false);

        $this->get(route('platform.communications', ['q' => 'public-orchid-reply']))
            ->assertOk()
            ->assertSee('public-orchid-reply');
    }

    public function test_non_admin_cannot_open_staff_communication_center(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);

        $this->actingAs($customer)->get(route('admin.communications.index'))->assertForbidden();
    }
    public function test_customer_ticket_cards_link_to_their_own_customer_facing_ticket_page(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'تیکت قابل مشاهده مشتری',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->actingAs($customer)
            ->get(route('platform.communications'))
            ->assertOk()
            ->assertSee(route('support.show', $ticket), false)
            ->assertDontSee(route('admin.support.management', ['ticket' => $ticket->id]), false);
    }

    public function test_admin_with_restricted_console_scope_cannot_see_global_support_records(): void
    {
        $overrideKey = 'communications_test_scope_'.spl_object_id($this);
        $previousOverrideKey = config('farast_admin.scope_override_key');
        config(['farast_admin.scope_override_key' => $overrideKey]);
        SiteSetting::write($overrideKey, 'own');

        try {
            $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
            $other = User::factory()->create(['role' => 'user', 'is_blocked' => false]);

            $ownTicket = Ticket::create([
                'user_id' => $admin->id,
                'subject' => 'تیکت شخصی مدیر محدودشده',
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
            ]);
            TicketMessage::create([
                'ticket_id' => $ownTicket->id,
                'user_id' => $admin->id,
                'body' => 'پیام عمومی مدیر در دامنه شخصی',
                'is_internal' => false,
                'is_staff' => false,
            ]);
            $otherTicket = Ticket::create([
                'user_id' => $other->id,
                'subject' => 'رکورد محرمانه خارج از دامنه',
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
            ]);

            $this->actingAs($admin)
                ->get(route('platform.communications', ['q' => 'رکورد محرمانه خارج از دامنه']))
                ->assertOk()
                ->assertSee('تیکت شخصی مدیر محدودشده')
                ->assertDontSee('<strong>'.$otherTicket->displayCode().' — '.$otherTicket->subject.'</strong>', false)
                ->assertSee(route('support.show', $ownTicket), false)
                ->assertDontSee(route('admin.support.management', ['ticket' => $ownTicket->id]), false);

            $this->get(route('platform.communications', ['q' => 'پیام عمومی مدیر در دامنه شخصی']))
                ->assertOk()
                ->assertSee(route('support.show', $ownTicket), false)
                ->assertDontSee(route('admin.support.management', ['ticket' => $ownTicket->id]), false);

            $this->get(route('support.show', $ownTicket))->assertOk();
            $this->assertTrue(Gate::forUser($admin)->allows('view', $ownTicket));
            $this->assertFalse(Gate::forUser($admin)->allows('view', $otherTicket));
            $this->assertFalse(Gate::forUser($admin)->allows('reply', $otherTicket));
            $this->assertFalse(Gate::forUser($admin)->allows('close', $otherTicket));
        } finally {
            SiteSetting::query()->where('key', $overrideKey)->delete();
            config(['farast_admin.scope_override_key' => $previousOverrideKey]);
        }
    }

    public function test_open_ticket_stat_counts_all_customer_tickets_not_only_the_recent_preview(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);

        for ($i = 1; $i <= 11; $i++) {
            Ticket::create([
                'user_id' => $customer->id,
                'subject' => 'تیکت شمارش '.$i,
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
            ]);
        }

        $this->actingAs($customer)
            ->get(route('platform.communications'))
            ->assertOk()
            ->assertSee('<strong>11</strong>', false)
            ->assertSee('نمایش آخرین ۱۰ تیکت');
    }

}
