<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Support\DocumentAmounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentAmountsTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'whole korunas' => [100000, '1000.00'],
            'with hellers' => [82562, '825.62'],
            'zero' => [0, '0.00'],
            'single heller' => [1, '0.01'],
            'negative credit note' => [-82562, '-825.62'],
            'negative under one koruna' => [-7, '-0.07'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_formats_minor_units_with_a_dot(int $minor, string $expected): void
    {
        $this->assertSame($expected, DocumentAmounts::decimal($minor));
    }
}
