<?php

namespace Modules\Accounting\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A document carrying a VAT rate no accounting format may honestly write
 * (VatRateMap knows 21 / 12 / 0). Thrown from the writers, mid-generation.
 *
 * The spec promises the nájemce a 422 naming the document, but a plain
 * RuntimeException reaching the handler is a 500 with a generic page — the
 * Czech message never arrived (final review, wave 2.11). Rendering is solved on
 * the exception rather than by catching it in the controller (the route
 * Modules\Docs takes for CreditNoteNotAllowed) because this one is thrown deep
 * inside a writer, after a temp file may already exist, and every current and
 * future call site would otherwise need the same catch block.
 */
class UnsupportedVatRate extends RuntimeException implements HttpExceptionInterface
{
    public static function forDocument(string $number, int|float $percent): self
    {
        return new self(
            "Doklad {$number} nese sazbu DPH {$percent} %, kterou účetní formát nezná. "
            .'Export byl zastaven — opravte sazbu nebo doklad z období vylučte.'
        );
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['X-Robots-Tag' => 'noindex'];
    }

    /**
     * The export form is a native GET navigation, not an Inertia visit, so the
     * browser lands on whatever this returns — the message has to be IN the
     * response body, not flashed to a session nobody will read.
     */
    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422, $this->getHeaders());
        }

        return response()->view(
            'accounting::errors.unsupported-vat-rate',
            ['message' => $this->getMessage()],
            422,
            $this->getHeaders(),
        );
    }
}
