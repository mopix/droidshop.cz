<?php

namespace Modules\Customers\Console;

use Illuminate\Console\Command;
use Modules\Customers\Services\CustomerTokens;

/**
 * Deletes customer_tokens rows nobody ever followed up on.
 *
 * consume() already deletes a row once it is used, win or lose, but a token
 * that is issued and never clicked just sits there past its own expires_at
 * — holding a real e-mail address for no purpose once it can no longer be
 * redeemed. Scheduled daily (Modules\Customers\Providers\ModuleProvider).
 *
 * Runs across every tenant in one query, like Products' reindex-search:
 * platform maintenance, not a per-shop action, and the address in a foreign
 * tenant's own expired row is not something this command reads or exposes.
 */
class PruneExpiredTokens extends Command
{
    protected $signature = 'customers:prune-tokens';

    protected $description = 'Delete expired customer password-reset and e-mail-verification tokens.';

    public function handle(CustomerTokens $tokens): int
    {
        $deleted = $tokens->pruneExpired();

        $this->info("Pruned {$deleted} expired customer token(s).");

        return self::SUCCESS;
    }
}
