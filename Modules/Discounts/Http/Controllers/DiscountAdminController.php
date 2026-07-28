<?php

namespace Modules\Discounts\Http\Controllers;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Modules\Categories\Models\Category;
use Modules\Discounts\Http\Requests\StoreDiscountRequest;
use Modules\Discounts\Http\Requests\UpdateDiscountRequest;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Products\Models\Product;

/**
 * Admin CRUD over discounts (wave 2.6, task 12).
 *
 * A coupon (has a code) and an automatic rule (does not) are the same
 * resource here, exactly as they are the same `discounts` row — the form
 * simply hides `code` for a rule and hides `combinable` for a coupon (the
 * engine only ever reads `combinable` on an automatic rule; a coupon's own
 * flag is ignored, so offering it there would be a control with no effect).
 *
 * `{discount}` route-model binding does the tenant isolation on its own:
 * Discount carries BelongsToTenant, so another shop's id never resolves and
 * Laravel answers 404 before any method here runs — the same guarantee
 * ShippingMethodAdminController and ProductAdminController rely on.
 */
class DiscountAdminController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user('web')?->can('discounts.manage'), 403);

        $discounts = Discount::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Discount $discount) => $this->present($discount));

        return inertia('Modules/Discounts/Index', [
            'discounts' => $discounts,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless((bool) $request->user('web')?->can('discounts.manage'), 403);

        return inertia('Modules/Discounts/Form', [
            'discount' => null,
            'categories' => $this->categoryOptions(),
            'products' => $this->productOptions(),
        ]);
    }

    public function store(StoreDiscountRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $discount = Discount::query()->create($this->attributes($request->validated()));

            $this->syncTargets($discount, $request->validated('scope'), $request->validated('targets', []));
        });

        return redirect()->route('admin.discounts.index')->with('success', 'Sleva byla vytvořena.');
    }

    public function edit(Request $request, Discount $discount): Response
    {
        abort_unless((bool) $request->user('web')?->can('discounts.manage'), 403);

        $discount->load('targets');

        return inertia('Modules/Discounts/Form', [
            'discount' => $this->present($discount, withTargets: true),
            'categories' => $this->categoryOptions(),
            'products' => $this->productOptions(),
        ]);
    }

    public function update(UpdateDiscountRequest $request, Discount $discount): RedirectResponse
    {
        DB::transaction(function () use ($request, $discount): void {
            $discount->update($this->attributes($request->validated()));

            $this->syncTargets($discount, $request->validated('scope'), $request->validated('targets', []));
        });

        return redirect()->route('admin.discounts.index')->with('success', 'Sleva byla uložena.');
    }

    public function destroy(Request $request, Discount $discount): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('discounts.manage'), 403);

        // discount_targets and discount_redemptions cascadeOnDelete at the DB
        // level, so this is the only write needed.
        $discount->delete();

        return redirect()->route('admin.discounts.index')->with('success', 'Sleva byla smazána.');
    }

    /**
     * Strips `targets` (not a `discounts` column, handled by syncTargets())
     * from the validated payload before it goes into a mass create/update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        unset($data['targets']);

        return $data;
    }

    /**
     * Full delete-then-recreate rather than a diff: a discount carries at
     * most a handful of targets, so the simpler code is worth more here than
     * the write it saves.
     *
     * A cart-scoped discount never keeps targets, whatever the request
     * carried — the picker is only reachable in the UI for scope != cart,
     * and the FormRequest's `prohibited` rule already rejects a posted id in
     * that case, so `$ids` is guaranteed empty here for scope=cart.
     *
     * @param  list<int>  $ids
     */
    private function syncTargets(Discount $discount, string $scope, array $ids): void
    {
        $discount->targets()->delete();

        $targetType = match ($scope) {
            Discount::SCOPE_CATEGORIES => DiscountTarget::TYPE_CATEGORY,
            Discount::SCOPE_PRODUCTS => DiscountTarget::TYPE_PRODUCT,
            default => null,
        };

        if ($targetType === null) {
            return;
        }

        foreach (array_unique($ids) as $id) {
            $discount->targets()->create([
                'target_type' => $targetType,
                'target_id' => $id,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Discount $discount, bool $withTargets = false): array
    {
        $data = [
            'id' => $discount->id,
            'name' => $discount->name,
            'code' => $discount->code,
            'active' => $discount->active,
            'type' => $discount->type,
            'value' => $discount->value,
            'scope' => $discount->scope,
            'starts_at' => $discount->starts_at?->toIso8601String(),
            'ends_at' => $discount->ends_at?->toIso8601String(),
            'min_cart_total' => $discount->min_cart_total,
            'usage_limit' => $discount->usage_limit,
            'usage_limit_per_email' => $discount->usage_limit_per_email,
            'used_count' => $discount->used_count,
            'requires_login' => $discount->requires_login,
            'first_order_only' => $discount->first_order_only,
            'combinable' => $discount->combinable,
            'priority' => $discount->priority,
        ];

        if ($withTargets) {
            $data['target_ids'] = $discount->targets->pluck('target_id')->all();
        }

        return $data;
    }

    /**
     * Discounts declares no manifest `requires` on categories (module.json:
     * `"requires": {}`) — a category-targeting picker is a nicety of this
     * admin screen, not a reason to make categories undeactivatable the
     * moment a shop also runs discounts (same stance checkout takes on
     * shipping/orders via ShippingOptions/PaymentOptions null bindings,
     * rozhodnutí 2026-07-21). So the check happens here, at read time, rather
     * than at the manifest level.
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        if (! $this->moduleEnabled('categories')) {
            return [];
        }

        return Category::query()
            ->orderBy('path')
            ->orderBy('position')
            ->get(['id', 'name', 'depth'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => str_repeat('— ', $category->depth).$category->name,
            ])
            ->all();
    }

    /**
     * Same reasoning as categoryOptions() above, for the products module.
     *
     * @return list<array{id: int, name: string}>
     */
    private function productOptions(): array
    {
        if (! $this->moduleEnabled('products')) {
            return [];
        }

        return Product::query()
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->sku ? "{$product->name} ({$product->sku})" : $product->name,
            ])
            ->all();
    }

    private function moduleEnabled(string $key): bool
    {
        $tenant = $this->tenants->current();

        return $tenant !== null && $this->modules->isEnabled($tenant, $key);
    }
}
