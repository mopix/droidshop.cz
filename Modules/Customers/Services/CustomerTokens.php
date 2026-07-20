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
     */
    public function issue(string $email, string $purpose): string
    {
        $token = Str::random(64);

        DB::table('customer_tokens')->updateOrInsert(
            [
                'tenant_id' => $this->tenantId(),
                'email' => Str::lower($email),
                'purpose' => $purpose,
            ],
            [
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
                'created_at' => now(),
            ],
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
