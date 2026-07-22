<?php

namespace App\Core\Documents\Contracts;

use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;

/**
 * Issuing a document for an order, from outside the docs module.
 *
 * The write side of invoicing, reached by the orders/payments modules and the
 * admin the same way OrderPlacement/OrderSettlement are — through a kernel
 * contract, never the docs model. The kernel binds NullDocumentIssuer; the
 * docs module overrides it with InvoiceIssuer at deploy level.
 */
interface DocumentIssuer
{
    /**
     * Issues a document of $type for the order with $orderUuid, or returns the
     * existing one. Idempotent: a second call for the same (order, type) must
     * not allocate a new number nor write a second row.
     *
     * @param  string  $type  one of invoice|proforma|credit_note; wave 1.5 issues only invoice
     *
     * @throws DocumentIssuanceUnavailable when no implementation is active
     */
    public function issue(string $orderUuid, string $type = 'invoice'): DocumentView;

    /**
     * Every document already issued for $orderUuid, newest first — read
     * companion to issue(), the same split OrderBook keeps between placing
     * and reading an order. Unlike issue(), this never throws: a tenant that
     * never activated the docs module, or an unknown order, simply has no
     * documents, so the admin order screen (which calls this to decide
     * whether to show a document or an "issue" button) renders normally
     * either way.
     *
     * @return list<DocumentView>
     */
    public function forOrder(string $orderUuid): array;
}
