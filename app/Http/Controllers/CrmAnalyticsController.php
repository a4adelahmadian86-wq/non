<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminConsoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmAnalyticsController extends Controller
{
    public function __invoke(Request $request, AdminConsoleService $console)
    {
        $console->authorizeAction($request->user(), 'marketing');
        $visibleIds = $console->visibleUserIds($request->user());

        $customers = User::query();
        if ($visibleIds !== null) {
            $customers->whereIn('users.id', $visibleIds);
        }

        $customerCount = (clone $customers)->count();
        $newCustomers = (clone $customers)->where('users.created_at', '>=', now()->subDays(30))->count();
        $blockedCustomers = (clone $customers)->where('users.is_blocked', true)->count();

        $paidOrders = Order::query()->where('status', 'paid');
        if ($visibleIds !== null) {
            $paidOrders->whereIn('user_id', $visibleIds);
        }

        $paidRevenue = (clone $paidOrders)->sum('total_rials');
        $paidOrderCount = (clone $paidOrders)->count();
        $payingCustomers = (clone $paidOrders)->distinct('user_id')->count('user_id');

        $repeatQuery = DB::table('orders')->select('user_id')
            ->where('status', 'paid')->groupBy('user_id')->havingRaw('COUNT(*) >= 2');
        if ($visibleIds !== null) {
            $repeatQuery->whereIn('user_id', $visibleIds);
        }
        $repeatCustomers = DB::query()->fromSub($repeatQuery, 'repeat_customers')->count();

        $openTickets = Ticket::query()->whereIn('status', ['open', 'user_replied', 'pending']);
        if ($visibleIds !== null) {
            $openTickets->whereIn('user_id', $visibleIds);
        }

        $followUps = DB::table('crm_follow_up_tasks');
        if ($visibleIds !== null) {
            $followUps->whereIn('customer_id', $visibleIds);
        }
        $openFollowUps = (clone $followUps)->where('status', 'open')->count();
        $overdueFollowUps = (clone $followUps)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', now())->count();

        $campaignRecipients = DB::table('crm_campaign_recipients')
            ->join('crm_marketing_campaigns', 'crm_marketing_campaigns.id', '=', 'crm_campaign_recipients.campaign_id')
            ->leftJoin('farast_notifications', 'farast_notifications.id', '=', 'crm_campaign_recipients.notification_id');
        if ($visibleIds !== null) {
            $campaignRecipients->whereIn('crm_campaign_recipients.user_id', $visibleIds);
        }
        $recipientCount = (clone $campaignRecipients)->count();
        $readCount = (clone $campaignRecipients)->whereNotNull('farast_notifications.read_at')->count();

        $topCustomers = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', 'paid')
            ->select('users.id', 'users.name', DB::raw('COUNT(orders.id) as paid_orders_count'), DB::raw('SUM(orders.total_rials) as paid_revenue_rials'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('paid_revenue_rials')
            ->limit(10);
        if ($visibleIds !== null) {
            $topCustomers->whereIn('users.id', $visibleIds);
        }

        return response()->json([
            'period' => ['new_customers_days' => 30],
            'customers' => [
                'total' => $customerCount,
                'new_last_30_days' => $newCustomers,
                'blocked' => $blockedCustomers,
                'paying' => $payingCustomers,
                'repeat_buyers' => $repeatCustomers,
            ],
            'commerce' => [
                'paid_orders' => $paidOrderCount,
                'paid_revenue_rials' => (int) $paidRevenue,
                'average_paid_order_rials' => $paidOrderCount > 0 ? (int) round($paidRevenue / $paidOrderCount) : 0,
            ],
            'operations' => [
                'open_tickets' => $openTickets->count(),
                'open_follow_ups' => $openFollowUps,
                'overdue_follow_ups' => $overdueFollowUps,
            ],
            'marketing' => [
                'campaign_recipients' => $recipientCount,
                'read_notifications' => $readCount,
                'read_rate_percent' => $recipientCount > 0 ? round(100 * $readCount / $recipientCount, 1) : 0,
            ],
            'top_customers' => $topCustomers->get(),
        ]);
    }
}
