<?php

namespace App\Core\Documents\Exceptions;

use RuntimeException;

/**
 * Part of the DocumentIssuer contract, so it lives with the contract.
 *
 * Thrown by the kernel's null binding (App\Core\Documents\NullDocumentIssuer) —
 * a deploy without the docs module, or a tenant that never activated it,
 * cannot issue a document at all. A caller catching this must not have to name
 * a module class, matching App\Core\Orders\Exceptions\OrderPlacementUnavailable.
 */
class DocumentIssuanceUnavailable extends RuntimeException
{
    public static function moduleOff(): self
    {
        return new self('The docs module is not active for this tenant; no document can be issued.');
    }
}
