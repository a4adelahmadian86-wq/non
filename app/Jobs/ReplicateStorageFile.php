<?php

namespace App\Jobs;

use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReplicateStorageFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $timeout = 900;

    public function __construct(public readonly int $fileId)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(StorageManager $storage): void
    {
        $storage->replicate($this->fileId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
