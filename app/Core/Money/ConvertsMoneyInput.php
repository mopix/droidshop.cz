<?php

namespace App\Core\Money;

use App\Core\Money\Exceptions\InvalidMoneyInput;
use Illuminate\Validation\ValidationException;

/**
 * Turns korunas typed into a form into the haléře the request validates
 * (wave 3.8).
 *
 * Used from prepareForValidation(), so by the time any rule runs the field
 * holds minor units and every existing `integer`/`min`/`lt` rule keeps
 * working unchanged — including cross-field ones like the sale price having
 * to be below the shelf price, which would otherwise be comparing korunas
 * against haléře.
 *
 * A malformed amount fails as a validation error, not a 500: a typo in a
 * price field is an everyday event, and the merchant needs to see which
 * field it was.
 */
trait ConvertsMoneyInput
{
    /**
     * @param  list<string>  $fields
     */
    protected function convertMoneyFields(array $fields): void
    {
        $converted = [];

        foreach ($fields as $field) {
            // Absent stays absent. Overwriting with null would turn "the form
            // did not send this" into "clear it", which on an update request
            // is a different instruction entirely.
            if (! $this->has($field)) {
                continue;
            }

            try {
                $converted[$field] = MoneyInput::toMinorUnits($this->input($field));
            } catch (InvalidMoneyInput) {
                throw ValidationException::withMessages([
                    $field => 'Zadejte částku v korunách, například 1790,50.',
                ]);
            }
        }

        if ($converted !== []) {
            $this->merge($converted);
        }
    }
}
