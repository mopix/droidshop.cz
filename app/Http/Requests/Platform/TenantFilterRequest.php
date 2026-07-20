<?php

namespace App\Http\Requests\Platform;

use App\Core\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the tenant listing.
 *
 * Whitelisted rather than passed through: the values end up in a query, and an
 * unknown status silently returning everything would be worse than a 422.
 */
class TenantFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
            'plan' => ['nullable', 'string', 'exists:plans,key'],
        ];
    }

    public function status(): ?TenantStatus
    {
        $status = $this->validated('status');

        return $status ? TenantStatus::from($status) : null;
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }

    public function planKey(): ?string
    {
        return $this->validated('plan');
    }

    /**
     * Echoed back to the front end so the filter bar keeps its state.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search(),
            'status' => $this->validated('status'),
            'plan' => $this->planKey(),
        ];
    }
}
