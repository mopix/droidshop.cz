<?php

namespace App\Core\Modules;

/**
 * The sections the tenant admin menu is divided into.
 *
 * The list and its order live in the kernel, not in the manifests: a menu
 * whose sections were ordered by whichever module happened to be installed
 * first would rearrange itself every time a shop switched a module on. A
 * manifest says only which section its entry belongs to.
 */
enum NavigationGroup: string
{
    case Products = 'products';
    case Orders = 'orders';
    case Modules = 'modules';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Produkty',
            self::Orders => 'Objednávky',
            self::Modules => 'Moduly',
            self::Settings => 'Nastavení',
        };
    }

    /**
     * Where an entry goes when its manifest names no group.
     *
     * Deliberately a real section rather than a hidden one: an entry that
     * quietly disappears from the menu is a feature the tenant paid for and
     * cannot reach.
     */
    public static function fallback(): self
    {
        return self::Modules;
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Products, self::Orders, self::Modules, self::Settings];
    }
}
