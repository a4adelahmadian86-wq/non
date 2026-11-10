<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Services\Storage\StorageRoutingAdapter;
use App\Services\StorageManager;
use App\Services\SupportTicketService;
use Illuminate\Auth\Events\Login;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupportTicketService::class);
        $this->app->singleton(StorageManager::class);
    }

    public function boot(): void
    {
        Storage::extend('farast', function ($app, array $config) {
            $adapter = new StorageRoutingAdapter($app->make(StorageManager::class));
            $filesystem = new Filesystem($adapter, $config);
            return new LaravelFilesystemAdapter($filesystem, $adapter, $config);
        });

        Gate::policy(Ticket::class, TicketPolicy::class);

        Event::listen(Login::class, function (Login $event): void {
            try {
                app(SupportTicketService::class)->claimGuestTickets($event->user);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}