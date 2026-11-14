<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportSlaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportReportAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_report_preserves_first_public_staff_response_and_sla_semantics(): void
    {
        $owner = User::factory()->create();
        $adminAuthor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['name' => 'Support Agent']);

        config(['support.business_hours_only' => false]);
        $createdAt = now()->subHour();
        $ticket = Ticket::create([
            'user_id' => $owner->id,
            'assigned_to' => $agent->id,
            'subject' => 'Report aggregation',
            'status' => 'closed',
            'priority' => 'normal',
            'sla_first_due_at' => $createdAt->copy()->addMinutes(30),
        ]);
        $ticket->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        TicketMessage::forceCreate([
            'ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'body' => 'Customer message',
            'is_internal' => false,
            'is_staff' => false,
            'created_at' => $createdAt->copy()->addMinutes(5),
            'updated_at' => $createdAt->copy()->addMinutes(5),
        ]);
        TicketMessage::forceCreate([
            'ticket_id' => $ticket->id,
            'user_id' => $adminAuthor->id,
            'body' => 'Internal staff note',
            'is_internal' => true,
            'is_staff' => false,
            'created_at' => $createdAt->copy()->addMinutes(10),
            'updated_at' => $createdAt->copy()->addMinutes(10),
        ]);
        TicketMessage::forceCreate([
            'ticket_id' => $ticket->id,
            'user_id' => $adminAuthor->id,
            'body' => 'First public staff response',
            'is_internal' => false,
            'is_staff' => false,
            'created_at' => $createdAt->copy()->addMinutes(20),
            'updated_at' => $createdAt->copy()->addMinutes(20),
        ]);

        $report = app(SupportSlaService::class)->agentReport(
            Carbon::parse($createdAt)->startOfDay(),
            Carbon::parse($createdAt)->endOfDay(),
        );

        $this->assertSame(1, $report['totals']['tickets']);
        $this->assertSame(1, $report['totals']['closed']);
        $this->assertSame(1, $report['totals']['first_response_total']);
        $this->assertSame(1, $report['totals']['first_response_met']);
        $this->assertSame(20, $report['totals']['avg_first_response_minutes']);
        $this->assertSame(100.0, $report['totals']['sla_rate']);

        $agents = collect($report['agents'])->keyBy('id');
        $this->assertSame(1, $agents[$agent->id]['assigned']);
        $this->assertSame(1, $agents[$agent->id]['closed']);
        $this->assertSame(0, $agents[$agent->id]['replies']);
        $this->assertSame(1, $agents[$adminAuthor->id]['replies']);
        $this->assertSame(1, $agents[$adminAuthor->id]['first_response_total']);
        $this->assertSame(20, $agents[$adminAuthor->id]['avg_first_response_minutes']);
    }

    public function test_agent_report_eager_loads_messages_in_a_bounded_number_of_queries(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($index = 0; $index < 4; $index++) {
            $ticket = Ticket::create([
                'user_id' => $owner->id,
                'subject' => 'Ticket '.$index,
                'status' => 'open',
                'priority' => 'normal',
            ]);

            for ($messageIndex = 0; $messageIndex < 8; $messageIndex++) {
                TicketMessage::forceCreate([
                    'ticket_id' => $ticket->id,
                    'user_id' => $messageIndex % 2 === 0 ? $owner->id : $staff->id,
                    'body' => 'Message '.$messageIndex,
                    'is_internal' => false,
                    'is_staff' => $messageIndex % 2 !== 0,
                ]);
            }
        }

        $messageQueries = [];
        $reportQueryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$messageQueries, &$reportQueryCount): void {
            $reportQueryCount++;
            if (str_contains(strtolower($query->sql), 'ticket_messages')) {
                $messageQueries[] = $query->sql;
            }
        });

        $report = app(SupportSlaService::class)->agentReport(now()->subDay(), now()->addDay());

        $this->assertSame(4, $report['totals']['tickets']);
        $this->assertCount(1, $messageQueries, 'Messages must be eager-loaded once for the ticket chunk, not queried per ticket.');
        $this->assertLessThanOrEqual(4, $reportQueryCount, 'The report must keep a bounded query count for a chunk of tickets.');
    }

    public function test_support_report_aggregates_csat_scores_without_loading_each_rating(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $today = now()->startOfDay()->addHours(12);

        foreach ([5, 5, 3] as $score) {
            Ticket::create([
                'user_id' => $customer->id,
                'subject' => 'Rated ticket',
                'status' => 'closed',
                'priority' => 'normal',
                'csat_score' => $score,
                'csat_at' => $today,
                'closed_at' => $today,
            ]);
        }

        Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Outside range',
            'status' => 'closed',
            'priority' => 'normal',
            'csat_score' => 1,
            'csat_at' => $today->copy()->subDay(),
            'closed_at' => $today->copy()->subDay(),
        ]);

        $csatQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$csatQueries): void {
            if (str_contains(strtolower($query->sql), 'csat_score')) {
                $csatQueries[] = strtolower($query->sql);
            }
        });

        $this->actingAs($admin)
            ->get(route('admin.support.report', [
                'from' => $today->toDateString(),
                'to' => $today->toDateString(),
            ]))
            ->assertOk()
            ->assertViewHas('csat', function (array $csat): bool {
                return $csat['count'] === 3
                    && $csat['avg'] === 4.33
                    && $csat['distribution'][5] === 2
                    && $csat['distribution'][3] === 1
                    && $csat['distribution'][1] === 0;
            });

        $this->assertCount(1, $csatQueries);
        $this->assertStringContainsString('group by', $csatQueries[0]);
        $this->assertStringContainsString('count(*)', $csatQueries[0]);
    }


    public function test_agent_report_prefers_the_persisted_first_response_timestamp(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create(['name' => 'Persisted Response Agent']);
        config(['support.business_hours_only' => false]);
        $createdAt = now()->subHour();
        $responseAt = $createdAt->copy()->addMinutes(15);

        $ticket = Ticket::create([
            'user_id' => $owner->id,
            'assigned_to' => $agent->id,
            'subject' => 'Persisted response timestamp',
            'status' => 'answered',
            'priority' => 'normal',
            'first_response_at' => $responseAt,
            'sla_first_due_at' => $createdAt->copy()->addMinutes(30),
        ]);
        $ticket->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        $report = app(SupportSlaService::class)->agentReport(
            Carbon::parse($createdAt)->startOfDay(),
            Carbon::parse($createdAt)->endOfDay(),
        );

        $this->assertSame(1, $report['totals']['tickets']);
        $this->assertSame(1, $report['totals']['first_response_total']);
        $this->assertSame(15, $report['totals']['avg_first_response_minutes']);
        $this->assertSame(100.0, $report['totals']['sla_rate']);
        $this->assertSame('Persisted Response Agent', $report['agents'][0]['name']);
    }

}
