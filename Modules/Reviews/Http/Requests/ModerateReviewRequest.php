<?php

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason behind a rejection or a hide.
 *
 * Required at the boundary, not only in ReviewModerator: the service throws
 * an InvalidArgumentException (a 500), and a moderator who leaves the field
 * empty deserves a form error, not an error page.
 */
class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('reviews.moderate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Uveďte důvod zamítnutí. Nízké hodnocení důvodem být nemůže.',
        ];
    }
}
