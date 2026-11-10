<?php

namespace App\Http\Controllers;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminStorageController extends Controller
{
    private const DRIVES = [
        'iran_primary' => ['title' => 'فضای ذخیره‌سازی ایران', 'region' => 'ایران', 'kind' => 'operational'],
        'iran_backup' => ['title' => 'بکاپ ایران', 'region' => 'ایران', 'kind' => 'backup'],
        'foreign_primary' => ['title' => 'فضای ذخیره‌سازی خارج', 'region' => 'خارج از ایران', 'kind' => 'operational'],
        'foreign_backup' => ['title' => 'بکاپ خارج', 'region' => 'خارج از ایران', 'kind' => 'backup'],
    ];

    public function index()
    {
        $volumes = StorageVolume::withCount('files')->withSum('files', 'size_bytes')->orderBy('drive_key')->orderBy('priority')->get()->groupBy('drive_key');
        $drives = collect(self::DRIVES)->map(function (array $definition, string $key) use ($volumes) {
            $items = $volumes->get($key, collect());
            return [
                'key' => $key, 'title' => $definition['title'], 'region' => $definition['region'],
                'kind' => $definition['kind'], 'volumes' => $items,
                'capacity' => $items->isNotEmpty() && $items->every(fn ($v) => $v->capacity_bytes !== null) ? $items->sum('capacity_bytes') : null,
                'used' => $items->sum('files_sum_size_bytes'),
                'files' => $items->sum('files_count'),
                'healthy' => $items->where('status', 'healthy')->count(),
            ];
        });

        return view('admin.storage.index', compact('drives'));
    }

    public function backups()\n    {\n        $backups = StorageBackupRun::with('volume')->latest()->paginate(30);\n        return view('admin.storage.backups', compact('backups'));\n    }\n\n    public function store(Request $request, StorageConnectionService $connections)
    {
        $data = $request->validate([
            'drive_key' => ['required', Rule::in(array_keys(self::DRIVES))],
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in(['s3', 'local'])],
            'provider' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:80'],
            'endpoint' => ['nullable', 'url', 'max:500'],
            'bucket' => ['nullable', 'string', 'max:190'],
            'credentials_json' => ['required_if:driver,s3', 'nullable', 'string', 'max:12000'],
            'capacity_bytes' => ['nullable', 'integer', 'min:1'],
            'file_limit' => ['nullable', 'integer', 'min:1'],
            'max_file_bytes' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $credentials = [];
        if ($data['driver'] === 's3') {
            $credentials = json_decode((string) ($data['credentials_json'] ?? ''), true);
            if (! is_array($credentials) || json_last_error() !== JSON_ERROR_NONE) {
                return back()->withErrors(['credentials_json' => 'اطلاعات اتصال باید JSON معتبر باشد.'])->withInput($request->except('credentials_json'));
            }
        }

        $volume = StorageVolume::create([
            'drive_key' => $data['drive_key'], 'name' => $data['name'],
            'provider' => $data['provider'] ?? null, 'driver' => $data['driver'],
            'region' => $data['region'] ?? null, 'endpoint' => $data['endpoint'] ?? ($credentials['endpoint'] ?? null),
            'bucket' => $data['bucket'] ?? ($credentials['bucket'] ?? null),
            'credentials' => $credentials,
            'capacity_bytes' => $data['capacity_bytes'] ?? null,
            'file_limit' => $data['file_limit'] ?? null,
            'max_file_bytes' => $data['max_file_bytes'] ?? null,
            'priority' => $data['priority'] ?? 100, 'status' => 'unverified',
        ]);

        try {
            $connections->verify($volume);
            return redirect()->route('admin.storage.index')->with('status', 'اتصال با موفقیت بررسی و ثبت شد.');
        } catch (Throwable $e) {
            $volume->update(['status' => 'error', 'last_checked_at' => now(), 'metadata' => ['last_error' => mb_substr($e->getMessage(), 0, 500)]]);
            return redirect()->route('admin.storage.index')->with('warning', 'اتصال ثبت شد اما آزمون سلامت ناموفق بود: '.$e->getMessage());
        }
    }

    public function show(StorageVolume $volume)
    {
        $files = $volume->files()->latest()->paginate(50);
        return view('admin.storage.volume', compact('volume', 'files'));
    }

    public function verify(StorageVolume $volume, StorageConnectionService $connections)
    {
        try {
            $connections->verify($volume);
            return back()->with('status', 'آزمون خواندن، نوشتن و حذف موفق بود.');
        } catch (Throwable $e) {
            $volume->update(['status' => 'error', 'last_checked_at' => now()]);
            return back()->with('warning', 'آزمون اتصال ناموفق بود: '.$e->getMessage());
        }
    }

    public function toggle(StorageVolume $volume)
    {
        $volume->update(['is_active' => ! $volume->is_active]);
        return back()->with('status', 'وضعیت اتصال به‌روزرسانی شد.');
    }

    public function destroy(StorageVolume $volume)
    {
        abort_if($volume->files()->exists(), 409, 'ابتدا فایل‌های این فضا را منتقل کنید؛ فضای دارای فایل حذف نمی‌شود.');
        $volume->delete();
        return redirect()->route('admin.storage.index')->with('status', 'اتصال حذف شد.');
    }
}