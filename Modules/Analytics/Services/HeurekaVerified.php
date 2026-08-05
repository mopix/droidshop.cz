<?php

namespace Modules\Analytics\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Storefront\Support\ShopModules;

/**
 * Heureka "Ověřeno zákazníky": hands a completed order to Heureka so it can
 * send the customer a satisfaction questionnaire.
 *
 * Outside the consent gate, and that is deliberate rather than an oversight.
 * It stores nothing in the visitor's browser — no cookie, no local storage —
 * so § 89 odst. 3 does not apply. The lawful basis is the tenant's legitimate
 * interest in feedback on their own sale, which is why the privacy-notice
 * template shipped with wave 3.2 names it: the customer has to be able to
 * find out and object, but not to be asked for consent first.
 *
 * Server-to-server rather than a browser widget, for two reasons: the
 * customer's e-mail never passes through the page (an ad blocker or a
 * mistyped script would otherwise leak it), and it keeps working when the
 * customer closes the tab the moment the order goes through.
 */
class HeurekaVerified
{
    private const ENDPOINT = 'https://ssl.heureka.cz/direct/i/order/';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ShopModules $modules,
    ) {}

    public function report(OrderView $order): void
    {
        if (! $this->modules->has('analytics')) {
            return;
        }

        $values = $this->settings->all('analytics');

        if (! ($values['heureka_enabled'] ?? false)) {
            return;
        }

        $key = (string) ($values['heureka_api_key'] ?? '');

        if ($key === '') {
            return;
        }

        $this->send($key, $order);
    }

    private function send(string $key, OrderView $order): void
    {
        try {
            $response = Http::timeout(5)->asForm()->post(self::ENDPOINT, [
                'id' => $key,
                'email' => $order->orderEmail(),
                'orderId' => $order->orderNumber(),
                'productId' => $this->productIds($order),
            ]);

            if ($response->failed()) {
                Log::warning('Heureka Ověřeno zákazníky refused an order.', [
                    'order' => $order->orderNumber(),
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a questionnaire cost a customer their order
            // confirmation. This runs after the order is already committed,
            // so the only thing a failure may do is get logged.
            Log::warning('Heureka Ověřeno zákazníky could not be reached.', [
                'order' => $order->orderNumber(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function productIds(OrderView $order): array
    {
        return $order->orderItems()
            ->map(fn ($item) => (string) ($item->sku ?? $item->name ?? ''))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();
    }
}
