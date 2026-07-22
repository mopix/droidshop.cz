<?php

namespace App\Core\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency log for Stripe webhook deliveries. Non-tenant: Stripe redelivers
 * at-least-once, and a repeat event id must be a no-op — see
 * App\Core\Billing\StripeWebhookHandler.
 */
class StripeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
