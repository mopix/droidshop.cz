<?php

namespace App\Core\Theme;

use App\Core\Theme\Exceptions\InvalidThemeManifest;
use FilesystemIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
     * The manifest and the directory have to agree in both directions.
     *
     * A file the manifest never declared would win over a core view without
     * appearing anywhere a reviewer would look — the whitelist in
     * ThemeManifest guards what a theme may *say*, and this guards what it
     * actually ships. A declared override with no file behind it is the
     * harmless half of the same mismatch, but it means the theme's author
     * believes they replaced something they did not.
     */
    private function assertViewsMatchManifest(ThemeManifest $manifest, string $directory, string $file): void
    {
        $views = $directory.'/views';
        $errors = [];

        foreach ($manifest->overrides as $view) {
            if (! is_file($views.'/'.$this->relativePathOf($view))) {
                $errors[] = "overrides declares [{$view}], but views/".$this->relativePathOf($view).' is missing.';
            }
        }

        $declared = array_map(fn (string $view): string => $this->relativePathOf($view), $manifest->overrides);

        foreach ($this->bladeFilesIn($views) as $found) {
            if (! in_array($found, $declared, true)) {
                $errors[] = "views/{$found} is not declared in overrides.";
            }
        }

        if ($errors !== []) {
            throw InvalidThemeManifest::forPath($file, $errors);
        }
    }

    private function relativePathOf(string $view): string
    {
        [$namespace, $name] = explode('::', $view);

        return $namespace.'/'.str_replace('.', '/', $name).'.blade.php';
    }

    /**
     * @return list<string>
     */
    private function bladeFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }

        sort($files);

        return $files;
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

            $this->assertViewsMatchManifest($manifest, dirname($file), $file);

            $manifests[$manifest->key] = $manifest->toArray();
        }

        ksort($manifests);

        return $manifests;
    }
}
