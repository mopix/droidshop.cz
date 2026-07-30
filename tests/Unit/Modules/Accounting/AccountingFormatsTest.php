<?php

namespace Tests\Unit\Modules\Accounting;

use InvalidArgumentException;
use Modules\Accounting\Support\AccountingFormats;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Accounting\Support\PohodaXmlFormat;
use PHPUnit\Framework\TestCase;

class AccountingFormatsTest extends TestCase
{
    private function formats(): AccountingFormats
    {
        return new AccountingFormats([new PohodaXmlFormat, new IsdocFormat]);
    }

    public function test_it_resolves_a_format_by_key(): void
    {
        $this->assertInstanceOf(PohodaXmlFormat::class, $this->formats()->get('pohoda'));
        $this->assertInstanceOf(IsdocFormat::class, $this->formats()->get('isdoc'));
    }

    public function test_it_reports_the_keys_it_knows(): void
    {
        $this->assertSame(['pohoda', 'isdoc'], $this->formats()->keys());
        $this->assertTrue($this->formats()->has('pohoda'));
        $this->assertFalse($this->formats()->has('money-s3'));
    }

    public function test_an_unknown_key_throws(): void
    {
        // The FormRequest validates `format` against keys(), so reaching this is
        // a programming error, not user input.
        $this->expectException(InvalidArgumentException::class);

        $this->formats()->get('money-s3');
    }
}
