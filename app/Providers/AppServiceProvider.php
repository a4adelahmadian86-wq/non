<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Services\SupportTicketService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupportTicketService::class);
    }

    public function boot(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);

        Event::listen(Login::class, function (Login $event) {
            try {
                app(SupportTicketService::class)->claimGuestTickets($event->user);
            } catch (\Throwable) {
            }
        });
    }
}
