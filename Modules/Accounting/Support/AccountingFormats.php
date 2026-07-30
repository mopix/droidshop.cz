<?php

namespace Modules\Accounting\Support;

use InvalidArgumentException;
use Modules\Accounting\Contracts\AccountingFormat;

/**
 * Resolves a format by the key that arrives from the request. Same shape as
 * App\Core\Payments\Contracts\PaymentGatewayRegistry: the caller never knows
 * the concrete class.
 */
class AccountingFormats
{
    /** @var array<string, AccountingFormat> */
    private array $formats = [];

    /**
     * @param  list<AccountingFormat>  $formats
     */
    public function __construct(array $formats)
    {
        foreach ($formats as $format) {
            $this->formats[$format->key()] = $format;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->formats[$key]);
    }

    public function get(string $key): AccountingFormat
    {
        return $this->formats[$key]
            ?? throw new InvalidArgumentException("Unknown accounting format [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->formats);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        return array_values(array_map(
            fn (AccountingFormat $format) => ['key' => $format->key(), 'label' => $format->label()],
            $this->formats,
        ));
    }
}
