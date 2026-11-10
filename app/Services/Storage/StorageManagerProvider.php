<?php

namespace App\Services\Storage;

use Illuminate\Support\ServiceProvider;

class StorageManagerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(S3CompatibleAdapter::class);
        $this->app->singleton(StorageManager::class);
        $this->app->singleton(StorageTransferService::class);
    }
}