<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Products\Exceptions\AttributeInUse;
use Modules\Products\Http\Requests\StoreAttributeRequest;
use Modules\Products\Http\Requests\StoreAttributeValueRequest;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;
use Modules\Products\Services\AttributeWriter;

/**
 * The tenant's code list of product properties (wave 4.2).
 *
 * Route-model binding does the tenant isolation: both models carry
 * BelongsToTenant, so another shop's id simply does not resolve and Laravel
 * answers 404 before anything here runs.
 */
class AttributeAdminController
{
    public function __construct(private readonly AttributeWriter $writer) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user('web')?->can('products.edit'), 403);

        return inertia('Modules/Products/Attributes', [
            'attributes' => ProductAttribute::query()
                ->with('values')
                ->orderBy('position')
                ->get()
                ->map(fn (ProductAttribute $attribute): array => [
                    'id' => $attribute->id,
                    'code' => $attribute->code,
                    'name' => $attribute->name,
                    'is_filterable' => $attribute->is_filterable,
                    'values' => $attribute->values->map(fn (ProductAttributeValue $value): array => [
                        'id' => $value->id,
                        'value' => $value->value,
                        'slug' => $value->slug,
                    ]),
                ]),
        ]);
    }

    public function store(StoreAttributeRequest $request): RedirectResponse
    {
        $this->writer->create($request->validated());

        return back()->with('success', 'Vlastnost byla přidána.');
    }

    public function update(StoreAttributeRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->writer->update($attribute, $request->validated());

        return back()->with('success', 'Vlastnost byla uložena.');
    }

    public function destroy(Request $request, ProductAttribute $attribute): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('products.edit'), 403);

        try {
            $this->writer->delete($attribute);
        } catch (AttributeInUse) {
            // Refused, not cascaded: cascading would strip a property from
            // goods that carry it and nobody would notice until a customer
            // asked why the shop stopped saying what colour a thing is.
            return back()->withErrors(['attribute' => 'Vlastnost používá aspoň jeden produkt, nelze ji smazat.']);
        }

        return back()->with('success', 'Vlastnost byla smazána.');
    }

    public function storeValue(StoreAttributeValueRequest $request, ProductAttribute $attribute): RedirectResponse
    {
        $this->writer->addValue($attribute, $request->validated());

        return back()->with('success', 'Hodnota byla přidána.');
    }

    public function updateValue(StoreAttributeValueRequest $request, ProductAttributeValue $value): RedirectResponse
    {
        // Renames the label and keeps the slug — see AttributeWriter.
        $this->writer->renameValue($value, $request->validated('value'));

        return back()->with('success', 'Hodnota byla uložena.');
    }

    public function destroyValue(Request $request, ProductAttributeValue $value): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('products.edit'), 403);

        if ($value->products()->exists()) {
            return back()->withErrors(['value' => 'Hodnotu používá aspoň jeden produkt, nelze ji smazat.']);
        }

        $value->delete();

        return back()->with('success', 'Hodnota byla smazána.');
    }
}
