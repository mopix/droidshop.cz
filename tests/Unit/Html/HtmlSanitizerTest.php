<?php

namespace Tests\Unit\Html;

use App\Core\Html\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tenants write product and page HTML. Everything here is an attack a shop
 * owner could otherwise mount against their own customers.
 */
class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_allowed_formatting_survives(): void
    {
        $html = '<p>Dobrý <strong>notebook</strong> s <em>krásným</em> displejem</p>';

        $this->assertSame($html, $this->sanitizer->clean($html));
    }

    public function test_a_script_element_and_its_payload_are_removed(): void
    {
        $out = $this->sanitizer->clean('<p>Text</p><script>alert(1)</script>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_a_style_element_is_removed(): void
    {
        $out = $this->sanitizer->clean('<style>body{display:none}</style><p>Text</p>');

        $this->assertStringNotContainsString('display:none', $out);
    }

    public function test_an_iframe_is_removed(): void
    {
        $out = $this->sanitizer->clean('<iframe src="https://evil.example"></iframe>');

        $this->assertSame('', $out);
    }

    public function test_event_handlers_are_stripped_but_the_element_stays(): void
    {
        $out = $this->sanitizer->clean('<p onclick="steal()">Text</p>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_a_javascript_url_is_stripped_from_a_link(): void
    {
        $out = $this->sanitizer->clean('<a href="javascript:alert(1)">klik</a>');

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('klik', $out);
    }

    public function test_a_data_url_image_is_refused(): void
    {
        $out = $this->sanitizer->clean('<img src="data:text/html;base64,PHNjcmlwdD4=" alt="x">');

        $this->assertStringNotContainsString('data:', $out);
    }

    public function test_a_protocol_relative_link_is_stripped(): void
    {
        // `//evil.com` looks like an internal path but the browser resolves it
        // as `https://evil.com` — an open redirect wearing the shop's clothes.
        $out = $this->sanitizer->clean('<a href="//evil.com">akce</a>');

        $this->assertStringNotContainsString('evil.com', $out);
        $this->assertStringContainsString('akce', $out);
    }

    public function test_a_backslash_link_is_stripped(): void
    {
        // The WHATWG URL parser normalises `\` to `/`, so `/\evil.com`
        // becomes `//evil.com` and leaves the shop just the same.
        $out = $this->sanitizer->clean('<a href="/\evil.com">akce</a>');

        $this->assertStringNotContainsString('evil.com', $out);
    }

    public function test_a_tab_obfuscated_protocol_relative_link_is_stripped(): void
    {
        // The parser drops tab, CR and LF anywhere in a URL before resolving
        // it, so this reaches the browser as `//evil.com`.
        $out = $this->sanitizer->clean('<a href="/&#9;/evil.com">akce</a>');

        $this->assertStringNotContainsString('evil.com', $out);
    }

    public function test_a_protocol_relative_image_source_is_stripped(): void
    {
        $out = $this->sanitizer->clean('<img src="//evil.com/a.jpg" alt="A">');

        $this->assertStringNotContainsString('evil.com', $out);
    }

    public function test_an_anchor_link_survives(): void
    {
        $out = $this->sanitizer->clean('<a href="#parametry">parametry</a>');

        $this->assertStringContainsString('href="#parametry"', $out);
    }

    public function test_ordinary_links_and_images_keep_their_urls(): void
    {
        $out = $this->sanitizer->clean('<a href="https://example.com">web</a><img src="/img/a.jpg" alt="A">');

        $this->assertStringContainsString('https://example.com', $out);
        $this->assertStringContainsString('/img/a.jpg', $out);
    }

    public function test_a_link_opening_a_new_window_gets_rel_noopener(): void
    {
        $out = $this->sanitizer->clean('<a href="https://example.com" target="_blank">web</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function test_an_unknown_wrapper_is_unwrapped_and_its_text_kept(): void
    {
        $out = $this->sanitizer->clean('<div class="x"><p>Text</p></div>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_a_script_nested_inside_an_unwrapped_element_still_goes(): void
    {
        // Removing while iterating a live node list is how a sanitiser skips
        // siblings; this is the test that catches it.
        $out = $this->sanitizer->clean('<div><script>a()</script><script>b()</script><p>Text</p></div>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_text_is_escaped_not_executed(): void
    {
        $out = $this->sanitizer->clean('<p>2 &lt; 3 &amp; 4 &gt; 1</p>');

        $this->assertStringNotContainsString('<3', $out);
        $this->assertStringContainsString('&lt;', $out);
    }

    public function test_diacritics_survive(): void
    {
        $out = $this->sanitizer->clean('<p>Příliš žluťoučký kůň úpěl ďábelské ódy</p>');

        $this->assertStringContainsString('žluťoučký', $out);
        $this->assertStringContainsString('ďábelské', $out);
    }

    public function test_an_image_without_alt_gets_an_empty_alt_attribute(): void
    {
        $out = $this->sanitizer->clean('<img src="/img/a.jpg">');

        $this->assertStringContainsString('alt=""', $out);
    }

    public function test_null_stays_null_and_empty_stays_empty(): void
    {
        $this->assertNull($this->sanitizer->clean(null));
        $this->assertSame('', $this->sanitizer->clean(''));
    }
}
