<?php

namespace Modules\Pages\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Pages\Http\Requests\PageRequest;
use Modules\Pages\Models\Page;
use Modules\Pages\Services\PageWriter;

/**
 * Admin CRUD for static pages.
 *
 * Until wave 3.2 this screen was read-only, which meant the three legal
 * pages every shop is seeded with could never be filled in or published —
 * the tenant had templates and no way to use them.
 *
 * Writes go through PageWriter, never through the model: that is what keeps
 * HTML sanitised on write, slugs unique per shop, and a 301 behind a renamed
 * page.
 */
class PageAdminController
{
    public function __construct(private readonly PageWriter $writer) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('pages.view'), 403);

        return inertia('Modules/Pages/Index', [
            'pages' => Page::query()
                ->orderBy('title')
                ->get(['id', 'slug', 'title', 'is_published']),
            'canEdit' => request()->user()->can('pages.edit'),
        ]);
    }

    public function create(): Response
    {
        abort_unless(request()->user()->can('pages.edit'), 403);

        return inertia('Modules/Pages/Form', [
            'page' => null,
        ]);
    }

    public function store(PageRequest $request): RedirectResponse
    {
        $this->writer->create($request->validated());

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Stránka byla vytvořena.');
    }

    public function edit(Page $page): Response
    {
        abort_unless(request()->user()->can('pages.edit'), 403);

        return inertia('Modules/Pages/Form', [
            'page' => $page->only([
                'id', 'slug', 'title', 'body', 'is_published', 'seo_title', 'seo_description',
            ]),
        ]);
    }

    public function update(PageRequest $request, Page $page): RedirectResponse
    {
        $this->writer->update($page, $request->validated());

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Stránka byla uložena.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        abort_unless(request()->user()->can('pages.edit'), 403);

        $this->writer->delete($page);

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Stránka byla smazána.');
    }
}
