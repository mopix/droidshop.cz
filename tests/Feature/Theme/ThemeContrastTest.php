<?php

namespace Tests\Feature\Theme;

use App\Core\Theme\Contrast;
use App\Core\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A theme may not ship a palette its own text fails on (wave 4.1, task 16).
 *
 * WCAG 2.2 AA is a legal requirement here (EAA), and the pairings below are
 * the ones every storefront page uses for body copy. Checking them in the
 * manifest catches the problem where it is cheap — before a merchant picks the
 * theme and puts it in front of customers.
 */
class ThemeContrastTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        $cases = [];

        // Not base_path(): PHPUnit builds data providers before the
        // application is booted.
        foreach (glob(dirname(__DIR__, 3).'/themes/*/theme.json') ?: [] as $file) {
            $key = basename(dirname($file));
            $cases[$key] = [$key];
        }

        return $cases;
    }

    #[DataProvider('themes')]
    public function test_body_text_is_readable_on_every_surface(string $key): void
    {
        $tokens = app(ThemeRegistry::class)->find($key)->tokens;

        $pairs = [
            ['ink', 'surface', 4.5],
            ['ink', 'surface-muted', 4.5],
            ['ink-muted', 'surface', 4.5],
            ['ink-muted', 'surface-muted', 4.5],
        ];

        foreach ($pairs as [$text, $background, $minimum]) {
            if (! isset($tokens[$text], $tokens[$background])) {
                continue;
            }

            $ratio = Contrast::ratio($tokens[$text], $tokens[$background]);

            $this->assertGreaterThanOrEqual(
                $minimum,
                $ratio,
                "[{$key}] --{$text} on --{$background} is {$ratio}:1, below {$minimum}:1.",
            );
        }
    }
}
