<?php

namespace Tests\Unit\Storefront;

use Modules\Storefront\Support\BlockUrl;
use PHPUnit\Framework\TestCase;

/**
 * Hero CTA and banner links are tenant-authored free text stored in
 * `homepage_blocks.payload` and printed as an `href` on the storefront.
 * Anything besides an internal path or http(s) URL is a script-injection
 * vector (`javascript:`, `data:`, `vbscript:`) reachable by the tenant
 * against their own customers.
 */
class BlockUrlTest extends TestCase
{
    public function test_null_and_empty_are_safe(): void
    {
        $this->assertTrue(BlockUrl::isSafe(null));
        $this->assertTrue(BlockUrl::isSafe(''));
    }

    public function test_internal_relative_path_is_safe(): void
    {
        $this->assertTrue(BlockUrl::isSafe('/kategorie/boty'));
        $this->assertTrue(BlockUrl::isSafe('/internal'));
    }

    public function test_http_and_https_urls_are_safe(): void
    {
        $this->assertTrue(BlockUrl::isSafe('https://example.com'));
        $this->assertTrue(BlockUrl::isSafe('http://example.com/x'));
    }

    public function test_scheme_check_is_case_insensitive(): void
    {
        $this->assertTrue(BlockUrl::isSafe('HTTPS://example.com'));
        $this->assertTrue(BlockUrl::isSafe('HtTp://example.com'));
    }

    public function test_dangerous_schemes_are_rejected(): void
    {
        $this->assertFalse(BlockUrl::isSafe('javascript:alert(1)'));
        $this->assertFalse(BlockUrl::isSafe('data:text/html,x'));
        $this->assertFalse(BlockUrl::isSafe('vbscript:x'));
        $this->assertFalse(BlockUrl::isSafe('ftp://x'));
        $this->assertFalse(BlockUrl::isSafe('mailto:x'));
    }

    public function test_scheme_less_string_with_a_colon_is_rejected(): void
    {
        $this->assertFalse(BlockUrl::isSafe('not-a-path:evil'));
    }
}
