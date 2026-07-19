<?php

namespace App\Core\Enums;

enum DomainType: string
{
    /** nazev.droidshop.cz — issued automatically on onboarding. */
    case Subdomain = 'subdomain';

    /** Tenant's own domain via CNAME — phase 2. */
    case Custom = 'custom';
}
