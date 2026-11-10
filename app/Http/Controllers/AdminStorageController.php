<?php

namespace App\Http\Controllers;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use App\Services\StorageBackupService;
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
        $volumes = StorageVolume::withCount(['files' => fn ($query) => $query->where('status', 'active'), 'replicas' => fn ($query) => $query->where('status', 'active')])->withSum(['files' => fn ($query) => $query->where('status', 'active')], 'size_bytes')->orderBy('drive_key')->orderBy('priority')->get()->groupBy('drive_key');
        $drives = collect(self::DRIVES)->map(function (array $definition, string $key) use ($volumes) {
            $items = $volumes->get($key, collect());
            return [
                'key' => $key, 'title' => $definition['title'], 'region' => $definition['region'],
                'kind' => $definition['kind'], 'volumes' => $items,
                'capacity' => $items->isNotEmpty() && $items->every(fn ($v) => $v->capacity_bytes !== null) ? $items->sum('capacity_bytes') : null,
                'used' => $items->sum('used_bytes'),
                'files' => $items->sum('files_count') + $items->sum('replicas_count') + $items->sum('backups_count'),
                'healthy' => $items->where('status', 'healthy')->count(),
            ];
        });

        return view('admin.storage.index', compact('drives'));
    }

    public function backups()
    {
        $backups = StorageBackupRun::with('volume')->latest()->paginate(30);
        return view('admin.storage.backups', compact('backups'));
    }

    public function runBackup(StorageBackupService $backups)
    {
        $run = $backups->runWeekly();
        if ($run->status === 'completed') {
            return redirect()->route('admin.storage.backups')->with('status', 'نسخه پشتیبان با موفقیت ایجاد و صحت‌سنجی شد.');
        }

        return redirect()->route('admin.storage.backups')->with('warning', $run->error_message ?: 'تهیه نسخه پشتیبان ناموفق بود.');
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

        $endpoint = $connection['endpoint'] ?? ($credentials['endpoint'] ?? null);
        if ($driver === 's3' && ! $endpoint && ! empty($connection['region'])) {
            $endpoint = 'https://s3.'.trim((string) $connection['region']).'.amazonaws.com';
        }
        if ($driver === 's3' && (empty($credentials['key']) || empty($credentials['secret']) || empty($connection['bucket'] ?? $credentials['bucket'] ?? null) || empty($endpoint))) {
            return back()->withErrors(['connection_json' => 'برای اتصال S3، آدرس سرویس، Bucket، Access Key و Secret Key لازم است.'])->withInput($request->except('connection_json'));
        }
        $detectedProvider = $connection['provider'] ?? ($driver === 's3' ? $connections->detectProvider($endpoint) : 'فضای محلی');
        $endpointHost = (string) parse_url((string) $endpoint, PHP_URL_HOST);
        $name = trim((string) ($connection['name'] ?? ($endpointHost !== '' ? $endpointHost : $detectedProvider)));
        if ($name === '') {
            return back()->withErrors(['connection_json' => 'نام سرویس در اطلاعات اتصال مشخص نشده است.'])->withInput($request->except('connection_json'));
        }

        $credentials = (array) ($connection['credentials'] ?? []);
        foreach (['key', 'secret', 'bucket', 'region', 'endpoint', 'use_path_style_endpoint'] as $key) {
            if (array_key_exists($key, $connection) && ! array_key_exists($key, $credentials)) {
                $credentials[$key] = $connection[$key];
            }
        }

        $volume = StorageVolume::create([
            'drive_key' => $data['drive_key'],
            'name' => mb_substr($name, 0, 120),
            'provider' => mb_substr((string) $detectedProvider, 0, 120),
            'driver' => $driver,
            'region' => isset($connection['region']) ? mb_substr((string) $connection['region'], 0, 80) : (isset($credentials['region']) ? mb_substr((string) $credentials['region'], 0, 80) : null),
            'endpoint' => $endpoint,
            'bucket' => $connection['bucket'] ?? ($credentials['bucket'] ?? null),
            'credentials' => $credentials,
            'capacity_bytes' => null,
            'file_limit' => null,
            'max_file_bytes' => null,
            'priority' => max(1, min(10000, (int) ($connection['priority'] ?? 100))),
            'status' => 'unverified',
            'metadata' => ['provider_metadata' => (array) ($connection['metadata'] ?? [])],
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
        $files = $volume->files()->with('replicaVolume')->where('status', 'active')->latest()->paginate(50, ['*'], 'files_page');
        $replicaFiles = \App\Models\StorageManagedFile::query()->with('volume')->where('replicated_to_volume_id', $volume->id)->where('status', 'active')->latest()->paginate(50, ['*'], 'replicas_page');
        $backups = $volume->backups()->where('status', 'completed')->latest()->paginate(30, ['*'], 'backups_page');
        return view('admin.storage.volume', compact('volume', 'files', 'replicaFiles', 'backups'));
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