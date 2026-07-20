<?php

namespace Modules\Customers\Services;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time tokens for customer password resets and e-mail verification.
 *
 * Laravel's password broker is not usable here: password_reset_tokens has
 * email as its primary key and the framework's repository looks a token up by
 * address alone. Customer addresses are unique only within a tenant, so two
 * shops' customers sharing an address would silently overwrite each other's
 * tokens — one person's reset link invalidated by a stranger at another shop.
 *
 * Only the hash is stored. A leaked database row is then useless for taking
 * over an account, which is the whole point of storing a credential at all.
 */
class CustomerTokens
{
    public const PASSWORD_RESET = 'password_reset';

    public const EMAIL_VERIFICATION = 'email_verification';

    private const LIFETIME_MINUTES = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Issues a token, replacing any live one for the same address and purpose.
     *
     * upsert() rather than updateOrInsert(): the latter runs a SELECT and
     * then a separate INSERT or UPDATE, so two concurrent requests for the
     * same address can both pass the existence check and the loser gets an
     * uncaught QueryException off the unique index (a 500 on a double-click
     * of the reset button). upsert() compiles to a single atomic
     * `INSERT ... ON DUPLICATE KEY UPDATE` on MySQL, keyed on the same
     * columns the unique index covers.
     */
    public function issue(string $email, string $purpose): string
    {
        $token = Str::random(64);

        DB::table('customer_tokens')->upsert(
            [
                'tenant_id' => $this->tenantId(),
                'email' => Str::lower($email),
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
                'created_at' => now(),
            ],
            ['tenant_id', 'email', 'purpose'],
            ['token_hash', 'expires_at', 'created_at'],
        );

        return $token;
    }

    /**
     * Checks a token and, if it is good, spends it. Returns false for a wrong,
     * expired, foreign-tenant or already-used token — the caller must not be
     * able to tell those apart.
     */
    public function consume(string $email, string $purpose, string $token): bool
    {
        $row = DB::table('customer_tokens')
            ->where('tenant_id', $this->tenantId())
            ->where('email', Str::lower($email))
            ->where('purpose', $purpose)
            ->first();

        if ($row === null) {
            return false;
        }

        if (! hash_equals($row->token_hash, hash('sha256', $token))) {
            return false;
        }

        if (now()->greaterThan($row->expires_at)) {
            // Spent here too, not just left for the caller to reject: an
            // expired row that lingers still holds a plaintext-adjacent
            // e-mail address (see prune()) and, worse, is exactly the row a
            // GDPR erasure of this address must not leave behind — see
            // CustomerEraser::erase().
            DB::table('customer_tokens')->where('id', $row->id)->delete();

            return false;
        }

        DB::table('customer_tokens')->where('id', $row->id)->delete();

        return true;
    }

    /**
     * Deletes every token — any purpose — for one address at this tenant.
     *
     * Used by CustomerEraser: a surviving token for an address that has just
     * been freed by an erasure is a live credential for whoever registers
     * that address next (see the class docblock's account-takeover chain).
     * All purposes, not just the one the caller happens to be thinking about
     * — a strayed email_verification row is exactly as dangerous as a
     * password_reset one once the address is reassigned.
     */
    public function deleteAllForAddress(string $email): void
    {
        DB::table('customer_tokens')
            ->where('tenant_id', $this->tenantId())
            ->where('email', Str::lower($email))
            ->delete();
    }

    /**
     * Deletes every token, at every tenant, that has expired without ever
     * being followed up on. Not tenant-scoped: platform maintenance, run by
     * customers:prune-tokens (Modules\Customers\Console\PruneExpiredTokens)
     * across every shop, the same way Products' reindex command does.
     */
    public function pruneExpired(): int
    {
        return DB::table('customer_tokens')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function tenantId(): int
    {
        $id = $this->context->id();

        if ($id === null) {
            throw new MissingTenantContext(
                'Token zákazníka nelze vydat ani ověřit bez kontextu e-shopu.'
            );
        }

        return $id;
    }
}
