<?php

namespace Modules\Docs\Services\Contracts;

use App\Core\Orders\Contracts\OrderView;

/**
 * One document type's rule, consumed by DocumentWriter. The writer owns the
 * shared, invariant-heavy mechanics (numbering, idempotency, immutable insert,
 * PDF dispatch); the implementer owns only what differs per type — the snapshot
 * and which series/prefix it draws from. Registered by type in
 * DocumentIssuerRegistry.
 */
interface TypedDocumentIssuer
{
    /** The Document::TYPE_* this issuer produces. */
    public function type(): string;

    /**
     * The immutable snapshot for $order: supplier/customer/items/vat_summary/
     * total/currency plus the Carbon dates issued_at/taxable_at/due_at. Must NOT
     * include order_id/type/number/series — the writer sets those.
     *
     * @return array<string, mixed>
     */
    public function build(OrderView $order): array;

    /** The SequenceService series base (config), before the year is applied. */
    public function seriesBase(): string;

    /** The tenant-configured number prefix for this type. */
    public function prefix(): string;
}
