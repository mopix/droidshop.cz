<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Documents\Contracts\DocumentLedger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Modules\Accounting\Support\AccountingFormats;

class ExportDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('accounting.export');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['required', 'string', 'in:'.implode(',', app(AccountingFormats::class)->keys())],
        ];
    }

    /**
     * The cap lives here rather than in the controller so the nájemce gets a
     * field error on the period they chose, not a failed download. Counting is
     * the same query the export runs, which is cheap next to generating XML.
     */
    protected function passedValidation(): void
    {
        $max = (int) config('accounting.max_documents');

        $count = app(DocumentLedger::class)->taxableBetween(
            Carbon::parse($this->validated('from')),
            Carbon::parse($this->validated('to')),
        )->count();

        if ($count > $max) {
            $this->validator->errors()->add('from', "Období obsahuje {$count} dokladů, maximum je {$max}. Zvolte prosím kratší období.");
            $this->failedValidation($this->validator);
        }
    }
}
