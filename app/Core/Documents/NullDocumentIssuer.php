<?php

namespace App\Core\Documents;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;

/**
 * The binding in force when the docs module is off. Any attempt to issue is a
 * hard error for an in-app caller (the admin button is gated behind the module,
 * so it is never reached there); the auto-issue listeners live in the module,
 * so they simply do not exist when it is off. A guest checkout that never asks
 * for a document is entirely unaffected.
 */
final class NullDocumentIssuer implements DocumentIssuer
{
    public function issue(string $orderUuid, string $type = 'invoice'): DocumentView
    {
        throw DocumentIssuanceUnavailable::moduleOff();
    }

    public function forOrder(string $orderUuid): array
    {
        return [];
    }
}
