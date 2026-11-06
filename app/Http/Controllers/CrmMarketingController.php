<?php

namespace App\Http\Controllers;

use App\Models\FarastNotification;
use App\Services\AdminConsoleService;
use App\Services\AuditLogService;
use App\Services\CrmAudienceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmMarketingController extends Controller
{
    private function scope(Request $request, AdminConsoleService $console): ?array
    {
        $console->authorizeAction($request->user(), 'marketing');

        return $console->visibleUserIds($request->user());
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'role' => 'nullable|in:admin,organization_manager,team_supervisor,employee,observer,guest',
            'verification' => 'nullable|in:any,verified,unverified',
            'registered_after' => 'nullable|date',
            'registered_before' => 'nullable|date|after_or_equal:registered_after',
            'min_orders' => 'nullable|integer|min:1|max:1000',
            'paid_customer' => 'sometimes|boolean',
        ]);
    }

    public function segments(Request $request, AdminConsoleService $console)
    {
        $this->scope($request, $console);

        return response()->json([
            'data' => DB::table('crm_customer_segments')->orderByDesc('created_at')->paginate(25),
        ]);
    }

    public function storeSegment(Request $request, AdminConsoleService $console)
    {
        $visibleIds = $this->scope($request, $console);
        $data = $request->validate([
            'name' => 'required|string|max:140',
            'description' => 'nullable|string|max:1000',
            'filters' => 'required|array',
        ]);
        $filters = validator($data['filters'], [
            'role' => 'nullable|in:admin,organization_manager,team_supervisor,employee,observer,guest',
            'verification' => 'nullable|in:any,verified,unverified',
            'registered_after' => 'nullable|date',
            'registered_before' => 'nullable|date|after_or_equal:registered_after',
            'min_orders' => 'nullable|integer|min:1|max:1000',
            'paid_customer' => 'sometimes|boolean',
        ])->validate();

        $id = DB::table('crm_customer_segments')->insertGetId([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = app(CrmAudienceService::class)->query($filters, $visibleIds)->count();
        app(AuditLogService::class)->record($request->user()->id, 'crm.segment.created', $request->ip(), [
            'segment_id' => $id, 'audience_count' => $count,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'audience_count' => $count], 201);
    }

    public function previewAudience(Request $request, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $visibleIds = $this->scope($request, $console);
        $filters = $this->filters($request);

        return response()->json([
            'audience_count' => $audiences->query($filters, $visibleIds)->count(),
            'filters' => $filters,
        ]);
    }

    public function campaigns(Request $request, AdminConsoleService $console)
    {
        $this->scope($request, $console);

        return response()->json([
            'data' => DB::table('crm_marketing_campaigns')
                ->leftJoin('crm_customer_segments', 'crm_customer_segments.id', '=', 'crm_marketing_campaigns.segment_id')
                ->orderByDesc('crm_marketing_campaigns.created_at')
                ->paginate(25, [
                    'crm_marketing_campaigns.*',
                    'crm_customer_segments.name as segment_name',
                ]),
        ]);
    }

    public function storeCampaign(Request $request, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $visibleIds = $this->scope($request, $console);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'segment_id' => 'required|integer|exists:crm_customer_segments,id',
            'title' => 'required|string|max:180',
            'body' => 'required|string|min:2|max:5000',
        ]);

        $segment = DB::table('crm_customer_segments')->where('id', $data['segment_id'])->first();
        abort_unless($segment, 404);
        $filters = json_decode($segment->filters, true) ?: [];
        $count = $audiences->query($filters, $visibleIds)->count();

        $id = DB::table('crm_marketing_campaigns')->insertGetId([
            'name' => trim($data['name']),
            'channel' => 'in_app',
            'status' => 'draft',
            'segment_id' => $segment->id,
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'created_by' => $request->user()->id,
            'recipient_count' => $count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuditLogService::class)->record($request->user()->id, 'crm.campaign.created', $request->ip(), [
            'campaign_id' => $id, 'audience_count' => $count,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'status' => 'draft', 'audience_count' => $count], 201);
    }

    public function sendCampaign(Request $request, int $campaign, AdminConsoleService $console, CrmAudienceService $audiences)
    {
        $visibleIds = $this->scope($request, $console);

        $sentCount = DB::transaction(function () use ($campaign, $request, $visibleIds, $audiences) {
            $record = DB::table('crm_marketing_campaigns')->where('id', $campaign)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($record->status === 'draft', 409, 'این کمپین قبلاً ارسال یا پردازش شده است.');

            $segment = DB::table('crm_customer_segments')->where('id', $record->segment_id)->first();
            abort_unless($segment, 409);
            $filters = json_decode($segment->filters, true) ?: [];
            $audience = $audiences->query($filters, $visibleIds);
            $count = (clone $audience)->count();
            abort_if($count > 2000, 422, 'برای ارسال بیش از ۲۰۰۰ مخاطب، کمپین را به چند بخش کوچک‌تر تقسیم کنید.');

            DB::table('crm_marketing_campaigns')->where('id', $campaign)->update([
                'status' => 'sending', 'recipient_count' => $count, 'updated_at' => now(),
            ]);

            $sent = 0;
            $audience->chunkById(200, function ($users) use ($record, $campaign, &$sent) {
                foreach ($users as $user) {
                    $recipientId = DB::table('crm_campaign_recipients')->insertOrIgnore([
                        'campaign_id' => $campaign,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($recipientId === 0) {
                        continue;
                    }

                    $notification = FarastNotification::create([
                        'user_id' => $user->id,
                        'type' => 'announcement_campaign',
                        'title' => $record->title,
                        'body' => $record->body,
                        'data' => ['campaign_id' => $campaign, 'url' => '/workspace/notifications'],
                    ]);

                    DB::table('crm_campaign_recipients')->where('campaign_id', $campaign)
                        ->where('user_id', $user->id)->update([
                            'notification_id' => $notification->id,
                            'notified_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $sent++;
                }
            }, 'users.id');

            DB::table('crm_marketing_campaigns')->where('id', $campaign)->update([
                'status' => 'sent', 'recipient_count' => $sent, 'sent_at' => now(), 'updated_at' => now(),
            ]);

            return $sent;
        });

        app(AuditLogService::class)->record($request->user()->id, 'crm.campaign.sent', $request->ip(), [
            'campaign_id' => $campaign, 'recipient_count' => $sentCount,
        ]);

        return response()->json(['ok' => true, 'status' => 'sent', 'recipient_count' => $sentCount]);
    }
}
