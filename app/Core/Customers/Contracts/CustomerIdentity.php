<?php

namespace App\Core\Customers\Contracts;

/**
 * How the rest of the platform asks who is shopping (spec §6.7).
 *
 * Checkout attaches a cart to a signed-in customer through here, never by
 * reaching into Modules\Customers directly — that is what lets checkout run
 * on a shop that has the customers module switched off (a guest checkout),
 * and what stops a second identity implementation from needing to touch
 * every caller.
 *
 * The interface lives in the kernel, its implementation in the module — the
 * dependency points at the contract, not at the module.
 *
 * Kept deliberately small: current() and a single lookup. The checkout etapa
 * is what widens this, once it knows what it actually needs — a contract
 * that guesses at future needs ages worse than one that stayed honestly
 * small.
 */
interface CustomerIdentity
{
    /**
     * The customer signed in on this request, or null for a guest.
     */
    public function current(): ?CustomerAccount;

    /**
     * The customer at this shop registered under the given address, or null.
     *
     * Scoped to the current tenant like every other customer lookup — the
     * same address may hold an unrelated account at a different shop.
     *
     * Never returns a GDPR-anonymised account (Customer::isAnonymised()).
     * Checkout uses this to attach a cart to an existing identity by e-mail;
     * matching an erased row would quietly re-link new activity to an
     * identity the erasure was meant to sever, undoing it in effect.
     */
    public function findByEmail(string $email): ?CustomerAccount;
}
