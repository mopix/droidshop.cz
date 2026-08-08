<?php

namespace App\Http\Controllers\Platform;

use App\Core\Tax\TaxRates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTaxRateRequest;
use App\Http\Requests\Platform\UpdateTaxRateRequest;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * VAT rates, managed by the platform (wave 3.7).
 *
 * Here and not in a tenant's admin, by the owner's decision: a rate is
 * legislation, not a shopkeeper's choice. Every shop picks from this one list,
 * and when parliament moves a rate it moves once for everybody. `tax_rates`
 * has no tenant_id and never had one.
 *
 * Until now nothing edited this table at all — it was seeded by the migration
 * that created it, so the only way to follow a change in the law was another
 * migration.
 *
 * Czech rates only. There is no country column, and there will not be one
 * until there is a second country to put in it; a column with one value in
 * every row is a promise the rest of the code does not keep (nothing here
 * handles OSS, reverse charge or a foreign registration).
 */
class TaxRateController extends Controller
{
    /**
     * Tenant-scoped tables that point at a rate by id.
     *
     * Read through the query builder, not Eloquent: this runs with no tenant
     * in context, where a model's global scope would quietly answer "nobody
     * uses it" for every shop but the current one — and there is no current
     * one.
     */
    private const REFERENCING_TABLES = [
        'products' => 'produkt',
        'shipping_methods' => 'způsob dopravy',
        'payment_methods' => 'způsob platby',
    ];

    public function __construct(private readonly TaxRates $rates) {}

    public function index(): Response
    {
        return Inertia::render('Platform/TaxRates', [
            'rates' => TaxRate::query()->orderBy('position')->get()->map(fn (TaxRate $rate) => [
                'id' => $rate->id,
                'code' => $rate->code,
                'name' => $rate->name,
                'percent' => $rate->percent(),
                'is_default' => $rate->is_default,
                'position' => $rate->position,
                'in_use' => $this->usage($rate->id) !== [],
            ])->values()->all(),
        ]);
    }

    public function store(StoreTaxRateRequest $request): RedirectResponse
    {
        $rate = TaxRate::create($this->attributes($request->validated()));

        $this->applyDefault($rate, $request->boolean('is_default'));
        $this->rates->flush();

        return back()->with('success', 'Sazba byla přidána.');
    }

    public function update(UpdateTaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $taxRate->update($this->attributes($request->validated()));

        $this->applyDefault($taxRate, $request->boolean('is_default'));

        // Without this the shops keep charging the old rate for up to a day:
        // TaxRates caches the whole table for 24 hours precisely because rates
        // change by act of parliament and not by user action.
        $this->rates->flush();

        return back()->with('success', 'Sazba byla uložena.');
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $usage = $this->usage($taxRate->id);

        // A deleted rate would leave an invoice nobody can reconstruct: the
        // document snapshots the percentage, but the product it was sold from
        // points at a row that is gone.
        if ($usage !== []) {
            throw ValidationException::withMessages([
                'rate' => 'Sazbu nelze smazat, používá ji: '.implode(', ', $usage).'.',
            ]);
        }

        if ($taxRate->is_default) {
            throw ValidationException::withMessages([
                'rate' => 'Výchozí sazbu nelze smazat. Nejdřív nastavte jako výchozí jinou.',
            ]);
        }

        $taxRate->delete();
        $this->rates->flush();

        return back()->with('success', 'Sazba byla smazána.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            // Stored per mille, so 12.5 % is 125 and no float ever reaches a
            // document (see the tax_rates migration).
            'rate_permille' => (int) round(((float) $data['percent']) * 10),
            'position' => (int) $data['position'],
        ];
    }

    /**
     * Exactly one default, always.
     *
     * TaxRates::default() takes the first row flagged default; two of them
     * would make the rate a new product gets depend on row order, which is
     * not a thing anybody would think to check.
     */
    private function applyDefault(TaxRate $rate, bool $wanted): void
    {
        if (! $wanted) {
            return;
        }

        TaxRate::query()->whereKeyNot($rate->getKey())->update(['is_default' => false]);
        $rate->forceFill(['is_default' => true])->save();
    }

    /**
     * @return list<string>
     */
    private function usage(int $rateId): array
    {
        $used = [];

        foreach (self::REFERENCING_TABLES as $table => $label) {
            if (DB::table($table)->where('tax_rate_id', $rateId)->exists()) {
                $used[] = $label;
            }
        }

        return $used;
    }
}
