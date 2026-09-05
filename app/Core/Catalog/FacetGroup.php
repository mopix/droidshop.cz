<?php

namespace App\Core\Catalog;

/**
 * One filterable property and its options, ready for the view.
 *
 * Carries the counts so the panel can grey out a value that would leave
 * nothing — and carries `selected` so the checkbox state comes from the
 * server rather than from the browser remembering a form.
 */
final readonly class FacetGroup
{
    /**
     * @param  list<array{slug: string, label: string, count: int, selected: bool}>  $values
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $values,
    ) {}

    public function hasSelection(): bool
    {
        foreach ($this->values as $value) {
            if ($value['selected']) {
                return true;
            }
        }

        return false;
    }
}
