<?php

namespace App\Core\Catalog;

/**
 * What a storefront listing asks the catalogue for.
 *
 * A value object rather than a pile of arguments, because the same question is
 * asked by the category page, the search page and later the feeds — and
 * because every one of those inputs arrives from a query string and has to be
 * normalised in exactly one place.
 */
readonly class ProductQuery
{
    public const SORT_NEWEST = 'nejnovejsi';

    public const SORT_PRICE_ASC = 'cena-asc';

    public const SORT_PRICE_DESC = 'cena-desc';

    public const SORT_NAME = 'nazev';

    public const SORTS = [
        self::SORT_NEWEST,
        self::SORT_PRICE_ASC,
        self::SORT_PRICE_DESC,
        self::SORT_NAME,
    ];

    /**
     * @param  list<int>  $categoryIds  empty means "the whole catalogue"
     */
    public function __construct(
        public array $categoryIds = [],
        public ?string $term = null,
        public string $sort = self::SORT_NEWEST,
        public bool $inStockOnly = false,
        public int $perPage = 24,
    ) {}

    /**
     * Builds from request input, dropping anything we do not recognise.
     *
     * Unknown sorts fall back instead of erroring: a stale link or a crawler
     * guessing at parameters must not produce a 500.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $categoryIds
     */
    public static function fromInput(array $input, array $categoryIds = [], int $perPage = 24): self
    {
        $sort = is_string($input['razeni'] ?? null) ? $input['razeni'] : self::SORT_NEWEST;

        return new self(
            categoryIds: $categoryIds,
            term: is_string($input['q'] ?? null) ? trim($input['q']) : null,
            sort: in_array($sort, self::SORTS, true) ? $sort : self::SORT_NEWEST,
            inStockOnly: filter_var($input['skladem'] ?? false, FILTER_VALIDATE_BOOL),
            perPage: $perPage,
        );
    }

    /**
     * True when the visitor narrowed the listing beyond its plain form. Such
     * combinations are noindex: they are the same goods sliced differently.
     */
    public function isFiltered(): bool
    {
        return $this->inStockOnly || $this->sort !== self::SORT_NEWEST;
    }
}
