<?php

namespace App\Http\Requests\Platform;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    public function rules(): array
    {
        return [
            // Nullable on purpose: taking the plan away is a real operation
            // (spec §5.4 — no plan means core only), not a missing value.
            'plan_id' => ['present', 'nullable', 'integer', 'exists:plans,id'],
        ];
    }

    public function plan(): ?Plan
    {
        $id = $this->validated('plan_id');

        return $id === null ? null : Plan::findOrFail($id);
    }
}
