<?php

namespace Modules\Customers\Services;

use App\Core\Services\AuditLog;
use Illuminate\Support\Str;
use Modules\Customers\Models\Customer;

/**
 * GDPR erasure (spec §15.1): anonymises a customer in place rather than
 * deleting the row.
 *
 * Orders will hold a foreign key to a customer; deleting the row would either
 * fail that constraint or leave a dangling reference, turning a GDPR request
 * into a broken order history. Anonymising keeps the row — and only the row —
 * alive.
 */
class CustomerEraser
{
    public function __construct(private readonly AuditLog $auditLog) {}

    public function erase(Customer $customer): void
    {
        // Idempotent: a second erase request for the same customer must not
        // throw, must not write a second audit entry, and must not replace an
        // already-stable placeholder e-mail with a fresh one that would
        // needlessly touch the (tenant_id, email) unique index again.
        if ($customer->isAnonymised()) {
            return;
        }

        $customer->addresses()->delete();

        $customer->forceFill([
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            // Keyed by the row's own id, which is already unique per shop, so
            // this can never collide with a real address or with another
            // erased customer of the same tenant — even though
            // (tenant_id, email) stays a unique index.
            'email' => "smazano-{$customer->id}@anonymized.invalid",
            // A random value nobody — including us — ever learns. The model
            // casts `password` as `hashed`, so this plain string is turned
            // into a bcrypt hash on save: what lands in the column is
            // unusable for Auth::attempt() because the plaintext needed to
            // pass Hash::check() was discarded the instant it was generated,
            // not merely because the string looks unlike a real password.
            'password' => Str::random(60),
            'remember_token' => null,
            'anonymised_at' => now(),
        ])->save();

        $this->auditLog->log('customer.erase', $customer);
    }
}
