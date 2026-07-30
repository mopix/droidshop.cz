<?php

namespace Modules\Accounting\Exceptions;

use RuntimeException;

class UnsupportedVatRate extends RuntimeException
{
    public static function forDocument(string $number, int|float $percent): self
    {
        return new self(
            "Doklad {$number} nese sazbu DPH {$percent} %, kterou účetní formát nezná. "
            .'Export byl zastaven — opravte sazbu nebo doklad z období vylučte.'
        );
    }
}
