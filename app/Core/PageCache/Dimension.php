<?php

namespace App\Core\PageCache;

/**
 * What a cached page depends on. A page's key carries only the dimensions it
 * actually reads, so recolouring the shop does not drop the catalogue and
 * editing a product does not drop the static pages.
 */
enum Dimension: string
{
    case Catalog = 'catalog';
    case Content = 'content';
    case Theme = 'theme';

    public function column(): string
    {
        return 'page_gen_'.$this->value;
    }

    /**
     * Parses the middleware parameters (`page-cache:catalog,theme`).
     *
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function list(array $values): array
    {
        return array_map(static fn (string $value): self => self::from($value), $values);
    }
}
