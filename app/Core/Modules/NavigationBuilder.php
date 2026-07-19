<?php

namespace App\Core\Modules;

use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Builds the admin menu from the manifests of the modules a tenant runs
 * (spec §15.5 bod 3).
 */
class NavigationBuilder
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @return Collection<int, array{module: string, label: string, route: string, icon: ?string, order: int}>
     */
    public function forTenant(Tenant $tenant, string $area = 'admin'): Collection
    {
        return $this->registry->enabledFor($tenant)
            ->flatMap(fn (Module $module) => $this->entriesFor($module, $area))
            ->sortBy([['order', 'asc'], ['label', 'asc']])
            ->values();
    }

    /**
     * @return list<array{module: string, label: string, route: string, icon: ?string, order: int}>
     */
    private function entriesFor(Module $module, string $area): array
    {
        $manifest = Manifest::fromArray($module->manifest);

        $entries = [];

        foreach ($manifest->nav as $entry) {
            if (($entry['area'] ?? 'admin') !== $area) {
                continue;
            }

            $entries[] = [
                'module' => $module->key,
                'label' => $entry['label'],
                'route' => $entry['route'],
                'icon' => $entry['icon'] ?? null,
                // Unordered entries sink to the bottom rather than jumping to
                // the top and shoving the core menu around.
                'order' => $entry['order'] ?? 999,
            ];
        }

        return $entries;
    }
}
