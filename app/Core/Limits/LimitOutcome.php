<?php

namespace App\Core\Limits;

enum LimitOutcome: string
{
    /** Under 80 % of the cap. */
    case Allow = 'allow';

    /** Between 80 % and the cap: allowed, but the UI should nudge. */
    case Warn = 'warn';

    /** The cap would be exceeded: refuse. */
    case Block = 'block';

    public function allowed(): bool
    {
        return $this !== self::Block;
    }
}
