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
}
