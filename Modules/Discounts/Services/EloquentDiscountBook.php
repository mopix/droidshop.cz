<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\Contracts\DiscountBook;
use Illuminate\Support\Collection;
use Modules\Discounts\Models\Discount;

final class EloquentDiscountBook implements DiscountBook
{
    public function all(): Collection
    {
        return Discount::query()->orderByDesc('id')->get();
    }

    public function findByCode(string $code): ?Discount
    {
        // Codes are compared case-insensitively: a shopper typing "vitejte"
        // must hit the coupon created as "VITEJTE".
        return Discount::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->first();
    }
}
