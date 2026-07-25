<?php

namespace Modules\Storefront\Http\Requests;

use App\Core\Html\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Support\BlockUrl;

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

            if ($type === BlockType::ProductRow && ($p['mode'] ?? 'latest') === 'latest') {
                $count = (int) ($p['count'] ?? 0);
                if ($count < 1 || $count > 12) {
                    $v->errors()->add('payload.count', 'Počet 1–12.');
                }
            }
        });
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
