<?php

namespace App\Core\Theme;

use Illuminate\Support\Facades\View;

/**
 * Points Blade's view finder at the current tenant's theme.
 *
 * A theme replaces a view by putting a file of the same name in front of the
 * base one, so the view name never changes — view composers, @include and the
 * existing tests carry on untouched, and only the file behind the name moves.
 *
 * Paths are always *replaced*, never prepended. The finder is a singleton, so
 * in any process that survives more than one request — a queue worker, Octane
 * — prepending would stack one shop's theme on top of the next shop's, and the
 * second visitor would be served the first one's storefront. That is the same
 * class of bug as a leaked database row, and it would only show under load.
 */
class ThemeViewPaths
{
    /**
     * The hints as the modules registered them, captured before anything here
     * touches them.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $base = null;

    public function apply(ThemeManifest $theme): void
    {
        $base = $this->baseHints();
        $namespaces = $this->namespacesOf($theme);
        $path = rtrim((string) config('themes.path'), '/')."/{$theme->key}/views";

        foreach ($base as $namespace => $paths) {
            $hints = in_array($namespace, $namespaces, true)
                ? ["{$path}/{$namespace}", ...$paths]
                : $paths;

            View::replaceNamespace($namespace, $hints);
        }

        // The finder memoises every view it has already located. Without this,
        // a view resolved earlier in the process keeps the previous theme's
        // path no matter what the hints now say.
        View::getFinder()->flush();
    }

    /**
     * @return array<string, list<string>>
     */
    private function baseHints(): array
    {
        return $this->base ??= View::getFinder()->getHints();
    }

    /**
     * Only the namespaces the manifest actually declares.
     *
     * Pointing every namespace at the theme would let a file the manifest
     * never mentions win over a core view — the whitelist would be guarding
     * the declaration while nothing guarded the directory. ThemeRegistry
     * refuses such a theme outright; this is the second lock on the same door.
     *
     * @return list<string>
     */
    private function namespacesOf(ThemeManifest $theme): array
    {
        $namespaces = [];

        foreach ($theme->overrides as $view) {
            $namespaces[] = strtok($view, ':');
        }

        return array_values(array_unique($namespaces));
    }
}
