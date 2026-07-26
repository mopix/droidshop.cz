<?php

namespace Modules\Storefront\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;

/**
 * Adds a new block to the tenant's homepage. Only the type is client-given —
 * the starting payload is the type's `defaultPayload()`, edited afterwards
 * through `UpdateBlockRequest`.
 */
class StoreBlockRequest extends FormRequest
{
    /** Hard cap so one tenant can't build an unbounded homepage. */
    private const MAX_BLOCKS = 30;

    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::enum(BlockType::class)]];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Tenant-scoped by BelongsToTenant's global scope.
            if (HomepageBlock::query()->count() >= self::MAX_BLOCKS) {
                $v->errors()->add('type', 'Homepage může mít nejvýše '.self::MAX_BLOCKS.' bloků.');
            }
        });
    }

    public function blockType(): BlockType
    {
        return BlockType::from($this->validated('type'));
    }
}
