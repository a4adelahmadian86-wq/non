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

    public function index()
    {
        return view('announcements', [
            'announcements' => Announcement::visible()->orderByDesc('is_pinned')->latest()->paginate(12),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->announcements->create($data, auth()->id());

        return back()->with('status', 'اعلان جدید منتشر شد و به کاربران هدف اطلاع‌رسانی شد.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $this->validated($request);
        $this->announcements->update($announcement, $data);

        return back()->with('status', 'اعلان به‌روزرسانی شد.');
    }

    public function toggle(Announcement $announcement)
    {
        $this->announcements->toggle($announcement);

        return back()->with('status', $announcement->fresh()->is_active ? 'اعلان فعال شد.' : 'اعلان غیرفعال شد.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return back()->with('status', 'اعلان حذف شد.');
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
