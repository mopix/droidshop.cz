<?php

namespace App\Core\Billing\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';
}
