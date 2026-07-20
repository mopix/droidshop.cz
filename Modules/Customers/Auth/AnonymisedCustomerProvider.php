<?php

namespace Modules\Customers\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Excludes anonymised (GDPR-erased) customers from every lookup the
 * `customer` guard performs.
 *
 * retrieveById() (session resolution), retrieveByToken() (remember-me
 * cookies) and retrieveByCredentials() (login) all build their query through
 * the parent's newModelQuery() — overriding that one method covers all
 * three at once. This is deliberately a guard-level exclusion, not a global
 * scope on the Customer model or a query change on the admin controller: the
 * admin list and detail must keep showing anonymised customers, so the
 * exclusion belongs only to the authentication path, never to reads.
 *
 * This is what makes erasure end a session that was already live: a
 * customer signed in at the moment of erasure keeps their session cookie,
 * but the very next request re-resolves the guard's user through this
 * provider, gets null, and `auth:customer` treats that exactly like a guest
 * — no AuthenticateSession middleware or manual logout bookkeeping needed.
 */
class AnonymisedCustomerProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        /** @var Builder $query */
        $query = parent::newModelQuery($model);

        return $query->whereNull('anonymised_at');
    }
}
