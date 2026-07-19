<?php

namespace App\Core\Enums;

enum SslStatus: string
{
    /** Covered by the platform wildcard certificate. */
    case None = 'none';

    case Pending = 'pending';
    case Issued = 'issued';
    case Error = 'error';
}
