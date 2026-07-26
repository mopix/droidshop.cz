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

        if ($type === BlockType::Hero && HomepageBlock::query()->where('type', BlockType::Hero)->exists()) {
            return back()->withErrors(['type' => 'Homepage může mít jen jeden Hero blok.']);
        }

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

        // image_path is server-authoritative and must never come from the
        // request body: a no-file PATCH could otherwise smuggle an arbitrary
        // payload.image_path string straight into storage. Drop whatever the
        // client sent, then re-derive it below either from a genuine upload
        // or from the block's previously stored value.
        unset($payload['image_path']);

        if ($request->hasFile('image')) {
            // image_path is always derived here from the uploaded file, never
            // accepted as a client-supplied string in the payload — otherwise
            // a tenant could point a block at an arbitrary path on the public
            // disk (or another tenant's, if the guard ever had a hole).
            $extension = $request->file('image')->extension();
            $path = "homepage/{$block->id}.{$extension}";
            $this->files->putPublic($path, file_get_contents($request->file('image')->getRealPath()));
            $payload['image_path'] = $path;
        } elseif (array_key_exists('image_path', $block->payload ?? [])) {
            // No new upload — keep the block's existing server-stored image
            // untouched instead of leaving it forged or wiped.
            $payload['image_path'] = $block->payload['image_path'];
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

        return back()->with('success', 'Pořadí bloků bylo změněno.');
    }

    public function toggle(ToggleBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $block->update(['visible' => $request->boolean('visible')]);

        return back()->with('success', $block->visible ? 'Blok byl zobrazen.' : 'Blok byl skryt.');
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
