<?php

namespace Modules\Products\Http\Requests;

class UpdateProductRequest extends StoreProductRequest
{
    /**
     * Editing an existing product does not add one, so the plan limit does not
     * apply. Enforcing it here would lock a shop that is already over its cap
     * out of fixing its own data.
     */
    protected function enforcesProductLimit(): bool
    {
        return false;
    }
}
