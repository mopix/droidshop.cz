<?php

namespace App\Core\Modules;

use App\Core\Modules\Exceptions\UnresolvableDependencies;
use Composer\Semver\Semver;

/**
 * Orders modules so that nothing boots before what it depends on (spec §15.5).
 */
class DependencyResolver
{
    /**
     * Topological order: a module always comes after everything it requires.
     *
     * @param  array<string, Manifest>  $manifests  keyed by module name
     * @return list<string>
     *
     * @throws UnresolvableDependencies
     */
    public function sort(array $manifests): array
    {
        $sorted = [];
        $state = [];

        // Sorted keys so the output is deterministic: an unstable module order
        // would make route registration and navigation shuffle between deploys.
        $keys = array_keys($manifests);
        sort($keys);

        foreach ($keys as $key) {
            $this->visit($key, $manifests, $state, $sorted, []);
        }

        return $sorted;
    }

    /**
     * Dependencies that are absent or at an incompatible version.
     *
     * @param  array<string, Manifest>  $available  keyed by module name
     * @return list<string> human-readable problems, empty when satisfiable
     */
    public function unmetDependencies(Manifest $manifest, array $available): array
    {
        $problems = [];

        foreach ($manifest->requires as $dependency => $constraint) {
            if (! isset($available[$dependency])) {
                $problems[] = UnresolvableDependencies::missing($manifest->name, $dependency)->getMessage();

                continue;
            }

            $installed = $available[$dependency]->version;

            if (! Semver::satisfies($installed, $constraint)) {
                $problems[] = UnresolvableDependencies::versionMismatch(
                    $manifest->name, $dependency, $constraint, $installed
                )->getMessage();
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, Manifest>  $manifests
     * @param  array<string, string>  $state
     * @param  list<string>  $sorted
     * @param  list<string>  $path
     */
    private function visit(string $key, array $manifests, array &$state, array &$sorted, array $path): void
    {
        $current = $state[$key] ?? 'unvisited';

        if ($current === 'done') {
            return;
        }

        if ($current === 'visiting') {
            throw UnresolvableDependencies::cycle([...$path, $key]);
        }

        // A dependency on something not installed is not this method's problem;
        // unmetDependencies() reports that with a better message.
        if (! isset($manifests[$key])) {
            return;
        }

        $state[$key] = 'visiting';

        foreach ($manifests[$key]->dependencyKeys() as $dependency) {
            $this->visit($dependency, $manifests, $state, $sorted, [...$path, $key]);
        }

        $state[$key] = 'done';
        $sorted[] = $key;
    }
}
