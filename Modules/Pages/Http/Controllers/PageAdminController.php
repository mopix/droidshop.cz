<?php

namespace Modules\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Pages\Models\Page;

/**
 * Admin listing.
 *
 * JSON for now: the Inertia admin shell arrives with its own wave. What this
 * proves today is that a module's admin route mounts and is gated correctly.
 */
class PageAdminController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'pages' => Page::query()->orderBy('title')->get(['id', 'slug', 'title', 'is_published']),
        ]);
    }
}
