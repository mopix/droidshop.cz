<?php

namespace Modules\Pages\Http\Controllers;

use Inertia\Response;
use Modules\Pages\Models\Page;

/**
 * Admin listing.
 *
 * Read-only: editing pages is a later wave. What this screen proves today is
 * that a module's admin route mounts, is gated correctly, and renders inside
 * the shared tenant admin shell without knowing anything about it.
 */
class PageAdminController
{
    public function index(): Response
    {
        abort_unless(request()->user()->can('pages.view'), 403);

        return inertia('Modules/Pages/Index', [
            'pages' => Page::query()
                ->orderBy('title')
                ->get(['id', 'slug', 'title', 'is_published']),
        ]);
    }
}
