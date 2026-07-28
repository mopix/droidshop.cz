<?php

namespace Tests\Unit\Modules\Products;

use Modules\Products\Support\ProductCsvParser;
use Modules\Products\Support\ProductCsvSchema;
use Tests\TestCase;

/**
 * The file a merchant uploads comes out of Excel, so it may carry a BOM, use
 * either separator and write money with a decimal comma. The parser is
 * forgiving on input; the exporter stays strict on output.
 */
class ProductCsvParserTest extends TestCase
{
    /**
     * @return list<array{line: int, data: array<string, string>}>
     */
    private function parse(string $contents): array
    {
        return iterator_to_array(app(ProductCsvParser::class)->rows($contents), false);
    }

    public function test_it_reads_a_semicolon_file_with_a_bom(): void
    {
        $rows = $this->parse("\xEF\xBB\xBFtyp;sku;nazev\nprodukt;ACME-1;Klávesnice\n");

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
        $this->assertSame('Klávesnice', $rows[0]['data']['nazev']);
    }

    public function test_it_reads_a_comma_file(): void
    {
        $rows = $this->parse("typ,sku,nazev\nprodukt,ACME-1,Klávesnice\n");

        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
    }

    public function test_column_order_does_not_matter(): void
    {
        $rows = $this->parse("nazev;typ;sku\nKlávesnice;produkt;ACME-1\n");

        $this->assertSame('ACME-1', $rows[0]['data']['sku']);
        $this->assertSame(ProductCsvSchema::TYPE_PRODUCT, $rows[0]['data']['typ']);
    }

    public function test_blank_lines_are_skipped_but_line_numbers_keep_counting(): void
    {
        $rows = $this->parse("typ;sku\nprodukt;A\n\nprodukt;B\n");

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(4, $rows[1]['line']);
    }

    public function test_money_accepts_both_czech_and_plain_notation(): void
    {
        $this->assertSame(129000, ProductCsvSchema::money('1 290,00'));
        $this->assertSame(129000, ProductCsvSchema::money('1290.00'));
        $this->assertSame(129000, ProductCsvSchema::money("1\u{00A0}290,00"));
        $this->assertSame(50, ProductCsvSchema::money('0,50'));
        $this->assertNull(ProductCsvSchema::money(''));
        $this->assertNull(ProductCsvSchema::money(null));
    }

    public function test_money_prints_back_in_czech_notation(): void
    {
        $this->assertSame('1290,00', ProductCsvSchema::formatMoney(129000));
        $this->assertSame('0,50', ProductCsvSchema::formatMoney(50));
    }

    public function test_booleans_accept_czech_words(): void
    {
        $this->assertTrue(ProductCsvSchema::bool('ano'));
        $this->assertFalse(ProductCsvSchema::bool('ne'));
        $this->assertTrue(ProductCsvSchema::bool('1'));
        $this->assertNull(ProductCsvSchema::bool(''));
    }
}
