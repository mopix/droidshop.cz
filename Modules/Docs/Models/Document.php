<?php

namespace Modules\Docs\Models;

use App\Core\Documents\Contracts\DocumentView;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

class Document extends Model implements DocumentView
{
    use BelongsToTenant;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_PROFORMA = 'proforma';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier' => 'array',
            'customer' => 'array',
            'items' => 'array',
            'vat_summary' => 'array',
            'total' => MoneyCast::class,
            'issued_at' => 'datetime',
            'taxable_at' => 'date',
            'due_at' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * A document is a legal record: once issued it must never be edited or
     * deleted, only superseded by a credit note (spec §16.6 AK). Only the two
     * post-issue side channels — the generated PDF path and the sent timestamp
     * — may still be written. Everything else, and any delete, is refused at
     * the model so no controller path can accidentally mutate the books.
     */
    protected static function booted(): void
    {
        static::updating(function (self $doc): void {
            $mutable = ['pdf_path', 'sent_at', 'updated_at'];
            $touched = array_keys($doc->getDirty());

            if (array_diff($touched, $mutable) !== []) {
                throw new RuntimeException('An issued document is immutable; only pdf_path and sent_at may change.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('An issued document cannot be deleted; issue a credit note instead.');
        });
    }

    public function documentNumber(): string
    {
        return $this->number;
    }

    public function documentType(): string
    {
        return $this->type;
    }

    public function documentOrderUuid(): string
    {
        // order_uuid is a denormalised snapshot inside the customer JSON, put
        // there by InvoiceSnapshot at issue time so a document never has to
        // re-read the live order to name it.
        return (string) ($this->customer['order_uuid'] ?? '');
    }

    public function documentTotal(): Money
    {
        return $this->total;
    }

    public function documentCurrency(): string
    {
        return $this->currency;
    }

    public function documentIssuedAt(): Carbon
    {
        return $this->issued_at;
    }

    public function documentPdfPath(): ?string
    {
        return $this->pdf_path;
    }

    public function documentSentAt(): ?Carbon
    {
        return $this->sent_at;
    }
}
