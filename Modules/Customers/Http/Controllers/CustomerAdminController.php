<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Customers\Services\CustomerEraser;

/**
 * The nájemce's view of their own shop's customers: list, detail, GDPR
 * export and erasure. `{customer}` route-model binding does the tenant
 * isolation on its own — Customer carries BelongsToTenant, so a foreign id
 * simply does not resolve and Laravel answers with a 404 before the
 * controller ever runs. That is deliberate: the existence of another shop's
 * customer is not this tenant's business, so the answer is "not found", not
 * "forbidden".
 */
class CustomerAdminController
{
    public function __construct(private readonly CustomerEraser $eraser) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $customers = Customer::query()
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->summarise($customer));

        return inertia('Modules/Customers/Index', [
            'customers' => $customers,
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $customer->load('addresses');

        return inertia('Modules/Customers/Show', [
            'customer' => [
                ...$this->summarise($customer),
                'anonymised_at' => $customer->anonymised_at?->toIso8601String(),
                'last_login_at' => $customer->last_login_at?->toIso8601String(),
                'addresses' => $customer->addresses->map(
                    fn (CustomerAddress $address) => $this->addressPayload($address)
                )->all(),
            ],
            'can' => [
                'erase' => $request->user()->can('customers.erase'),
            ],
        ]);
    }

    public function erase(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->can('customers.erase'), 403);

        $this->eraser->erase($customer);

        return back()->with('success', 'Údaje zákazníka byly anonymizovány.');
    }

    /**
     * The customer's own data as a portable, downloadable JSON file (right to
     * portability). Deliberately scoped to this one customer: nothing about
     * other customers, this shop's or another's, is ever assembled here.
     */
    public function export(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $customer->load('addresses');

        $payload = [
            'id' => $customer->id,
            'email' => $customer->email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
            'last_login_at' => $customer->last_login_at?->toIso8601String(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'addresses' => $customer->addresses->map(
                fn (CustomerAddress $address) => $this->addressPayload($address)
            )->all(),
        ];

        return response()->json($payload)->withHeaders([
            'Content-Disposition' => 'attachment; filename="zakaznik-'.$customer->id.'.json"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'full_name' => $customer->fullName(),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'email_verified' => $customer->hasVerifiedEmail(),
            'anonymised' => $customer->isAnonymised(),
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'kind' => $address->kind,
            'company' => $address->company,
            'reg_no' => $address->reg_no,
            'vat_no' => $address->vat_no,
            'street' => $address->street,
            'city' => $address->city,
            'zip' => $address->zip,
            'country' => $address->country,
            'is_default' => $address->is_default,
        ];
    }
}
