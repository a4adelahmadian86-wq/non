<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements)
    {
    }

    /**
     * لیست اعلان‌ها فقط برای کاربران واردشده (داشبورد) — نه فرانت‌اند عمومی.
     */
    public function index()
    {
        $user = auth()->user();

        return view('announcements', [
            'announcements' => Announcement::query()
                ->forUser($user)
                ->orderByDesc('is_pinned')
                ->latest()
                ->paginate(12),
            'pinned' => Announcement::query()
                ->forUser($user)
                ->where('is_pinned', true)
                ->latest()
                ->limit(3)
                ->get(),
        ]);
    }

    public function manage(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $q = Announcement::query()->latest();

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $q->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $q->where('is_active', false);
            }
        }

        if ($request->filled('audience') && in_array($request->audience, ['all', 'users', 'admins'], true)) {
            $q->where('audience', $request->audience);
        }

        if ($request->filled('type') && in_array($request->type, ['info', 'success', 'warning', 'danger'], true)) {
            $q->where('type', $request->type);
        }

        return view('admin.announcements', [
            'announcements' => $q->paginate(20)->withQueryString(),
            'filters' => $request->only(['status', 'audience', 'type']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $this->validated($request);
        $this->announcements->create($data, auth()->id());

        return back()->with('status', 'اعلان جدید منتشر شد و به کاربران هدف اطلاع‌رسانی شد.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $this->validated($request);
        $this->announcements->update($announcement, $data);

        return back()->with('status', 'اعلان به‌روزرسانی شد.');
    }

    public function toggle(Announcement $announcement)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->announcements->toggle($announcement);

        return back()->with('status', $announcement->fresh()->is_active ? 'اعلان فعال شد.' : 'اعلان غیرفعال شد.');
    }

    public function destroy(Announcement $announcement)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $announcement->delete();

        return back()->with('status', 'اعلان حذف شد.');
    }

    public function rebroadcast(Announcement $announcement)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $announcement->forceFill(['notified_at' => null])->saveQuietly();
        $sent = $this->announcements->broadcast($announcement->fresh());

        return back()->with('status', "اعلان مجدداً ارسال شد ({$sent} اعلان درون‌برنامه).");
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:info,success,warning,danger'],
            'audience' => ['nullable', 'in:all,users,admins'],
            'is_active' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'notify_in_app' => ['nullable', 'boolean'],
            'notify_email' => ['nullable', 'boolean'],
            'link_url' => ['nullable', 'url', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $data['audience'] = $data['audience'] ?? 'all';
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_pinned'] = $request->boolean('is_pinned', false);
        $data['notify_in_app'] = $request->boolean('notify_in_app', true);
        $data['notify_email'] = $request->boolean('notify_email', false);

        return $data;
    }
}
