<?php

namespace Tests\Unit\PageCache;

use App\Core\PageCache\DynamicTokens;
use PHPUnit\Framework\TestCase;

class DynamicTokensTest extends TestCase
{
    private DynamicTokens $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new DynamicTokens;
    }

    public function test_masking_replaces_every_occurrence_of_the_token(): void
    {
        $html = '<input value="abc123"><input value="abc123">';

        $this->assertSame(
            '<input value="'.DynamicTokens::MARKER.'"><input value="'.DynamicTokens::MARKER.'">',
            $this->tokens->mask($html, 'abc123'),
        );
    }

    public function test_unmasking_puts_the_asking_visitors_token_in(): void
    {
        $stored = '<input value="'.DynamicTokens::MARKER.'">';

        $this->assertSame('<input value="zzz999">', $this->tokens->unmask($stored, 'zzz999'));
    }

    public function test_a_round_trip_hands_a_different_visitor_a_different_token(): void
    {
        $rendered = '<form><input name="_token" value="first-session-token"></form>';

        $stored = $this->tokens->mask($rendered, 'first-session-token');
        $served = $this->tokens->unmask($stored, 'second-session-token');

        $this->assertStringContainsString('second-session-token', $served);
        $this->assertStringNotContainsString('first-session-token', $served);
    }

    public function test_an_empty_token_leaves_the_html_untouched(): void
    {
        // str_replace with an empty needle would corrupt the document.
        $html = '<p>hello</p>';

        $this->assertSame($html, $this->tokens->mask($html, ''));
    }

    public function test_html_without_a_token_survives_masking(): void
    {
        $html = '<p>no form here</p>';

        $this->assertSame($html, $this->tokens->mask($html, 'abc123'));
    }

    public function test_unmasking_with_an_empty_token_leaves_the_html_untouched(): void
    {
        // str_replace with an empty replacement would silently delete the marker.
        $html = '<input value="'.DynamicTokens::MARKER.'">';

        $this->assertSame($html, $this->tokens->unmask($html, ''));
    }

    public function test_escaped_marker_in_tenant_content_does_not_interfere_with_unmask(): void
    {
        // If a tenant types <!--PAGECACHE_CSRF--> into product content, Blade's
        // escaping turns it into &lt;!--PAGECACHE_CSRF--&gt; before it ever reaches
        // the cache. The escaped version doesn't match the literal marker in the form,
        // so unmask only affects the real form token.
        $html = '<p>Product: &lt;!--PAGECACHE_CSRF--&gt;</p><input value="<!--PAGECACHE_CSRF-->">';

        $served = $this->tokens->unmask($html, 'visitor-token-abc123');

        // The escaped marker in tenant text survives unchanged
        $this->assertStringContainsString('&lt;!--PAGECACHE_CSRF--&gt;', $served);
        // The literal marker in the form gets replaced
        $this->assertStringContainsString('<input value="visitor-token-abc123">', $served);
    }
}
