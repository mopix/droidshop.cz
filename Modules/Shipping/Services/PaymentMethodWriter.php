<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Shipping\Models\PaymentMethod;

/**
 * Every write to a payment method goes through here. The one thing a controller
 * must not be trusted to remember: the encrypted account is overwritten only
 * when a new value actually arrives, so opening and saving a form without
 * touching the account never blanks it.
 */
class PaymentMethodWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentMethod
    {
        return PaymentMethod::query()->create($this->applySettings($attributes, isCreate: true));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentMethod $method, array $attributes): PaymentMethod
    {
        $method->fill($this->applySettings($attributes, isCreate: false, existing: $method))->save();

        return $method;
    }

    public function delete(PaymentMethod $method): void
    {
        $method->delete();
    }

    /**
     * Rewrites gapped positions from the full ordered list. Tenant-scoped like
     * its shipping counterpart.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                PaymentMethod::query()
                    ->whereKey($id)
                    ->update(['position' => ($position + 1) * 10]);
            }
        });
    }

    /**
     * Folds the submitted credentials into the encrypted `settings`, or leaves
     * the stored ones alone. Two providers keep secrets here — bank transfer
     * (the QR account) and Comgate (merchant/secret) — and both share one rule:
     * a secret is overwritten only when a new value actually arrives, so
     * opening and saving a form without re-typing it never blanks it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applySettings(array $attributes, bool $isCreate, ?PaymentMethod $existing = null): array
    {
        $account = $this->pull($attributes, 'account');
        $merchant = $this->pull($attributes, 'merchant');
        $secret = $this->pull($attributes, 'secret');
        $test = (bool) ($attributes['test'] ?? false);
        unset($attributes['test']);

        $provider = $attributes['provider'] ?? $existing?->provider;

        if ($provider === PaymentMethod::PROVIDER_BANK_TRANSFER) {
            return $this->foldSecret($attributes, $isCreate, $account !== null ? ['account' => $account] : null);
        }

        if ($provider === PaymentMethod::PROVIDER_COMGATE) {
            // merchant and the test flag are not secret and are always written;
            // the secret follows the keep-on-update rule.
            $settings = ['merchant' => (string) $merchant, 'test' => $test];

            if ($secret !== null) {
                $settings['secret'] = $secret;
            } elseif (! $isCreate) {
                $stored = $existing?->settings['secret'] ?? null;
                if ($stored !== null) {
                    $settings['secret'] = $stored;
                }
            }

            $attributes['settings'] = $settings;

            return $attributes;
        }

        // Cash on delivery holds no credential; clear any that lingered from a
        // provider switch.
        $attributes['settings'] = null;

        return $attributes;
    }

    /**
     * Writes the given settings, or — on an update with nothing new — leaves the
     * stored column untouched so a blank re-save keeps the secret.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    private function foldSecret(array $attributes, bool $isCreate, ?array $settings): array
    {
        if ($settings !== null) {
            $attributes['settings'] = $settings;
        } elseif ($isCreate) {
            $attributes['settings'] = null;
        } else {
            unset($attributes['settings']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pull(array &$attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;
        unset($attributes[$key]);
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
