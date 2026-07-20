<?php

namespace App\Providers;

use App\Core\Limits\LimitsService;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailLimitCounter;
use App\Core\Mail\QueuedMailService;
use App\Core\Storage\StorageLimitCounter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // LimitsService holds registered counters, so it must be one instance
        // for the whole request or a counter registered here would be invisible
        // to the caller that checks the limit.
        $this->app->singleton(LimitsService::class);

        $this->app->singleton(
            MailService::class,
            QueuedMailService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The storage_mb counter is the first concrete LimitCounter. Registered
        // at boot so LimitsService can answer storage questions from anywhere.
        $this->app->make(LimitsService::class)
            ->registerCounter($this->app->make(StorageLimitCounter::class));

        // The emails_month counter closes the gap left when MailService
        // shipped without a limit: until now, e-mail usage always read as
        // zero in the superadmin tenant detail.
        $this->app->make(LimitsService::class)
            ->registerCounter($this->app->make(MailLimitCounter::class));
    }
}
