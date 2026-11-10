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
        $inventory = StorageManagedFile::query()->where('status', '!=', 'deleted')
            ->selectRaw('drive_key, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as bytes_used, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as pending_count', ['pending_transfer', 'transfer_error'])
            ->groupBy('drive_key')->get()->keyBy('drive_key');
        $drives = collect(self::DRIVES)->map(function (array $definition, string $key) use ($volumes, $inventory) {
            $items = $volumes->get($key, collect());
            $files = $inventory->get($key);
            return [
                'key' => $key, 'title' => $definition['title'], 'region' => $definition['region'],
                'kind' => $definition['kind'], 'volumes' => $items,
                'capacity' => $items->isNotEmpty() && $items->every(fn ($v) => $v->capacity_bytes !== null) ? $items->sum('capacity_bytes') : null,
                'used' => (int) $items->sum('used_bytes'),
                'files' => (int) $items->sum('object_count') + (int) ($files->pending_count ?? 0),
                'pending' => (int) ($files->pending_count ?? 0),
                'healthy' => $items->where('status', 'healthy')->count(),
            ];
        });

        return view('admin.storage.index', compact('drives'));
    }

    public function backups()
    {
        $backups = StorageBackupRun::with('volume')->where('source_name', '!=', 'snapshot_lock')->latest()->paginate(30);
        return view('admin.storage.backups', compact('backups'));
    }

    public function requestBackup()
    {
        abort_unless(config('storage_management.backup.encryption_key'), 422, 'کلید رمزنگاری بکاپ تنظیم نشده است.');
        abort_unless(StorageVolume::query()->where('drive_key', 'foreign_backup')->where('is_active', true)->where('status', 'healthy')->exists(), 422, 'ابتدا حداقل یک هاست سالم در درایو بکاپ خارجی متصل کنید.');

        $run = StorageBackupRun::create([
            'backup_type' => 'manual',
            'source_name' => 'manual_request',
            'status' => 'queued',
            'started_at' => now(),
            'metadata' => ['request_only' => true],
        ]);
        RunStorageBackupJob::dispatch($run->id);

        return back()->with('status', 'درخواست بکاپ در صف اجرا قرار گرفت.');
    }

    public function store(Request $request, StorageConnectionService $connections)
    {
        $data = $request->validate([
            'drive_key' => ['required', Rule::in(array_keys(self::DRIVES))],
            'connection_json' => ['required', 'string', 'max:16000'],
        ]);

        $connection = json_decode($data['connection_json'], true);
        if (! is_array($connection) || json_last_error() !== JSON_ERROR_NONE) {
            return back()->withErrors(['connection_json' => 'اطلاعات اتصال باید JSON معتبر باشد.'])->withInput($request->except('connection_json'));
        }

        $driver = (string) ($connection['driver'] ?? 's3');
        if (! in_array($driver, ['s3', 'local'], true)) {
            return back()->withErrors(['connection_json' => 'درایور اعلام‌شده پشتیبانی نمی‌شود.'])->withInput($request->except('connection_json'));
        }

        $credentials = (array) ($connection['credentials'] ?? []);
        $endpoint = (string) ($connection['endpoint'] ?? ($credentials['endpoint'] ?? ''));
        $bucket = (string) ($connection['bucket'] ?? ($credentials['bucket'] ?? ''));
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $provider = trim((string) ($connection['provider'] ?? $host));
        $name = trim((string) ($connection['name'] ?? (($bucket !== '' ? $bucket.' · ' : '').($provider ?: 'فضای ذخیره‌سازی جدید'))));
        if ($name === '') $name = 'فضای ذخیره‌سازی جدید';
        foreach (['key', 'secret', 'bucket', 'region', 'endpoint', 'use_path_style_endpoint'] as $key) {
            if (array_key_exists($key, $connection) && ! array_key_exists($key, $credentials)) {
                $credentials[$key] = $connection[$key];
            }
        }

        $volume = StorageVolume::create([
            'drive_key' => $data['drive_key'],
            'name' => mb_substr($name, 0, 120),
            'provider' => $provider !== '' ? mb_substr($provider, 0, 120) : null,
            'driver' => $driver,
            'region' => isset($connection['region']) ? mb_substr((string) $connection['region'], 0, 80) : (isset($credentials['region']) ? mb_substr((string) $credentials['region'], 0, 80) : null),
            'endpoint' => $endpoint ?: null,
            'bucket' => $bucket ?: null,
            'credentials' => $credentials,
            'capacity_bytes' => isset($connection['capacity_bytes']) ? max(1, (int) $connection['capacity_bytes']) : null,
            'file_limit' => isset($connection['file_limit']) ? max(1, (int) $connection['file_limit']) : null,
            'max_file_bytes' => isset($connection['max_file_bytes']) ? max(1, (int) $connection['max_file_bytes']) : null,
            'priority' => max(1, min(10000, (int) ($connection['priority'] ?? 100))),
            'status' => 'unverified',
            'metadata' => ['provider_metadata' => (array) ($connection['metadata'] ?? [])],
        ]);

        try {
            $connections->verify($volume, true);
            return redirect()->route('admin.storage.index')->with('status', 'اتصال با موفقیت بررسی و ثبت شد.');
        } catch (Throwable $e) {
            $volume->update(['status' => 'error', 'last_checked_at' => now(), 'metadata' => ['last_error' => mb_substr($e->getMessage(), 0, 500)]]);
            return redirect()->route('admin.storage.index')->with('warning', 'اتصال ثبت شد اما آزمون سلامت ناموفق بود: '.$e->getMessage());
        }
    }

    public function show(StorageVolume $volume)
    {
        $files = $volume->files()->latest()->paginate(50);
        $backups = StorageBackupRun::where('storage_volume_id', $volume->id)->latest()->limit(30)->get();
        return view('admin.storage.volume', compact('volume', 'files', 'backups'));
    }

    public function verify(StorageVolume $volume, StorageConnectionService $connections)
    {
        try {
            $connections->verify($volume, true);
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