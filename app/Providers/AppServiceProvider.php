<?php

namespace App\Providers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\NullCartRepository;
use App\Core\Customers\Contracts\CustomerIdentity;
use App\Core\Customers\NullCustomerIdentity;
use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\NullDocumentBook;
use App\Core\Documents\NullDocumentIssuer;
use App\Core\Limits\LimitsService;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailLimitCounter;
use App\Core\Mail\QueuedMailService;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Orders\NullOrderBook;
use App\Core\Orders\NullOrderPlacement;
use App\Core\Orders\NullOrderSettlement;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use App\Core\Payments\NullPaymentGatewayRegistry;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Shipping\NullPaymentOptions;
use App\Core\Shipping\NullShippingOptions;
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

        // Same pattern for the shipping/payment contracts checkout consumes:
        // guest-safe defaults so app(ShippingOptions::class) and
        // app(PaymentOptions::class) resolve even on a deploy without the
        // shipping module. Modules\Shipping\Providers\ModuleProvider overwrites
        // both when the module is present.
        $this->app->bind(ShippingOptions::class, NullShippingOptions::class);
        $this->app->bind(PaymentOptions::class, NullPaymentOptions::class);

        // Same pattern for the cart: a guest-safe default so
        // app(CartRepository::class) resolves even on a deploy without the
        // checkout module. Modules\Checkout\Providers\ModuleProvider
        // overwrites it.
        $this->app->bind(CartRepository::class, NullCartRepository::class);

        // Same pattern for orders: app(OrderPlacement::class) and
        // app(OrderBook::class) resolve even on a deploy without the orders
        // module. Modules\Orders\Providers\ModuleProvider overwrites both.
        // Unlike the carts/shipping defaults, NullOrderPlacement::place()
        // throws rather than pretending to succeed — see its own docblock.
        $this->app->bind(OrderPlacement::class, NullOrderPlacement::class);
        $this->app->bind(OrderBook::class, NullOrderBook::class);
        $this->app->bind(OrderSettlement::class, NullOrderSettlement::class);

        // Same pattern for the payment gateway registry: a guest-safe default
        // so app(PaymentGatewayRegistry::class) resolves on a deploy without
        // the payments module. Its for() returns null and available() is empty,
        // so checkout offers no online method and never attempts a redirect.
        // Modules\Payments\Providers\ModuleProvider overwrites it with a
        // registry that knows the deployed, tenant-configured drivers.
        $this->app->bind(PaymentGatewayRegistry::class, NullPaymentGatewayRegistry::class);

        // Same pattern for document issuance: app(DocumentIssuer::class)
        // resolves even on a deploy without the docs module. Unlike the
        // guest-safe checkout defaults, NullDocumentIssuer::issue() throws
        // rather than pretending a document was issued — see its own
        // docblock. Modules\Docs\Providers\ModuleProvider overwrites it.
        $this->app->bind(DocumentIssuer::class, NullDocumentIssuer::class);

        // The read side of documents is its own contract (DocumentBook),
        // the same split OrderBook/OrderPlacement keeps — see DocumentBook's
        // docblock. Guest-safe like OrderBook/ShippingOptions: an empty
        // Collection, never a throw. Modules\Docs\Providers\ModuleProvider
        // overwrites it.
        $this->app->bind(DocumentBook::class, NullDocumentBook::class);
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
