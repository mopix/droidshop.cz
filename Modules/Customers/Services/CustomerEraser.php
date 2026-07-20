<?php

namespace Modules\Customers\Services;

use App\Core\Services\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
    /**
     * The reserved domain used for placeholder addresses. RegisterRequest
     * rejects it on the way in, so no customer can ever hold an address here
     * going forward — but that guard is not proof: a row written before the
     * validation rule existed could already occupy the exact placeholder
     * this class is about to generate. erase() does not trust the guard; it
     * catches the resulting unique-constraint violation and tries again with
     * a fresh, unguessable local part instead.
     */
    public const PLACEHOLDER_DOMAIN = 'anonymized.invalid';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AuditLog $auditLog) {}

    public static function isReservedEmail(string $email): bool
    {
        return Str::endsWith(Str::lower($email), '@'.self::PLACEHOLDER_DOMAIN);
    }

    public function erase(Customer $customer): void
    {
        // Idempotent: a second erase request for the same customer must not
        // throw, must not write a second audit entry, and must not replace an
        // already-stable placeholder e-mail with a fresh one that would
        // needlessly touch the (tenant_id, email) unique index again.
        if ($customer->isAnonymised()) {
            return;
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                DB::transaction(function () use ($customer): void {
                    // Deletion, the field save and the audit write share one
                    // transaction: a failure partway through (including the
                    // retried save below) must not leave addresses gone with
                    // anonymised_at still null — a half-erased row the
                    // idempotency guard above would not recognise as done.
                    $customer->addresses()->delete();

                    $customer->forceFill([
                        'first_name' => null,
                        'last_name' => null,
                        'phone' => null,
                        'email' => $this->placeholderEmail($customer),
                        // A random value nobody — including us — ever learns.
                        // The model casts `password` as `hashed`, so this
                        // plain string is turned into a bcrypt hash on save:
                        // what lands in the column is unusable for
                        // Auth::attempt() because the plaintext needed to
                        // pass Hash::check() was discarded the instant it
                        // was generated, not merely because the string looks
                        // unlike a real password.
                        'password' => Str::random(60),
                        'remember_token' => null,
                        // A verified/logged-in placeholder would be a lie:
                        // both facts belonged to the person, not to the row.
                        'email_verified_at' => null,
                        'last_login_at' => null,
                        'anonymised_at' => now(),
                    ])->save();

                    $this->auditLog->log('customer.erase', $customer);
                });

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                // Extremely unlikely — the reserved domain is rejected at
                // registration — but not impossible for data written before
                // that rule existed. Retry with a fresh random local part
                // rather than trusting the guard blindly.
            }
        }
    }

    /**
     * Keyed by the row's own id plus an unguessable random suffix. The id
     * alone is not enough: it only guarantees no two *erasures* collide with
     * each other, not that no pre-existing row already holds that exact
     * address (see the class docblock on PLACEHOLDER_DOMAIN). The random
     * suffix, freshly generated on every attempt, is what erase() changes
     * between retries when it does.
     */
    private function placeholderEmail(Customer $customer): string
    {
        return sprintf(
            'smazano-%d-%s@%s',
            $customer->id,
            Str::lower(Str::random(12)),
            self::PLACEHOLDER_DOMAIN,
        );
    }
}
