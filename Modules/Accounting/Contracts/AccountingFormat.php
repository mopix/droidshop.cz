<?php

namespace Modules\Accounting\Contracts;

use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;

/**
 * One accounting file format (wave 2.11). Registry pattern rather than a
 * switch: every format carries its own XSD, its own code lists and its own
 * golden-file test, and a third one (Money S3, see docs/future) must be a new
 * file, not an edit of the existing two.
 */
interface AccountingFormat
{
    public function key(): string;

    public function label(): string;

    /** File extension without the dot, e.g. `xml` or `zip`. */
    public function extension(): string;

    public function mime(): string;

    /**
     * The whole file content for a single document.
     *
     * @param  array<string, mixed>  $settings  the tenant's module settings
     */
    public function writeOne(DocumentView $document, array $settings): string;

    /**
     * The download name for one document, type prefix included and sanitised.
     *
     * Part of the contract rather than each caller's business: the single-file
     * download endpoint used to build its own name from the raw number, so a
     * document number carrying a slash or a quote reached Content-Disposition
     * verbatim, and an invoice and a credit note printing the same number
     * downloaded under one name (final review, wave 2.11).
     */
    public function filenameFor(DocumentView $document): string;

    /**
     * Writes a whole period to a temporary file and describes what to send.
     *
     * Returns a path rather than a string because ISDOC batches are ZIPs, which
     * cannot be assembled in memory the way an XML batch can.
     *
     * @param  Collection<int, DocumentView>  $documents
     * @param  array<string, mixed>  $settings
     * @return array{path: string, filename: string, mime: string}
     */
    public function writeBatch(Collection $documents, array $settings, string $filenameBase): array;
}
