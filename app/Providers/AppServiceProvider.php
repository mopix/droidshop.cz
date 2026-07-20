<?php

namespace App\Providers;

use App\Core\Customers\Contracts\CustomerIdentity;
use App\Core\Customers\NullCustomerIdentity;
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

        // The kernel's own default for a contract a module owns. Module
        // providers register (and, per ModuleServiceProvider, boot) after
        // this provider's register() phase has already run, so
        // Modules\Customers\Providers\ModuleProvider's own bind() simply
        // overwrites this one when the module is part of the deploy — last
        // bind() wins in the container. Without this default, resolving
        // CustomerIdentity on a deploy without the module throws instead of
        // answering "no customer", which is what the contract promises.
        $this->app->bind(CustomerIdentity::class, NullCustomerIdentity::class);
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

        // The emails_month counter is what lets LimitsService answer plan
        // questions about mail: it counts queued and sent messages logged
        // this calendar month (see MailLimitCounter's docblock for why
        // queued counts too, and why failed does not).
        $this->app->make(LimitsService::class)
            ->registerCounter($this->app->make(MailLimitCounter::class));
    }
}
