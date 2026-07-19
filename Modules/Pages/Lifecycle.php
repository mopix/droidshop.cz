<?php

namespace Modules\Pages;

use App\Core\Modules\Contracts\ModuleLifecycle;
use App\Models\Tenant;
use Modules\Pages\Models\Page;

class Lifecycle implements ModuleLifecycle
{
    /**
     * Seeds the pages every Czech shop legally needs anyway.
     *
     * Uses updateOrCreate so a tenant who switches the module off and on again
     * does not get duplicates, and does not lose edits they already made.
     */
    public function onActivate(Tenant $tenant): void
    {
        $defaults = [
            ['slug' => 'obchodni-podminky', 'title' => 'Obchodní podmínky'],
            ['slug' => 'ochrana-osobnich-udaju', 'title' => 'Ochrana osobních údajů'],
            ['slug' => 'kontakt', 'title' => 'Kontakt'],
        ];

        foreach ($defaults as $page) {
            Page::query()->firstOrCreate(
                ['slug' => $page['slug']],
                ['title' => $page['title'], 'body' => '', 'is_published' => false],
            );
        }
    }

    /**
     * Nothing to do: deactivation hides the module, the tenant's pages stay
     * where they are so switching it back on restores everything.
     */
    public function onDeactivate(Tenant $tenant): void
    {
        //
    }
}
