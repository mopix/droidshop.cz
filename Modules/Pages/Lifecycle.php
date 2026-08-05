<?php

namespace Modules\Pages;

use App\Core\Modules\Contracts\ModuleLifecycle;
use App\Models\Tenant;
use Modules\Pages\Models\Page;
use Modules\Pages\Support\PageTemplates;

class Lifecycle implements ModuleLifecycle
{
    /**
     * Seeds the pages every Czech shop legally needs anyway.
     *
     * Until wave 3.2 the three pages were seeded empty, which meant a tenant
     * who never opened them had three invisible blanks and a shop with no
     * terms at all. They now carry a sample with visible [DOPLŇTE …] markers
     * — see PageTemplates for why the platform cannot write them outright.
     *
     * Still firstOrCreate, and that is the important part: a tenant who
     * switches the module off and on again keeps whatever they wrote. The
     * template is a starting point, never something that overwrites work.
     *
     * The pages stay unpublished. Publishing a sample with [DOPLŇTE …] still
     * in it would be worse than publishing nothing.
     */
    public function onActivate(Tenant $tenant): void
    {
        foreach (PageTemplates::all() as $slug => $page) {
            Page::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $page['title'],
                    'body' => $page['body'],
                    'is_published' => false,
                ],
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
