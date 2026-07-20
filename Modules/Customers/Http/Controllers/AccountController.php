<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Http\Requests\UpdateAddressRequest;
use Modules\Customers\Http\Requests\UpdateProfileRequest;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Storefront\Support\Seo;

/**
 * The customer's own account area: overview, profile, addresses.
 *
 * Order history is deliberately absent — the `orders` module does not exist
 * yet (wave 1.3, later etapa). See the placeholder section in
 * storefront.account.index instead of a guessed table here.
 */
class AccountController
{
    public function index(Request $request): View
    {
        return view('customers::storefront.account.index', [
            'seo' => new Seo(title: 'Můj účet', noindex: true),
            'customer' => $this->customer($request),
        ]);
    }

    public function editProfile(Request $request): View
    {
        return view('customers::storefront.account.profile', [
            'seo' => new Seo(title: 'Moje údaje', noindex: true),
            'customer' => $this->customer($request),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $customer = $this->customer($request);

        $customer->forceFill([
            'first_name' => (string) $request->string('first_name'),
            'last_name' => (string) $request->string('last_name'),
            'phone' => $request->filled('phone') ? (string) $request->string('phone') : null,
        ]);

        if ($request->wantsPasswordChange()) {
            // The model casts password to hashed, so the plain value never
            // lands in the column as-is.
            $customer->forceFill(['password' => (string) $request->string('password')]);
        }

        $customer->save();

        return redirect()->route('storefront.customers.account.profile')
            ->with('status', 'Údaje byly uloženy.');
    }

    public function addresses(Request $request): View
    {
        $customer = $this->customer($request);

        return view('customers::storefront.account.addresses', [
            'seo' => new Seo(title: 'Moje adresy', noindex: true),
            'addresses' => $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    public function storeAddress(UpdateAddressRequest $request): RedirectResponse
    {
        $customer = $this->customer($request);

        DB::transaction(function () use ($customer, $request): void {
            if ($request->boolean('is_default')) {
                $this->clearDefault($customer, $request->string('kind')->toString());
            }

            $customer->addresses()->create([
                ...$request->validated(),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        return redirect()->route('storefront.customers.account.addresses')
            ->with('status', 'Adresa byla přidána.');
    }

    public function editAddress(Request $request, int $address): View
    {
        return view('customers::storefront.account.address-edit', [
            'seo' => new Seo(title: 'Upravit adresu', noindex: true),
            'address' => $this->ownedAddress($request, $address),
        ]);
    }

    public function updateAddress(UpdateAddressRequest $request, int $address): RedirectResponse
    {
        $customer = $this->customer($request);
        $owned = $this->ownedAddress($request, $address);

        DB::transaction(function () use ($customer, $owned, $request): void {
            if ($request->boolean('is_default')) {
                $this->clearDefault($customer, $request->string('kind')->toString(), except: $owned->id);
            }

            $owned->update([
                ...$request->validated(),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        return redirect()->route('storefront.customers.account.addresses')
            ->with('status', 'Adresa byla upravena.');
    }

    public function confirmDeleteAddress(Request $request, int $address): View
    {
        return view('customers::storefront.account.address-delete', [
            'seo' => new Seo(title: 'Smazat adresu', noindex: true),
            'address' => $this->ownedAddress($request, $address),
        ]);
    }

    public function destroyAddress(Request $request, int $address): RedirectResponse
    {
        $this->ownedAddress($request, $address)->delete();

        return redirect()->route('storefront.customers.account.addresses')
            ->with('status', 'Adresa byla smazána.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer;
    }

    /**
     * Looks an address up scoped to the authenticated customer's own
     * relation, never by id alone.
     *
     * customer_addresses.tenant_id has no composite foreign key tying it to
     * the tenant of its customer_id — BelongsToTenant stamps it from the
     * ambient context independently of which customer is attached. Fetching
     * through $customer->addresses() rather than CustomerAddress::findOrFail()
     * is what turns a foreign id into a 404 instead of a write acting on a
     * row that never belonged to the caller.
     *
     * @throws ModelNotFoundException
     */
    private function ownedAddress(Request $request, int $addressId): CustomerAddress
    {
        return $this->customer($request)->addresses()->findOrFail($addressId);
    }

    /**
     * Keeps "default" meaning one address per kind: unsetting the previous
     * default before a new one is marked, scoped to this customer's own
     * addresses only.
     */
    private function clearDefault(Customer $customer, string $kind, ?int $except = null): void
    {
        $customer->addresses()
            ->where('kind', $kind)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->update(['is_default' => false]);
    }
}
