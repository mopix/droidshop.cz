<?php

namespace App\Http\Requests\Platform;

use App\Core\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTenantStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TenantStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The two rules that need the tenant's current state, so they cannot live
     * in rules(): which transitions are legal, and when a reason is required.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $tenant = $this->route('tenant');
                $to = $this->status();

                if (! $tenant instanceof Tenant) {
                    return;
                }

                if (! $tenant->status->canTransitionTo($to)) {
                    $validator->errors()->add('status', sprintf(
                        'Ze stavu „%s" nelze přejít na „%s".',
                        $tenant->status->label(),
                        $to->label(),
                    ));

                    return;
                }

                if ($to->requiresReason() && trim((string) $this->input('reason')) === '') {
                    $validator->errors()->add('reason', 'U této změny je důvod povinný.');
                }
            },
        ];
    }

    public function status(): TenantStatus
    {
        return TenantStatus::from($this->input('status'));
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
