<?php

namespace App\Core\Documents\Contracts;

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
     */
    public function issue(string $orderUuid, string $type = 'invoice'): DocumentView;
}
