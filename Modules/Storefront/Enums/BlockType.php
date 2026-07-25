<?php

namespace Modules\Storefront\Enums;

enum BlockType: string
{
    case Hero = 'hero';
    case ProductRow = 'product_row';
    case CategoryGrid = 'category_grid';
    case Text = 'text';
    case Banner = 'banner';

    /** Prázdný/výchozí payload pro nově přidaný blok tohoto typu. */
    public function defaultPayload(): array
    {
        return match ($this) {
            self::Hero => ['title' => '', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null],
            self::ProductRow => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []],
            self::CategoryGrid => ['heading' => 'Kategorie', 'category_ids' => []],
            self::Text => ['heading' => null, 'html' => ''],
            self::Banner => ['image_path' => null, 'url' => null, 'alt' => ''],
        };
    }
}
