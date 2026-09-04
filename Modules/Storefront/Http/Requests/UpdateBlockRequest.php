<?php

namespace Modules\Storefront\Http\Requests;

use App\Core\Html\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Support\BlockUrl;
use Modules\Storefront\Support\UspIcons;

/**
 * Edits an existing block's payload. The payload shape is free-form JSON
 * (varies per `BlockType`), so the strict validation is per-type in the
 * `withValidator` after-hook rather than in `rules()`. URL fields (hero CTA,
 * banner link) are additionally guarded by `BlockUrl::isSafe()` — this is
 * tenant-authored free text printed as an `href` on the public storefront.
 */
class UpdateBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'visible' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // Which item of a list-shaped block the upload belongs to. The
            // controller uses it to build the stored path; it is never a path
            // itself.
            'image_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:7'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = $this->route('block')->type;
            $p = $this->input('payload', []);

            foreach ($this->urlFields($type) as $field) {
                if (isset($p[$field]) && ! BlockUrl::isSafe($p[$field])) {
                    $v->errors()->add("payload.$field", 'Neplatná nebo nebezpečná adresa.');
                }
            }

            if ($type === BlockType::CategoryMosaic
                && ! in_array($p['layout'] ?? null, BlockType::MOSAIC_LAYOUTS, true)) {
                $v->errors()->add('payload.layout', 'Neznámé rozvržení mozaiky.');
            }

            if ($type === BlockType::ProductRow && ($p['mode'] ?? 'latest') === 'latest') {
                $count = (int) ($p['count'] ?? 0);
                if ($count < 1 || $count > 12) {
                    $v->errors()->add('payload.count', 'Počet 1–12.');
                }
            }

            $this->validateItems($v, $type, $p);

            if ($type === BlockType::Banner) {
                // "Will have an image" covers both a fresh upload and an
                // existing stored image kept from before (no new upload) —
                // either way the storefront ends up rendering an <img>, so
                // both need a non-empty alt.
                $hasExistingImage = ! empty($this->route('block')->payload['image_path'] ?? null);
                $hasImage = $this->hasFile('image') || $hasExistingImage;

                if ($hasImage && trim((string) ($p['alt'] ?? '')) === '') {
                    $v->errors()->add('payload.alt', 'Vyplňte alternativní text obrázku.');
                }
            }
        });
    }

    /**
     * The list-shaped blocks: slider, benefits strip, product tabs, banner grid.
     *
     * Every one of them is a list the tenant grows by clicking "add", so the
     * bound comes from the type rather than from each caller remembering it.
     * The per-item checks live here for the same reason the payload is
     * validated in an after-hook at all: the shape differs per type, and
     * rules() cannot see which type the route resolved to.
     *
     * @param  array<string, mixed>  $p
     */
    private function validateItems(Validator $v, BlockType $type, array $p): void
    {
        $bounds = $type->itemBounds();

        if ($bounds === null) {
            return;
        }

        [$key, $min, $max] = $bounds;
        $items = $p[$key] ?? [];

        if (! is_array($items) || count($items) < $min || count($items) > $max) {
            $v->errors()->add("payload.{$key}", "Počet položek {$min}–{$max}.");

            return;
        }

        foreach (array_values($items) as $i => $item) {
            $item = is_array($item) ? $item : [];
            $path = "payload.{$key}.{$i}";

            match ($type) {
                BlockType::Slider => $this->validateSlide($v, $path, $item),
                BlockType::UspStrip => $this->validateUspItem($v, $path, $item),
                BlockType::ProductTabs => $this->validateTab($v, $path, $item),
                BlockType::BannerGrid => $this->validateBanner($v, $path, $item),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateSlide(Validator $v, string $path, array $item): void
    {
        if (trim((string) ($item['title'] ?? '')) === '') {
            $v->errors()->add("{$path}.title", 'Vyplňte nadpis slidu.');
        }

        if (! empty($item['cta_url']) && ! BlockUrl::isSafe($item['cta_url'])) {
            $v->errors()->add("{$path}.cta_url", 'Neplatná nebo nebezpečná adresa.');
        }

        // Same rule as the single banner: a picture the shop renders without
        // alt text is something a screen reader cannot name.
        if (! empty($item['image_path']) && trim((string) ($item['alt'] ?? '')) === '') {
            $v->errors()->add("{$path}.alt", 'Vyplňte alternativní text obrázku.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateUspItem(Validator $v, string $path, array $item): void
    {
        if (! UspIcons::isKnown($item['icon'] ?? null)) {
            // An unknown name renders nothing, and an empty column in a row of
            // four reads as a broken page rather than as a missing icon.
            $v->errors()->add("{$path}.icon", 'Vyberte ikonu ze seznamu.');
        }

        if (trim((string) ($item['title'] ?? '')) === '') {
            $v->errors()->add("{$path}.title", 'Vyplňte nadpis.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateTab(Validator $v, string $path, array $item): void
    {
        if (trim((string) ($item['label'] ?? '')) === '') {
            $v->errors()->add("{$path}.label", 'Vyplňte název záložky.');
        }

        if (! in_array($item['mode'] ?? 'latest', BlockType::PRODUCT_MODES, true)) {
            $v->errors()->add("{$path}.mode", 'Neznámý zdroj produktů.');
        }

        $count = (int) ($item['count'] ?? 0);

        if (($item['mode'] ?? 'latest') !== 'manual' && ($count < 1 || $count > 12)) {
            $v->errors()->add("{$path}.count", 'Počet 1–12.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateBanner(Validator $v, string $path, array $item): void
    {
        if (! empty($item['url']) && ! BlockUrl::isSafe($item['url'])) {
            $v->errors()->add("{$path}.url", 'Neplatná nebo nebezpečná adresa.');
        }

        if (! empty($item['image_path']) && trim((string) ($item['alt'] ?? '')) === '') {
            $v->errors()->add("{$path}.alt", 'Vyplňte alternativní text obrázku.');
        }
    }

    /** @return list<string> */
    private function urlFields(BlockType $type): array
    {
        return match ($type) {
            BlockType::Hero => ['cta_url'],
            BlockType::Banner => ['url'],
            default => [],
        };
    }

    /** Validovaný + očištěný payload, připravený k uložení. */
    public function cleanPayload(BlockType $type, HtmlSanitizer $sanitizer): array
    {
        $p = $this->validated('payload');

        if ($type === BlockType::Text && isset($p['html'])) {
            $p['html'] = $sanitizer->clean($p['html']);
        }

        return $p;
    }
}
