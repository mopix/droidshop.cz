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
            return false;
        }

        DB::table('customer_tokens')->where('id', $row->id)->delete();

        return true;
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
