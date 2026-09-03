<?php

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shop's public answer under a review.
 *
 * Kept apart from ModerateReviewRequest because the two fields mean different
 * things: a reason is internal and mandatory, a reply is published and
 * optional in length only.
 */
class ReplyToReviewRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
