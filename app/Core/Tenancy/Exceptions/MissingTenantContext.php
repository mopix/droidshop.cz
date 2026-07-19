<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped data is touched with no tenant current.
 *
 * Failing loudly is the point. The alternative — quietly dropping the
 * tenant_id condition — returns every tenant's rows to whoever asked, which
 * is the exact failure this architecture exists to prevent (spec §4.2, §12.1).
 */
class MissingTenantContext extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No tenant is current, so [{$model}] cannot be queried or written safely. ".
            'Wrap the call in TenantContext::runAs(), or opt out explicitly with '.
            'withoutGlobalScope(TenantScope::class) if this really is platform-level work.'
        );
    }
}
