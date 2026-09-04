<?php

namespace App\Core\Theme;

use App\Core\Theme\Exceptions\InvalidThemeManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every storefront theme this deploy ships, read from disk.
 *
 * Themes are not database rows: they arrive with the code, exactly like
 * modules' manifests do, and a tenant only ever stores the key of the one
 * they picked. That is what lets a theme be added by deploying a directory.
 */
class ThemeRegistry
{
    /** @var Collection<string, ThemeManifest>|null */
    private ?Collection $memo = null;

    /**
     * @return Collection<string, ThemeManifest>
     */
    public function all(): Collection
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $manifests = Cache::remember(
            'themes:registry',
            (int) config('themes.cache_ttl', 3600),
            fn (): array => $this->read(),
        );

        return $this->memo = collect($manifests)
            ->map(fn (array $data): ThemeManifest => new ThemeManifest(
                key: $data['key'],
                name: $data['name'],
                description: $data['description'],
                preview: $data['preview'],
                cssEntry: $data['css'],
                tokens: $data['tokens'],
                overrides: $data['overrides'],
            ));
    }

    /**
     * The theme behind a stored key, or the default.
     *
     * Never throws. A key whose directory is gone — removed between deploys,
     * renamed, or simply never existing on this server — is a reason to log
     * and render the default look, not to answer every one of that shop's
     * customers with a 500.
     */
    public function find(?string $key): ThemeManifest
    {
        $themes = $this->all();
        $default = (string) config('themes.default', 'base');

        if ($key !== null && $themes->has($key)) {
            return $themes->get($key);
        }

        if ($key !== null && $key !== $default) {
            Log::warning('Unknown storefront theme requested; falling back to the default.', [
                'requested' => $key,
                'default' => $default,
            ]);
        }

        $fallback = $themes->get($default);

        if ($fallback === null) {
            // Not a data problem — the deploy is missing the one theme the
            // platform is allowed to assume. Say so instead of rendering
            // something nobody designed.
            throw InvalidThemeManifest::unreadable(
                rtrim((string) config('themes.path'), '/')."/{$default}/theme.json"
            );
        }

        return $fallback;
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget('themes:registry');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $path = rtrim((string) config('themes.path'), '/');
        $manifests = [];

        foreach (glob($path.'/*/theme.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            $data = $raw === false ? null : json_decode($raw, true);

            if (! is_array($data)) {
                throw InvalidThemeManifest::unreadable($file);
            }

            $manifest = ThemeManifest::fromArray($data, $file, basename(dirname($file)));

            $manifests[$manifest->key] = $manifest->toArray();
        }

        ksort($manifests);

        return $manifests;
    }
}
