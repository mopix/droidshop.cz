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
        return PaymentMethod::query()->create($this->applyAccount($attributes, isCreate: true));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentMethod $method, array $attributes): PaymentMethod
    {
        $method->fill($this->applyAccount($attributes, isCreate: false, existing: $method))->save();

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
     * Folds the submitted account into the encrypted `settings`, or leaves the
     * stored one alone.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyAccount(array $attributes, bool $isCreate, ?PaymentMethod $existing = null): array
    {
        $account = $attributes['account'] ?? null;
        unset($attributes['account']);
        $account = is_string($account) ? trim($account) : null;

        $provider = $attributes['provider'] ?? $existing?->provider;

        if ($provider !== PaymentMethod::PROVIDER_BANK_TRANSFER) {
            // No secret belongs on cash on delivery; clear any that lingered
            // from a provider switch.
            $attributes['settings'] = null;

            return $attributes;
        }

        if ($account !== null && $account !== '') {
            // A new account was entered — encrypt and store it.
            $attributes['settings'] = ['account' => $account];
        } elseif ($isCreate) {
            $attributes['settings'] = null;
        } else {
            // Update with no new account: keep the stored one by not writing
            // the column at all.
            unset($attributes['settings']);
        }

        return $attributes;
    }
}
