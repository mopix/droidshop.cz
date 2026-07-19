<?php

namespace App\Core\Limits;

/**
 * The verdict of a limit check (spec §15.1), with enough to drive the UI.
 */
readonly class LimitResult
{
    public function __construct(
        public LimitOutcome $outcome,
        public string $limit,
        public int $used,
        public ?int $cap,
        public string $message,
    ) {}

    public function allowed(): bool
    {
        return $this->outcome->allowed();
    }

    /**
     * Null when the limit is uncapped, otherwise how much headroom is left.
     */
    public function remaining(): ?int
    {
        return $this->cap === null ? null : max(0, $this->cap - $this->used);
    }
}
