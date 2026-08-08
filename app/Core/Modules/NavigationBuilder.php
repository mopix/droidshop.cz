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
     * @return Collection<int, array{module: string, label: string, route: string, icon: ?string, order: int, group: string}>
     */
    public function forTenant(Tenant $tenant, string $area = 'admin'): Collection
    {
        return $this->registry->enabledFor($tenant)
            ->flatMap(fn (Module $module) => $this->entriesFor($module, $area))
            ->sortBy([['order', 'asc'], ['label', 'asc']])
            ->values();
    }

    /**
     * The same entries, arranged into the menu's sections.
     *
     * Sections come back in the kernel's fixed order (NavigationGroup), not
     * in the order modules happen to sit on disk, and an empty one is dropped
     * entirely — a heading with nothing under it reads as something broken.
     *
     * Kernel entries (Nastavení modulů, Doména, Vzhled) are NOT added here.
     * They belong to no module, so they cannot be switched off, and mixing
     * them in would mean this class had to know about routes that have
     * nothing to do with the module system. The layout adds them.
     *
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function groupedForTenant(Tenant $tenant, string $area = 'admin'): array
    {
        $entries = $this->forTenant($tenant, $area)->groupBy('group');

        $groups = [];

        foreach (NavigationGroup::ordered() as $group) {
            $items = $entries->get($group->value, collect());

            if ($items->isEmpty()) {
                continue;
            }

            $groups[] = [
                'key' => $group->value,
                'label' => $group->label(),
                'items' => $items->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{module: string, label: string, route: string, icon: ?string, order: int, group: string}>
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
                // A malformed group would have been rejected by
                // ManifestValidator at modules:sync; by the time a request
                // reads this, the value is either valid or absent.
                'group' => NavigationGroup::tryFrom($entry['group'] ?? '')?->value
                    ?? NavigationGroup::fallback()->value,
            ];
        }

        return $entries;
    }
}
