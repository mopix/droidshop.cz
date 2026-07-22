<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use InvalidArgumentException;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The kernel DocumentIssuer, dispatching by type to a TypedDocumentIssuer and
 * running the shared DocumentWriter (spec §16.6, wave 1.6). Mirrors
 * PaymentGatewayRegistry: one contract out, many drivers in, resolved by key.
 * ShopModules gating happens inside DocumentWriter, so a disabled module still
 * throws DocumentIssuanceUnavailable the same way NullDocumentIssuer would.
 */
class DocumentIssuerRegistry implements DocumentIssuer
{
    /** @param array<string, TypedDocumentIssuer> $issuers */
    public function __construct(
        private readonly DocumentWriter $writer,
        private readonly array $issuers,
    ) {}

    public function issue(string $orderUuid, string $type = Document::TYPE_INVOICE): DocumentView
    {
        $issuer = $this->issuers[$type] ?? throw new InvalidArgumentException("No issuer registered for document type [{$type}].");

        return $this->writer->write($issuer, $orderUuid);
    }
}
