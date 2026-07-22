<?php

namespace App\Core\Documents\Contracts;

use Illuminate\Support\Collection;

/**
 * How the rest of the platform reads already-issued documents.
 *
 * Separate from DocumentIssuer on purpose, the same split OrderBook keeps
 * between placing and reading an order: issuing a document is a single
 * write with strict invariants (idempotency, gap-free numbering), while
 * reading is a different question — "what has already been issued for this
 * order" — with no such invariants to protect. The kernel binds
 * NullDocumentBook; the docs module overrides it at deploy level.
 */
interface DocumentBook
{
    /**
     * Every document already issued for $orderUuid, newest first.
     *
     * Never throws: a tenant that never activated the docs module, or an
     * unknown order, simply has no documents, so a caller (the admin order
     * screen, deciding whether to show a document or an "issue" button)
     * renders normally either way.
     *
     * @return Collection<int, DocumentView>
     */
    public function forOrder(string $orderUuid): Collection;
}
