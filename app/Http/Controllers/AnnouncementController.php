<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        return view('announcements', ['announcements' => Announcement::visible()->latest()->paginate(12)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:info,success,warning,danger'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['created_by'] = auth()->id();
        Announcement::create($data);
        return back()->with('status', 'اعلان جدید منتشر شد.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:info,success,warning,danger'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $announcement->update($data);
        return back()->with('status', 'اعلان به‌روزرسانی شد.');
    }

    public function toggle(Announcement $announcement)
    {
        $announcement->update(['is_active' => !$announcement->is_active]);
        return back()->with('status', $announcement->is_active ? 'اعلان فعال شد.' : 'اعلان غیرفعال شد.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return back()->with('status', 'اعلان حذف شد.');
    }
}
