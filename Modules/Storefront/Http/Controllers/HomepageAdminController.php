<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Html\HtmlSanitizer;
use App\Core\Storage\FileStorage;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Http\Requests\MoveBlockRequest;
use Modules\Storefront\Http\Requests\StoreBlockRequest;
use Modules\Storefront\Http\Requests\ToggleBlockRequest;
use Modules\Storefront\Http\Requests\UpdateBlockRequest;
use Modules\Storefront\Models\HomepageBlock;

/**
 * CRUD + reorder for the tenant's homepage blocks (page builder, wave 2.3).
 *
 * `{block}` route-model binding resolves through `HomepageBlock`'s
 * `BelongsToTenant` global scope, so a block belonging to another shop simply
 * does not exist from here — a 404, never a 403 that would confirm it exists.
 */
class HomepageAdminController
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('storefront.homepage.manage'), 403);

        $blocks = HomepageBlock::query()->orderBy('position')->get()
            ->map(fn (HomepageBlock $block) => [
                'id' => $block->id,
                'type' => $block->type->value,
                'payload' => $block->payload,
                'visible' => $block->visible,
            ]);

        return inertia('Modules/Storefront/Homepage', [
            'blocks' => $blocks,
            'blockTypes' => array_map(fn (BlockType $type) => $type->value, BlockType::cases()),
        ]);
    }

    public function store(StoreBlockRequest $request): RedirectResponse
    {
        $type = $request->blockType();

        HomepageBlock::create([
            'position' => (int) HomepageBlock::query()->max('position') + 1,
            'type' => $type,
            'payload' => $type->defaultPayload(),
            'visible' => true,
        ]);

        return back()->with('success', 'Blok byl přidán.');
    }

    public function update(UpdateBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        // Sanitized/validated payload only — never $request->validated('payload')
        // raw. This is what actually strips a <script> out of the text
        // block's html before it ever reaches the database.
        $payload = $request->cleanPayload($block->type, $this->sanitizer);

        if ($request->hasFile('image')) {
            // image_path is always derived here from the uploaded file, never
            // accepted as a client-supplied string in the payload — otherwise
            // a tenant could point a block at an arbitrary path on the public
            // disk (or another tenant's, if the guard ever had a hole).
            $extension = $request->file('image')->extension();
            $path = "homepage/{$block->id}.{$extension}";
            $this->files->putPublic($path, file_get_contents($request->file('image')->getRealPath()));
            $payload['image_path'] = $path;
        }

        $block->update([
            'payload' => $payload,
            'visible' => $request->boolean('visible', $block->visible),
        ]);

        return back()->with('success', 'Blok byl uložen.');
    }

    public function move(MoveBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $direction = $request->validated('direction');

        $neighbor = HomepageBlock::query()
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $block->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $block->position)->orderBy('position'),
            )
            ->first();

        if ($neighbor !== null) {
            [$block->position, $neighbor->position] = [$neighbor->position, $block->position];
            $block->save();
            $neighbor->save();
        }

        return back();
    }

    public function toggle(ToggleBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $block->update(['visible' => $request->boolean('visible')]);

        return back();
    }

    public function destroy(HomepageBlock $block): RedirectResponse
    {
        abort_unless(request()->user()->can('storefront.homepage.manage'), 403);

        if (! empty($block->payload['image_path'])) {
            $this->files->delete($block->payload['image_path'], private: false);
        }

        $block->delete();

        return back()->with('success', 'Blok byl smazán.');
    }
}
