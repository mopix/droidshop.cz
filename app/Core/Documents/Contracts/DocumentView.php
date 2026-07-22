<?php

namespace App\Core\Documents\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What a caller outside the docs module may rely on about an issued document.
 *
 * Deliberately narrow, matching App\Core\Orders\Contracts\OrderView: enough for
 * an admin list, a customer's account, or a mail to name and link a document,
 * without tying the kernel to the Eloquent model behind it. Every accessor is
 * prefixed `document` so it cannot collide with an Eloquent attribute name.
 *
 * Modules\Docs\Models\Document implements this.
 */
interface DocumentView
{
    public function documentNumber(): string;

    public function documentType(): string;

    public function documentOrderUuid(): string;

    public function documentTotal(): Money;

    public function documentCurrency(): string;

    public function documentIssuedAt(): Carbon;

    /** Tenant-relative key on the private disk, or null until the PDF job has run. */
    public function documentPdfPath(): ?string;

    public function documentSentAt(): ?Carbon;
}
