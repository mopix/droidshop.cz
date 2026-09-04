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

    /** Page sizes a listing offers. Anything else falls back to the first. */
    public const PER_PAGE = [24, 48, 96];

    /**
     * @param  list<int>  $categoryIds  empty means "the whole catalogue"
     * @param  array<string, list<string>>  $attributes  attribute code => value slugs
     */
    public function __construct(
        public array $categoryIds = [],
        public ?string $term = null,
        public string $sort = self::SORT_NEWEST,
        public bool $inStockOnly = false,
        public int $perPage = 24,
        public array $attributes = [],
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

        // A page size the visitor asked for, but only one the shop offers:
        // `?na-stranku=100000` is a way to make the server render the whole
        // catalogue, and it arrives from a query string like everything else.
        $requestedPerPage = filter_var($input['na-stranku'] ?? null, FILTER_VALIDATE_INT);
        $perPage = in_array($requestedPerPage, self::PER_PAGE, true) ? $requestedPerPage : $perPage;

        return new self(
            categoryIds: $categoryIds,
            term: is_string($input['q'] ?? null) ? trim($input['q']) : null,
            sort: in_array($sort, self::SORTS, true) ? $sort : self::SORT_NEWEST,
            inStockOnly: filter_var($input['skladem'] ?? false, FILTER_VALIDATE_BOOL),
            perPage: $perPage,
            attributes: self::normaliseAttributes($input['vlastnost'] ?? null),
        );
    }

    /**
     * True when the visitor narrowed the listing beyond its plain form. Such
     * combinations are noindex: they are the same goods sliced differently.
     */
    public function isFiltered(): bool
    {
        return $this->inStockOnly || $this->sort !== self::SORT_NEWEST || $this->attributes !== [];
    }

    /**
     * `?vlastnost[barva]=modra,cerna` turned into a shape the catalogue can use.
     *
     * Sorted and de-duplicated on the way in, and that is not tidiness: this
     * same value ends up in the page-cache key, so two orderings of the same
     * filter have to be one entry rather than two copies of identical HTML.
     * Codes and slugs are bounded and stripped of anything that is not a slug
     * character, because they arrive from a query string and a crawler will
     * eventually send every shape there is.
     *
     * @return array<string, list<string>>
     */
    public static function normaliseAttributes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $code => $values) {
            if (! is_string($code) || preg_match('/^[a-z0-9-]{1,64}$/D', $code) !== 1) {
                continue;
            }

            $list = is_array($values) ? $values : explode(',', (string) $values);

            $slugs = collect($list)
                ->filter(fn ($value): bool => is_string($value))
                ->map(fn (string $value): string => trim($value))
                ->filter(fn (string $value): bool => preg_match('/^[a-z0-9-]{1,64}$/D', $value) === 1)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($slugs !== []) {
                $result[$code] = $slugs;
            }
        }

        ksort($result);

        return $result;
    }
}
