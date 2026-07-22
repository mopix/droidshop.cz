<?php

namespace App\Core\Billing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription invoice the platform issues to a tenant. Non-tenant (no
 * BelongsToTenant scope): it lives in the platform ledger, not a shop's books.
 * Immutable snapshot — only pdf_path and sent_at may change after issue.
 */
class PlatformInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier' => 'array',
            'customer' => 'array',
            'vat_summary' => 'array',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'issued_at' => 'datetime',
            'taxable_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    private const MUTABLE = ['pdf_path', 'sent_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            foreach (array_keys($invoice->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new \RuntimeException("PlatformInvoice is immutable; cannot change [{$column}].");
                }
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('PlatformInvoice cannot be deleted (accounting record).');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'billed_tenant_id');
    }
}
