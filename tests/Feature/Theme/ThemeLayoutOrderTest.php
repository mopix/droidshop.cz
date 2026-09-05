<?php

namespace Tests\Feature\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Where a layout prints the theme's tokens (wave 4.1).
 *
 * storefront.css carries a :root of fallback tokens, and between two :root
 * rules of equal specificity the later one wins. A layout that prints the
 * tokens above the bundle therefore has them overwritten by the defaults: the
 * markup changes and the palette does not.
 *
 * That happened, and every response assertion in the suite stayed green while
 * it did — the tokens were in the HTML, just outranked. So this checks the
 * templates themselves, which is where the invariant actually lives.
 */
class ThemeLayoutOrderTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function layouts(): array
    {
        $root = dirname(__DIR__, 3);

        $files = ['base' => $root.'/Modules/Storefront/Resources/views/layouts/shop.blade.php'];

        // Not base_path(): PHPUnit builds data providers before the
        // application is booted.
        foreach (glob($root.'/themes/*/views/storefront/layouts/shop.blade.php') ?: [] as $file) {
            $files[basename(dirname($file, 4))] = $file;
        }

        return array_map(static fn (string $file): array => [$file], $files);
    }

    #[DataProvider('layouts')]
    public function test_theme_tokens_are_printed_after_the_stylesheet(string $file): void
    {
        $template = (string) file_get_contents($file);

        $vite = strpos($template, '@vite(');
        $tokens = strpos($template, '--brand-primary:');

        $this->assertNotFalse($vite, "No @vite in [{$file}].");
        $this->assertNotFalse($tokens, "No theme tokens in [{$file}].");
        $this->assertGreaterThan(
            $vite,
            $tokens,
            "[{$file}] prints the theme tokens before the stylesheet, so the fallbacks in storefront.css win.",
        );
    }
}
