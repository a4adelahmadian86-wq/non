<?php

namespace App\Services;

use App\Models\StoreProductIntakeSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreProductIntakeService
{
    public const PRODUCT_TYPES = ['digital_file', 'course'];

    public function start(User $user, string $productType): StoreProductIntakeSession
    {
        if (! in_array($productType, self::PRODUCT_TYPES, true)) {
            throw ValidationException::withMessages([
                'product_type' => 'نوع محصول انتخاب‌شده معتبر نیست.',
            ]);
        }

        return DB::transaction(fn () => StoreProductIntakeSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'product_type' => $productType,
            'status' => 'selecting_files',
            'metadata' => ['file_count' => 0, 'total_size_bytes' => 0],
            'expires_at' => now()->addHours(2),
        ]));
    }

    public function assertOwnedAndActive(StoreProductIntakeSession $session, User $user): void
    {
        abort_unless((int) $session->user_id === (int) $user->id, 404);
        abort_if($session->expires_at === null || $session->expires_at->isPast(), 410, 'مهلت این فرایند به پایان رسیده است؛ یک فرایند تازه آغاز کنید.');
        abort_if(in_array($session->status, ['cancelled', 'expired', 'submitted', 'completed'], true), 409, 'این فرایند دیگر قابل ویرایش نیست.');
        abort_unless(in_array($session->product_type, self::PRODUCT_TYPES, true), 409);
    }

    public function progress(StoreProductIntakeSession $session): array
    {
        $ingestions = $session->ingestions()->get([
            'id', 'status', 'original_name', 'mime', 'size_bytes', 'inspection_json',
            'supervisor_status', 'antivirus_status', 'is_primary',
        ]);
        // Rejected/cancelled records remain as an audit trail, but must not block
        // completion or inflate the active intake's file and size counters.
        $active = $ingestions->whereIn('status', ['inspected', 'uploaded'])->values();

        return [
            'file_count' => $active->count(),
            'uploaded_count' => $active->where('status', 'uploaded')->count(),
            'pending_inspection_count' => $active->where('status', 'inspected')->count(),
            'review_pending_count' => $active->whereIn('supervisor_status', ['pending', 'queued', 'reviewing', 'awaiting_supervisor'])->count(),
            'review_completed_count' => $active->where('status', 'uploaded')->where('supervisor_status', 'completed')->count(),
            'review_blocked_count' => $active->where('status', 'uploaded')->whereIn('supervisor_status', ['blocked', 'failed', 'manual_review_required'])->count(),
            'cancelled_count' => $ingestions->whereIn('status', ['rejected', 'cancel_pending'])->count(),
            'cleanup_pending_files' => $ingestions->where('status', 'cancel_pending')->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->original_name,
                'size_bytes' => $item->size_bytes,
            ])->values()->all(),
            'archive_file_count' => $active->sum(fn ($item) => (int) data_get($item->inspection_json, 'archive_entry_count', 0)),
            'total_size_bytes' => (int) $active->sum('size_bytes'),
            'files' => $active->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->original_name,
                'mime' => $item->mime,
                'size_bytes' => $item->size_bytes,
                'status' => $item->status,
                'supervisor_status' => $item->supervisor_status,
                'antivirus_status' => $item->antivirus_status,
                'is_primary' => $item->is_primary,
            ])->values()->all(),
        ];
    }
}
