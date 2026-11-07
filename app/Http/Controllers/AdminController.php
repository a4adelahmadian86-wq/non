<?php

namespace App\Http\Controllers;

use App\Models\AiInteraction;
use App\Models\Announcement;
use App\Models\EmailLog;
use App\Models\PricingRule;
use App\Models\FarastPricingPolicy;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserCapability;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\VoiceProviderAccount;
use App\Services\AdminConsoleService;
use App\Services\AuditLogService;
use App\Services\CapabilityService;
use App\Services\EmailService;
use App\Services\MailConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    public function index(Request $request, CapabilityService $capabilities, AdminConsoleService $console)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        // دامنهٔ کاربر از middleware یا محاسبهٔ مستقیم نقش.
        $scope = $request->attributes->get('farast.scope') ?? AdminConsoleService::scopeOf($actor);
        $globalScope = $scope === AdminConsoleService::SCOPE_GLOBAL;
        $visibleIds = $console->visibleUserIds($actor);

        // دسترسی‌های کنسول بر پایهٔ RBAC + Scope — همین‌جا منبع واحد ناوبری است.
        $allowedSections = $console->allowedSections($actor);

        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status');

        $usersQuery = User::with('wallet')->latest();
        if ($visibleIds !== null) {
            $usersQuery->whereIn('id', $visibleIds);
        }
        if ($search !== '') {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($statusFilter === 'blocked') {
            $usersQuery->where('is_blocked', true);
        } elseif ($statusFilter === 'active') {
            $usersQuery->where('is_blocked', false);
        } elseif ($statusFilter === 'admins') {
            $usersQuery->where('role', User::ROLE_ADMIN);
        }
        $canViewUsers = $console->sectionAllowed($actor, 'users');
        $totalScopedUsers = $canViewUsers ? (clone $usersQuery)->count() : 0;
        // Do not hydrate personal user data unless users.view is granted in this scope.
        $users = $canViewUsers ? $usersQuery->limit(100)->get() : collect();
        $canManageAnnouncements = $console->sectionAllowed($actor, 'announcements');
        $canViewAnalytics = $console->sectionAllowed($actor, 'analytics');

        $settings = [];
        if ($globalScope) {
            foreach (['weekly_free_pages', 'max_file_mb', 'daily_ai_requests', 'can_type', 'can_ai', 'can_voice', 'can_export_docx', 'can_export_pdf', 'can_feedback', 'can_support', 'gemini_model', 'gemini_fallback_model', 'assistant_groq_model'] as $key) {
                $default = $key === 'assistant_groq_model' ? 'openai/gpt-oss-120b' : (CapabilityService::DEFAULTS[$key] ?? 'gemini-3.8-flash');
                $settings[$key] = SiteSetting::read($key, $default);
            }
        }

        $weekAgo = now()->subWeek();
        $monthAgo = now()->subMonth();

        // شاخص‌های دامنه‌مند: اگر کاربر ادمین نباشد، فقط دادهٔ کاربران قابل‌مشاهده شمرده می‌شود.
        $scopedCount = function (string $table, array $filters = [], ?string $userColumn = 'user_id') use ($visibleIds) {
            if (! Schema::hasTable($table)) {
                return 0;
            }
            $q = DB::table($table);
            if ($visibleIds !== null && $userColumn !== null && Schema::hasColumn($table, $userColumn)) {
                $q->whereIn($userColumn, $visibleIds);
            } elseif ($visibleIds !== null && $table === 'users') {
                $q->whereIn('id', $visibleIds);
            }
            foreach ($filters as $col => $val) {
                if (Schema::hasColumn($table, is_string($col) ? $col : '')) {
                    $q->where($col, $val);
                }
            }

            return (int) $q->count();
        };

        $chartDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $chartDays[] = now()->subDays($i)->toDateString();
        }
        $seriesOf = function (string $table, string $dateCol = 'created_at') use ($chartDays, $visibleIds) {
            if (! Schema::hasTable($table)) {
                return array_fill(0, count($chartDays), 0);
            }
            $since = $chartDays[0];
            $q = DB::table($table)->where($dateCol, '>=', $since);
            if ($visibleIds !== null) {
                if ($table === 'users') {
                    $q->whereIn('id', $visibleIds);
                } elseif (Schema::hasColumn($table, 'user_id')) {
                    $q->whereIn('user_id', $visibleIds);
                } else {
                    // جدول بدون ستون user_id در دامنه‌های محدود شمارش نمی‌شود تا داده نشت نکند.
                    return array_fill(0, count($chartDays), 0);
                }
            }
            $rows = $q->select(DB::raw("DATE($dateCol) as d"), DB::raw('COUNT(*) as c'))
                ->groupByRaw("DATE($dateCol)")
                ->get();
            $map = [];
            foreach ($rows as $row) {
                $d = \Illuminate\Support\Carbon::parse($row->d)->toDateString();
                $map[$d] = ($map[$d] ?? 0) + (int) $row->c;
            }
            return array_map(fn ($d) => $map[$d] ?? 0, $chartDays);
        };

        $ticketStats = collect();
        if (Schema::hasTable('tickets')) {
            $tq = Ticket::query();
            if ($visibleIds !== null) {
                $tq->whereIn('user_id', $visibleIds);
            }
            $ticketStats = $tq->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        }

        $visibleTeamIds = $console->visibleTeamIds($actor);
        $visibleOrganizationIds = $console->visibleOrganizationIds($actor);
        $paidPaymentsQuery = function () use ($visibleIds) {
            $query = DB::table('payments')->where('payments.status', 'paid');
            if ($visibleIds !== null) {
                $query->join('orders', 'orders.id', '=', 'payments.order_id')
                    ->whereIn('orders.user_id', $visibleIds);
            }

            return $query;
        };
        $walletBalanceQuery = function () use ($visibleIds) {
            $query = DB::table('wallets');
            if ($visibleIds !== null) {
                $query->whereIn('user_id', $visibleIds);
            }

            return $query;
        };

        $topConsumers = collect();
        if ($canViewAnalytics && Schema::hasTable('typing_documents')) {
            $tcq = DB::table('typing_documents')
                ->join('users', 'users.id', '=', 'typing_documents.user_id')
                ->where('typing_documents.created_at', '>=', $monthAgo)
                ->groupBy('users.id', 'users.name', 'users.mobile')
                ->select('users.id', 'users.name', 'users.mobile', DB::raw('COUNT(typing_documents.id) as documents'))
                ->orderByDesc('documents')
                ->limit(5);
            if ($visibleIds !== null) {
                $tcq->whereIn('typing_documents.user_id', $visibleIds);
            }
            $topConsumers = $tcq->get();
        }

        return view('admin.index', [
            'rules' => $globalScope ? PricingRule::orderBy('id')->get() : collect(),
            'users' => $users,
            'userTotalForFilter' => $totalScopedUsers,
            'announcements' => $canManageAnnouncements ? Announcement::latest()->limit(20)->get() : collect(),
            'console' => $console,
            'scope' => $scope,
            'scopeSummary' => $console->scopeSummary($actor),
            'allowedSections' => $allowedSections,
            'stats' => [
                'users' => $totalScopedUsers,
                'active_users' => $visibleIds === null
                    ? User::where('is_blocked', false)->count()
                    : User::whereIn('id', $visibleIds)->where('is_blocked', false)->count(),
                'new_users_week' => $visibleIds === null
                    ? User::where('created_at', '>=', $weekAgo)->count()
                    : User::whereIn('id', $visibleIds)->where('created_at', '>=', $weekAgo)->count(),
                'blocked_users' => $visibleIds === null
                    ? User::where('is_blocked', true)->count()
                    : User::whereIn('id', $visibleIds)->where('is_blocked', true)->count(),
                'documents' => $scopedCount('typing_documents'),
                'new_documents_week' => Schema::hasTable('typing_documents')
                    ? (($visibleIds === null ? DB::table('typing_documents') : DB::table('typing_documents')->whereIn('user_id', $visibleIds))->where('created_at', '>=', $weekAgo)->count())
                    : 0,
                'ai' => $scopedCount('ai_interactions'),
                'ai_today' => Schema::hasTable('ai_interactions')
                    ? (($visibleIds === null ? DB::table('ai_interactions') : DB::table('ai_interactions')->whereIn('user_id', $visibleIds))->whereDate('created_at', today())->count())
                    : 0,
                // Voice usage has no user attribution in its schema; expose it only in the global scope.
                'voice_seconds' => $visibleIds === null && Schema::hasTable('voice_provider_usage') ? (int) DB::table('voice_provider_usage')->sum('audio_seconds') : 0,
                'voice_requests' => $visibleIds === null && Schema::hasTable('voice_provider_usage') ? (int) DB::table('voice_provider_usage')->sum('requests') : 0,
                'organizations' => Schema::hasTable('organizations')
                    ? ($visibleOrganizationIds === null
                        ? \App\Models\Organization::count()
                        : DB::table('organizations')->whereIn('id', $visibleOrganizationIds)->count())
                    : 0,
                'teams' => Schema::hasTable('teams')
                    ? ($visibleTeamIds === null
                        ? \App\Models\Team::count()
                        : DB::table('teams')->whereIn('id', $visibleTeamIds)->count())
                    : 0,
                'audit_events' => Schema::hasTable('audit_logs')
                    ? ($visibleIds === null
                        ? DB::table('audit_logs')->count()
                        : DB::table('audit_logs')->whereIn('user_id', $visibleIds)->count())
                    : 0,
                'revenue_month' => Schema::hasTable('payments')
                    ? (int) $paidPaymentsQuery()->where('payments.created_at', '>=', $monthAgo)->sum('payments.amount_rials')
                    : 0,
                'revenue_total' => Schema::hasTable('payments')
                    ? (int) $paidPaymentsQuery()->sum('payments.amount_rials')
                    : 0,
                'tickets_open' => (int) ($ticketStats['open'] ?? 0) + (int) ($ticketStats['user_replied'] ?? 0),
                'wallet_balance' => Schema::hasTable('wallets') ? (int) $walletBalanceQuery()->sum('balance_rials') : 0,
            ],
            'charts' => [
                'labels' => $chartDays,
                'users' => Schema::hasTable('users') ? $seriesOf('users') : array_fill(0, 30, 0),
                'documents' => Schema::hasTable('typing_documents') ? $seriesOf('typing_documents') : array_fill(0, 30, 0),
                'ai' => Schema::hasTable('ai_interactions') ? $seriesOf('ai_interactions') : array_fill(0, 30, 0),
            ],
            'ticketStats' => $ticketStats,
            'topConsumers' => $canViewAnalytics ? $topConsumers : collect(),
            'recentAudit' => (Schema::hasTable('audit_logs') && $scope === AdminConsoleService::SCOPE_GLOBAL)
                ? DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                    ->latest('audit_logs.id')->limit(7)
                    ->get(['audit_logs.action', 'audit_logs.created_at', 'users.name as actor_name'])
                : collect(),
            'settings' => $globalScope ? $settings : [
                'weekly_free_pages' => '',
                'max_file_mb' => '',
                'daily_ai_requests' => '',
                'gemini_model' => '',
                'gemini_fallback_model' => '',
                'assistant_groq_model' => '',
                'can_type' => false,
                'can_ai' => false,
                'can_voice' => false,
                'can_export_docx' => false,
                'can_export_pdf' => false,
                'can_feedback' => false,
                'can_support' => false,
            ],
            'secretStatus' => $globalScope ? [
                'gemini' => (bool) (SiteSetting::read('gemini_api_key') ?: config('services.gemini.key')),
                'files' => (bool) (SiteSetting::read('files_api_key') ?: env('FILES_API_KEY')),
                'groq' => (bool) (SiteSetting::read('voice_groq_key') ?: env('GROQ_API_KEY', '')),
            ] : ['gemini' => false, 'files' => false, 'groq' => false],
            'systemHealth' => $globalScope ? [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'driver' => config('database.default'),
                'db_ok' => $this->databaseReachable(),
                'cache_ok' => $this->cacheReachable(),
                'queue_connection' => config('queue.default'),
                'env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'storage_writable' => is_writable(storage_path()),
            ] : [],
            'capabilities' => $capabilities,
            'voiceAccounts' => ($scope === AdminConsoleService::SCOPE_GLOBAL && Schema::hasTable('voice_provider_accounts'))
                ? VoiceProviderAccount::query()->get()->keyBy('provider')
                : collect(),
        ]);
    }

    /**
     * صفحه مدیریت کاربران — همان کنسول، با دامنه اعمال‌شده.
     */
    public function users(Request $request, AdminConsoleService $console)
    {
        $actor = $request->user();
        abort_unless($actor, 403);

        // صفحهٔ مدیریت کاربران فقط با مجوز users.view باز می‌شود (RBAC + دامنه).
        if (! $console->sectionAllowed($actor, 'users')) {
            abort(403, 'دسترسی به مدیریت کاربران برای نقش شما باز نیست.');
        }

        $visibleIds = $console->visibleUserIds($actor);
        $search = trim((string) $request->query('q', ''));
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $query = User::with(['wallet', 'organization'])->latest();
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($role !== '' && in_array($role, User::DASHBOARD_ROLES, true)) {
            $query->where('role', $role);
        }
        if ($status === 'blocked') {
            $query->where('is_blocked', true);
        } elseif ($status === 'active') {
            $query->where('is_blocked', false);
        }

        return view('admin.users', [
            'users' => $query->paginate(25)->withQueryString(),
            'filters' => ['q' => $search, 'role' => $role, 'status' => $status],
            'scopeSummary' => $console->scopeSummary($actor),
            'console' => $console,
        ]);
    }

    /**
     * تغییر نقش کاربر (فقط سوپرادمین؛ ثبت ممیزی).
     */
    public function updateUserRole(Request $request, User $user, AdminConsoleService $console, AuditLogService $audit)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'تغییر نقش فقط توسط مدیر اصلی ممکن است.');
        abort_unless($console->sectionAllowed($request->user(), 'access'), 403, 'تغییر نقش فقط در دامنهٔ سراسری مجاز است.');
        $console->authorizeAction($request->user(), 'users', $user->id);
        abort_if($user->isAdmin() && $user->id !== $request->user()->id, 403, 'نقش مدیر اصلی قابل تغییر نیست.');

        $d = $request->validate(['role' => ['required', 'in:' . implode(',', User::DASHBOARD_ROLES)]]);

        $old = $user->role;
        $user->update(['role' => $d['role']]);

        if (Schema::hasTable('audit_logs')) {
            $audit->record($request->user()->id, 'admin.user.role_changed', $request->ip(), [
                'target_user_id' => $user->id, 'from' => $old, 'to' => $d['role'],
            ]);
        }

        return back()->with('status', "نقش کاربر «{$user->name}» از {$old} به {$d['role']} تغییر کرد.");
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cacheReachable(): bool
    {
        $key = 'farast:health:ping:'.bin2hex(random_bytes(12));

        try {
            cache()->put($key, 1, 10);

            return (bool) cache()->get($key);
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                cache()->forget($key);
            } catch (\Throwable) {
                // Health reporting must never break the dashboard response.
            }
        }
    }

    public function supportSms()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $eventDefs = [
            'ticket_reply' => ['label' => 'پاسخ کارشناس به کاربر', 'hint' => 'وقتی پشتیبان به تیکت پاسخ می‌دهد، به موبایل کاربر پیامک می‌رود.'],
            'ticket_closed' => ['label' => 'بستن تیکت', 'hint' => 'پس از بسته شدن تیکت به کاربر اطلاع داده شود.'],
            'ticket_assigned' => ['label' => 'ارجاع تیکت به کارشناس', 'hint' => 'به کارشناس ارجاع‌شده پیامک زده شود.'],
            'ticket_created_staff' => ['label' => 'تیکت جدید (ستاد)', 'hint' => 'هشدار تیکت جدید به موبایل هشدار ستاد.'],
            'ticket_customer_reply' => ['label' => 'پاسخ کاربر با اولویت بالا', 'hint' => 'فقط برای اولویت high به کارشناس/ستاد پیامک می‌شود.'],
            'ticket_sla_warning' => ['label' => 'هشدار نزدیک به نقض SLA', 'hint' => 'دقایقی قبل از اتمام مهلت (معمولاً اولویت بالا).'],
            'ticket_sla_breach' => ['label' => 'نقض SLA', 'hint' => 'وقتی مهلت پاسخ یا حل گذشته باشد.'],
        ];

        $events = [];
        foreach ($eventDefs as $key => $meta) {
            $events[$key] = [
                'label' => $meta['label'],
                'hint' => $meta['hint'],
                'enabled' => filter_var(SiteSetting::read('sms_'.$key.'_enabled', false), FILTER_VALIDATE_BOOLEAN),
                'template' => config('support.sms_templates.'.$key) ?: config('services.kavenegar.templates.'.$key),
            ];
        }

        return view('admin.support-sms', [
            'events' => $events,
            'smsEnabled' => filter_var(SiteSetting::read('sms_enabled', true), FILTER_VALIDATE_BOOLEAN),
            'alertMobile' => (string) SiteSetting::read('support_alert_mobile', ''),
            'hasKey' => filled(config('services.kavenegar.key')),
        ]);
    }

    public function updateSupportSms(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $request->validate(['support_alert_mobile' => ['nullable', 'string', 'max:20']]);

        SiteSetting::write('sms_enabled', $request->boolean('sms_enabled') ? '1' : '0');

        foreach (['ticket_reply', 'ticket_closed', 'ticket_assigned', 'ticket_created_staff', 'ticket_customer_reply', 'ticket_sla_warning', 'ticket_sla_breach'] as $key) {
            SiteSetting::write('sms_'.$key.'_enabled', $request->boolean('sms_'.$key.'_enabled') ? '1' : '0');
        }

        $mobile = preg_replace('/\D+/', '', (string) $request->input('support_alert_mobile', '')) ?: '';
        SiteSetting::write('support_alert_mobile', $mobile);

        return back()->with('status', 'تنظیمات پیامک پشتیبانی ذخیره شد.');
    }

    public function finance(Request $request, AdminConsoleService $console)
    {
        // مجوز و دامنهٔ مالی؛ همهٔ داده‌های وابسته به کاربر باید در همین دامنه بمانند.
        $console->authorizeAction($request->user(), 'finance');
        $visibleIds = $console->visibleUserIds($request->user());
        $globalScope = $visibleIds === null;

        $monthAgo = now()->subMonth();
        $chartDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $chartDays[] = now()->subDays($i)->toDateString();
        }

        $paymentsQuery = function (string $status) use ($visibleIds) {
            $query = DB::table('payments')->where('payments.status', $status);
            if ($visibleIds !== null) {
                $query->join('orders', 'orders.id', '=', 'payments.order_id')
                    ->whereIn('orders.user_id', $visibleIds);
            }

            return $query;
        };

        $dailyRevenue = array_fill(0, 30, 0);
        $revenueSeries = [];
        if (Schema::hasTable('payments')) {
            $rows = $paymentsQuery('paid')
                ->where('payments.created_at', '>=', $chartDays[0])
                ->select('payments.created_at', 'payments.amount_rials')
                ->get();

            foreach ($rows as $row) {
                $day = \Illuminate\Support\Carbon::parse($row->created_at)->toDateString();
                $index = array_search($day, $chartDays, true);
                if ($index !== false) {
                    $dailyRevenue[$index] += (int) $row->amount_rials;
                }
            }

            $revenueSeries = [
                'total' => (int) $paymentsQuery('paid')->sum('payments.amount_rials'),
                'month' => (int) $paymentsQuery('paid')->where('payments.created_at', '>=', $monthAgo)->sum('payments.amount_rials'),
                'count' => (int) $paymentsQuery('paid')->count(),
                'pending' => (int) $paymentsQuery('pending')->count(),
            ];
        }

        $recentTransactions = collect();
        if (Schema::hasTable('wallet_transactions') && Schema::hasTable('wallets')) {
            $transactions = DB::table('wallet_transactions')
                ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
                ->join('users', 'users.id', '=', 'wallets.user_id');
            if ($visibleIds !== null) {
                $transactions->whereIn('wallets.user_id', $visibleIds);
            }
            $recentTransactions = $transactions
                ->latest('wallet_transactions.id')
                ->limit(40)
                ->get(['wallet_transactions.*', 'users.name as user_name', 'users.mobile as user_mobile']);
        }

        $invoices = collect();
        if (Schema::hasTable('farast_invoices')) {
            $invoiceQuery = DB::table('farast_invoices');
            if ($visibleIds !== null) {
                if (Schema::hasColumn('farast_invoices', 'user_id')) {
                    $invoiceQuery->whereIn('user_id', $visibleIds);
                } elseif (Schema::hasColumn('farast_invoices', 'order_id') && Schema::hasTable('orders')) {
                    $invoiceQuery->join('orders', 'orders.id', '=', 'farast_invoices.order_id')
                        ->whereIn('orders.user_id', $visibleIds)
                        ->select('farast_invoices.*');
                } else {
                    $invoiceQuery = null;
                }
            }
            if ($invoiceQuery) {
                $invoices = $invoiceQuery->latest('farast_invoices.id')->limit(20)->get();
            }
        }

        $usersQuery = User::with('wallet')->orderBy('name');
        if ($visibleIds !== null) {
            $usersQuery->whereIn('id', $visibleIds);
        }

        $walletQuery = Schema::hasTable('wallets') ? DB::table('wallets') : null;
        if ($walletQuery && $visibleIds !== null) {
            $walletQuery->whereIn('user_id', $visibleIds);
        }

        return view('admin.finance', [
            // Gateway and tax configuration are global settings, not organization-level report data.
            'taxEnabled' => $globalScope ? filter_var(SiteSetting::read('tax_enabled', true), FILTER_VALIDATE_BOOLEAN) : false,
            'taxRate' => $globalScope ? (float) SiteSetting::read('tax_rate_percent', 10) : 0,
            'gateway' => $globalScope ? (string) SiteSetting::read('payment_gateway', 'none') : 'none',
            'merchantConfigured' => $globalScope ? (bool) SiteSetting::read('gateway_merchant_id') : false,
            'users' => $usersQuery->limit(100)->get(),
            'financeStats' => $revenueSeries,
            'chartDays' => $chartDays,
            'dailyRevenue' => $dailyRevenue,
            'recentTransactions' => $recentTransactions,
            'invoices' => $invoices,
            'walletTotal' => $walletQuery ? (int) $walletQuery->sum('balance_rials') : 0,
        ]);
    }

    public function updateFinance(Request $r)
    {
        $d = $r->validate([
            'tax_enabled' => ['nullable', 'boolean'],
            'tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_gateway' => ['required', 'in:none,zarinpal'],
            'gateway_merchant_id' => ['nullable', 'string', 'max:200'],
            'zarinpal_sandbox' => ['nullable', 'boolean'],
        ]);

        SiteSetting::write('tax_enabled', $r->boolean('tax_enabled') ? '1' : '0');
        SiteSetting::write('tax_rate_percent', (string) $d['tax_rate_percent']);
        SiteSetting::write('payment_gateway', $d['payment_gateway']);
        SiteSetting::write('zarinpal_sandbox', $r->boolean('zarinpal_sandbox') ? '1' : '0');

        if (filled($d['gateway_merchant_id'] ?? null)) {
            SiteSetting::write('gateway_merchant_id', trim($d['gateway_merchant_id']), true);
        }

        return back()->with('status', 'تنظیمات مالی ذخیره شد.');
    }

    public function updateSettings(Request $r)
    {
        $data = $r->validate([
            'weekly_free_pages' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'max_file_mb' => ['nullable', 'integer', 'min:1', 'max:2048'],
            'daily_ai_requests' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'gemini_model' => ['nullable', 'string', 'max:120'],
            'can_type' => ['nullable', 'boolean'],
            'can_ai' => ['nullable', 'boolean'],
            'can_voice' => ['nullable', 'boolean'],
            'can_export_docx' => ['nullable', 'boolean'],
            'can_export_pdf' => ['nullable', 'boolean'],
            'can_feedback' => ['nullable', 'boolean'],
            'can_support' => ['nullable', 'boolean'],
            'gemini_api_key' => ['nullable', 'string', 'max:500'],
            'files_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['weekly_free_pages', 'max_file_mb', 'daily_ai_requests', 'gemini_model'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                SiteSetting::write($key, $data[$key]);
            }
        }

        foreach (CapabilityService::BOOLEAN_KEYS as $key) {
            if ($r->has($key)) {
                SiteSetting::write($key, $r->boolean($key) ? '1' : '0');
            }
        }

        if (filled($data['gemini_api_key'] ?? null)) {
            SiteSetting::write('gemini_api_key', trim($data['gemini_api_key']), true);
        }

        if (filled($data['files_api_key'] ?? null)) {
            SiteSetting::write('files_api_key', trim($data['files_api_key']), true);
        }

        return back()->with('status', 'تنظیمات کلی ذخیره شد.');
    }

    public function toggleUser(User $user, AdminConsoleService $console, AuditLogService $audit)
    {
        abort_if($user->isAdmin(), 403);
        // دامنه: مدیر سازمان/تیم فقط روی کاربران داخل دامنه خودش می‌تواند اقدام کند.
        $console->authorizeAction(auth()->user(), 'users', $user->id);

        $user->update(['is_blocked' => ! $user->is_blocked]);

        if (Schema::hasTable('audit_logs')) {
            $audit->record(auth()->id(), $user->is_blocked ? 'admin.user.blocked' : 'admin.user.unblocked', request()->ip(), ['target_user_id' => $user->id]);
        }

        return back();
    }

    public function emails(MailConfigService $mailConfig)
    {
        $flags = ['email_enabled' => true];
        foreach (array_keys(EmailService::TYPES) as $type) {
            $flags['email_'.$type.'_enabled'] = true;
        }

        foreach ($flags as $key => $default) {
            $flags[$key] = filter_var(SiteSetting::read($key, $default), FILTER_VALIDATE_BOOLEAN);
        }

        $logs = Schema::hasTable('email_logs') ? EmailLog::latest()->limit(80)->get() : collect();
        $tickets = Schema::hasTable('tickets') ? Ticket::with('user')->latest()->limit(30)->get() : collect();
        $provider = $mailConfig->currentProvider();
        $mailHost = SiteSetting::read('mail_host', env('MAIL_HOST'));
        if (is_string($mailHost) && str_contains(strtolower($mailHost), 'mailpit')) {
            $mailHost = '127.0.0.1';
        }

        return view('admin.emails', [
            'flags' => $flags,
            'types' => EmailService::TYPES,
            'logs' => $logs,
            'tickets' => $tickets,
            'mailFrom' => SiteSetting::read('mail_from_address', config('mail.from.address')),
            'mailFromName' => SiteSetting::read('mail_from_name', config('mail.from.name')),
            'mailer' => $provider,
            'providers' => MailConfigService::PROVIDERS,
            'providerConfigured' => $mailConfig->providerConfigured($provider),
            'mailHost' => $mailHost,
            'mailPort' => SiteSetting::read('mail_port', env('MAIL_PORT', 25)),
            'mailUsername' => SiteSetting::read('mail_username', env('MAIL_USERNAME')),
            'mailEncryption' => SiteSetting::read('mail_encryption', env('MAIL_ENCRYPTION')),
            'hasResendKey' => filled(SiteSetting::read('resend_api_key', env('RESEND_API_KEY'))),
            'hasBrevoKey' => filled(SiteSetting::read('brevo_api_key', env('BREVO_API_KEY'))),
            'hasSmtpPassword' => filled(SiteSetting::read('mail_password', env('MAIL_PASSWORD'))),
        ]);
    }

    public function updateEmailSettings(Request $request)
    {
        SiteSetting::write('email_enabled', $request->boolean('email_enabled') ? '1' : '0');

        foreach (array_keys(EmailService::TYPES) as $type) {
            $key = 'email_'.$type.'_enabled';
            SiteSetting::write($key, $request->boolean($key) ? '1' : '0');
        }

        SiteSetting::write('email_sync', $request->boolean('email_sync') ? '1' : '0');

        $data = $request->validate([
            'mail_provider' => ['required', 'in:log,sendmail,local,smtp,mailtrap,brevo,resend'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'mail_host' => ['nullable', 'string', 'max:200'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:200'],
            'mail_password' => ['nullable', 'string', 'max:500'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,null'],
            'resend_api_key' => ['nullable', 'string', 'max:500'],
            'brevo_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        SiteSetting::write('mail_provider', $data['mail_provider']);

        if (isset($data['mail_host']) && is_string($data['mail_host']) && str_contains(strtolower($data['mail_host']), 'mailpit')) {
            $data['mail_host'] = '127.0.0.1';
        }

        if (filled($data['mail_from_address'] ?? null)) {
            SiteSetting::write('mail_from_address', trim($data['mail_from_address']));
        }
        if (filled($data['mail_from_name'] ?? null)) {
            SiteSetting::write('mail_from_name', trim($data['mail_from_name']));
        }
        if (array_key_exists('mail_host', $data) && $data['mail_host'] !== null) {
            SiteSetting::write('mail_host', trim((string) $data['mail_host']));
        }
        if (array_key_exists('mail_port', $data) && $data['mail_port'] !== null) {
            SiteSetting::write('mail_port', (string) $data['mail_port']);
        }
        if (array_key_exists('mail_username', $data) && $data['mail_username'] !== null) {
            SiteSetting::write('mail_username', trim((string) $data['mail_username']));
        }
        if (filled($data['mail_password'] ?? null)) {
            SiteSetting::write('mail_password', trim($data['mail_password']), true);
        }
        if (array_key_exists('mail_encryption', $data)) {
            $enc = $data['mail_encryption'] === 'null' ? '' : ($data['mail_encryption'] ?? 'tls');
            SiteSetting::write('mail_encryption', $enc);
        }
        if (filled($data['resend_api_key'] ?? null)) {
            SiteSetting::write('resend_api_key', trim($data['resend_api_key']), true);
        }
        if (filled($data['brevo_api_key'] ?? null)) {
            SiteSetting::write('brevo_api_key', trim($data['brevo_api_key']), true);
        }

        return back()->with('status', 'تنظیمات ایمیل ذخیره شد.');
    }

    public function sendTestEmail(Request $request, EmailService $emailService)
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']]);

        try {
            $emailService->sendTest($data['to']);

            return back()->with('status', 'ایمیل آزمایشی ارسال شد.');
        } catch (\Throwable $e) {
            return back()->withErrors(['test_email' => 'ارسال ناموفق: '.$e->getMessage()]);
        }
    }

    public function updateVoiceProviders(Request $r)
    {
        return back()->with('status', 'از پنل اصلی تنظیمات صوتی استفاده کنید.');
    }

    public function updatePricing(Request $r)
    {
        $d = $r->validate([
            'key' => 'required|string|max:120',
            'value' => 'required|integer|min:0',
            'label' => 'required|string|max:160',
        ]);

        PricingRule::updateOrCreate(['key' => $d['key']], [
            'value' => $d['value'],
            'label' => $d['label'],
            'active' => true,
        ]);

        return back()->with('status', 'قیمت‌گذاری به‌روزرسانی شد.');
    }

    public function updateUserCapabilities(Request $r, User $user, AdminConsoleService $console)
    {
        abort_if($user->isAdmin(), 403);
        $console->authorizeAction($r->user(), 'users', $user->id);

        $data = $r->validate([
            'active' => ['nullable', 'boolean'],
            'can_type' => ['nullable', 'boolean'],
            'can_ai' => ['nullable', 'boolean'],
            'can_voice' => ['nullable', 'boolean'],
            'can_export_docx' => ['nullable', 'boolean'],
            'can_export_pdf' => ['nullable', 'boolean'],
            'can_feedback' => ['nullable', 'boolean'],
            'can_support' => ['nullable', 'boolean'],
            'weekly_free_pages' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'max_file_mb' => ['nullable', 'integer', 'min:1', 'max:2048'],
            'daily_ai_requests' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $values = [];
        foreach (['active', 'can_type', 'can_ai', 'can_voice', 'can_export_docx', 'can_export_pdf', 'can_feedback', 'can_support'] as $key) {
            $values[$key] = $r->has($key) ? $r->boolean($key) : null;
        }
        foreach (['weekly_free_pages', 'max_file_mb', 'daily_ai_requests'] as $key) {
            $values[$key] = array_key_exists($key, $data) && $data[$key] !== '' ? $data[$key] : null;
        }

        UserCapability::updateOrCreate(['user_id' => $user->id], $values);

        return back()->with('status', 'دسترسی‌های کاربر ذخیره شد.');
    }

    public function adjustWallet(Request $r, User $user, AdminConsoleService $console, AuditLogService $audit)
    {
        // تنظیم مالی فقط با مجوز بخش finance و داخل دامنه کاربر هدف.
        $console->authorizeAction($r->user(), 'finance', $user->id);

        $d = $r->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount_rials' => ['required', 'integer', 'min:1000', 'max:10000000000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($d, $user) {
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['balance_rials' => 0]);
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $before = (int) $wallet->balance_rials;

            if ($d['type'] === 'debit' && $d['amount_rials'] > $before) {
                throw new \RuntimeException('موجودی کاربر کافی نیست.');
            }

            $after = $d['type'] === 'credit' ? $before + $d['amount_rials'] : $before - $d['amount_rials'];
            $wallet->update(['balance_rials' => $after]);

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $d['type'],
                'amount_rials' => $d['amount_rials'],
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'admin',
                'reference_id' => auth()->id(),
                'description' => $d['description'] ?: ($d['type'] === 'credit' ? 'شارژ توسط مدیر' : 'کسر توسط مدیر'),
                'idempotency_key' => 'admin-'.auth()->id().'-'.bin2hex(random_bytes(12)),
            ]);
        });

        if (Schema::hasTable('audit_logs')) {
            $audit->record(auth()->id(), 'admin.wallet.adjusted', request()->ip(), [
                'target_user_id' => $user->id, 'type' => $d['type'], 'amount_rials' => $d['amount_rials'],
            ]);
        }

        return back()->with('status', 'موجودی کیف پول کاربر به‌روزرسانی شد.');
    }
}
