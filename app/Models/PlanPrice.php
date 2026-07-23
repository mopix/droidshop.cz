<?php

namespace App\Models;

use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's Stripe price for one billing interval. Netenantová tabulka
 * (platformní katalog jako plans) — allowlist v SchemaConventionTest.
 */
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_amount' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
