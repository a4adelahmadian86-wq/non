<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalMessengerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'سازمان آزمایشی',
            'slug' => 'org-'.Str::lower(Str::random(12)),
            'status' => 'active',
        ]);
    }

    private function makeEmployee(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_EMPLOYEE,
            'is_blocked' => false,
        ]);
    }

    public function test_user_can_create_a_private_conversation_and_send_a_message(): void
    {
        $organization = $this->makeOrganization();
        $sender = $this->makeEmployee($organization);
        $recipient = $this->makeEmployee($organization);

        $this->actingAs($sender)->post(route('messenger.store'), [
            'type' => 'direct',
            'participant_ids' => [$recipient->id],
        ])->assertRedirect();

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame(2, $conversation->participants()->count());

        $this->actingAs($sender)->postJson(route('messenger.send', $conversation), [
            'body' => 'سلام، این یک پیام داخلی است.',
        ])->assertCreated()->assertJsonPath('message.body', 'سلام، این یک پیام داخلی است.');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'سلام، این یک پیام داخلی است.',
        ]);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $recipient->id,
            'type' => 'message_received',
        ]);

        $this->actingAs($recipient)->get(route('messenger.show', $conversation))->assertOk();
    }

    public function test_non_participant_cannot_view_poll_or_send_messages(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeEmployee($organization);
        $member = $this->makeEmployee($organization);
        $outsider = $this->makeEmployee($organization);
        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $owner->id, 'direct_key' => hash('sha256', collect([$owner->id, $member->id])->sort()->implode(':'))]);
        $conversation->participants()->attach([$owner->id => ['role' => 'owner'], $member->id => ['role' => 'member']]);

        $this->actingAs($outsider)->get(route('messenger.show', $conversation))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('messenger.poll', $conversation))->assertNotFound();
        $this->actingAs($outsider)->postJson(route('messenger.send', $conversation), ['body' => 'نباید ارسال شود'])->assertNotFound();
        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_group_owner_can_add_members_rename_and_transfer_ownership_when_leaving(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeEmployee($organization);
        $member = $this->makeEmployee($organization);
        $newMember = $this->makeEmployee($organization);

        $this->actingAs($owner)->post(route('messenger.store'), [
            'type' => 'group',
            'title' => 'تیم پشتیبانی',
            'participant_ids' => [$member->id],
        ])->assertRedirect();

        $conversation = Conversation::query()->where('type', 'group')->firstOrFail();

        $this->actingAs($owner)->post(route('messenger.participants.add', $conversation), [
            'participant_ids' => [$newMember->id],
        ])->assertRedirect();

        $this->actingAs($owner)->patch(route('messenger.update', $conversation), [
            'title' => 'تیم عملیات',
        ])->assertRedirect();

        $this->assertSame('تیم عملیات', $conversation->fresh()->title);
        $this->assertSame(3, $conversation->participants()->count());

        $this->actingAs($owner)->delete(route('messenger.leave', $conversation), [
            'new_owner_id' => $member->id,
        ])->assertRedirect();

        $this->assertSame('owner', $conversation->participants()->where('users.id', $member->id)->first()->pivot->role);
        $this->actingAs($owner)->get(route('messenger.show', $conversation))->assertNotFound();
        $this->actingAs($member)->get(route('messenger.show', $conversation))->assertOk();
    }

    public function test_typing_presence_is_visible_only_to_conversation_members(): void
    {
        $organization = $this->makeOrganization();
        $sender = $this->makeEmployee($organization);
        $recipient = $this->makeEmployee($organization);
        $outsider = $this->makeEmployee($organization);
        $conversation = Conversation::create([
            'type' => 'direct',
            'created_by' => $sender->id,
            'direct_key' => hash('sha256', collect([$sender->id, $recipient->id])->sort()->implode(':')),
        ]);
        $conversation->participants()->attach([
            $sender->id => ['role' => 'owner'],
            $recipient->id => ['role' => 'member'],
        ]);

        $this->actingAs($sender)->postJson(route('messenger.presence', $conversation), ['typing' => true])->assertOk();
        $this->actingAs($recipient)->getJson(route('messenger.poll', $conversation))->assertOk()->assertJsonPath('presence.0.typing', true);
        $this->actingAs($outsider)->getJson(route('messenger.poll', $conversation))->assertNotFound();
    }

    public function test_archived_direct_conversation_returns_to_inbox_when_a_new_message_arrives(): void
    {
        $organization = $this->makeOrganization();
        $first = $this->makeEmployee($organization);
        $second = $this->makeEmployee($organization);
        $conversation = Conversation::create([
            'type' => 'direct',
            'created_by' => $first->id,
            'direct_key' => hash('sha256', collect([$first->id, $second->id])->sort()->implode(':')),
        ]);
        $conversation->participants()->attach([
            $first->id => ['role' => 'owner'],
            $second->id => ['role' => 'member'],
        ]);

        $this->actingAs($first)->post(route('messenger.archive', $conversation))->assertRedirect();
        $this->actingAs($first)->get(route('messenger.show', $conversation))->assertNotFound();

        $this->actingAs($second)->postJson(route('messenger.send', $conversation), ['body' => 'پیام تازه'])->assertCreated();
        $this->actingAs($first)->get(route('messenger.show', $conversation))->assertOk();
    }

    public function test_repeated_direct_conversation_creation_reuses_the_existing_thread(): void
    {
        $organization = $this->makeOrganization();
        $first = $this->makeEmployee($organization);
        $second = $this->makeEmployee($organization);
        $payload = ['type' => 'direct', 'participant_ids' => [$second->id]];

        $this->actingAs($first)->post(route('messenger.store'), $payload)->assertRedirect();
        $this->actingAs($second)->post(route('messenger.store'), ['type' => 'direct', 'participant_ids' => [$first->id]])->assertRedirect();

        $this->assertSame(1, Conversation::query()->where('type', 'direct')->count());
    }

    public function test_employees_cannot_start_conversations_across_organizations(): void
    {
        $sender = $this->makeEmployee($this->makeOrganization());
        $otherOrganizationUser = $this->makeEmployee($this->makeOrganization());

        $this->actingAs($sender)->post(route('messenger.store'), [
            'type' => 'direct',
            'participant_ids' => [$otherOrganizationUser->id],
        ])->assertSessionHasErrors('participant_ids');
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_customer_and_unassigned_accounts_cannot_open_internal_messenger(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'organization_id' => null]);
        $unassignedEmployee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'organization_id' => null]);

        $this->actingAs($customer)->get(route('messenger.index'))->assertForbidden();
        $this->actingAs($unassignedEmployee)->get(route('messenger.index'))->assertForbidden();
    }
}
