<?php

namespace App\Core\Enums;

enum TenantRole: string
{
    case Owner = 'owner';

    /** Phase 2. Modelled from day one so adding it needs no schema change. */
    case Staff = 'staff';
}
