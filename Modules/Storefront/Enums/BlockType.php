<?php

namespace Modules\Storefront\Enums;

enum BlockType: string
{
    case Hero = 'hero';
    case Slider = 'slider';
    case UspStrip = 'usp_strip';
    case ProductRow = 'product_row';
    case ProductTabs = 'product_tabs';
    case CategoryGrid = 'category_grid';
    case CategoryMosaic = 'category_mosaic';
    case Text = 'text';
    case Banner = 'banner';
    case BannerGrid = 'banner_grid';

    /**
     * Mosaic arrangements a theme knows how to draw.
     *
     * A layout is a shape, not a count: "1-2-1" means a tall tile, two stacked
     * halves and a tall tile, whatever the shop puts in them.
     */
    public const MOSAIC_LAYOUTS = ['2-2', '1-2-1'];

    /** How a product list is filled. */
    public const PRODUCT_MODES = ['latest', 'category', 'manual'];

    /**
     * How many items a list-shaped block may hold: [payload key, min, max].
     *
     * The maximum is not tidiness. A homepage is stored whole by the page
     * cache and served to every visitor after that, so a block with twenty
     * slides is twenty images everyone downloads to see one. The minimum is
     * the point below which the block stops being itself — a slider with no
     * slide, a strip of benefits with one benefit.
     *
     * @return array{string, int, int}|null
     */
    public function itemBounds(): ?array
    {
        return match ($this) {
            self::Slider => ['slides', 1, 8],
            self::UspStrip => ['items', 2, 6],
            self::ProductTabs => ['tabs', 2, 5],
            self::BannerGrid => ['banners', 2, 3],
            default => null,
        };
    }

    /** Prázdný/výchozí payload pro nově přidaný blok tohoto typu. */
    public function defaultPayload(): array
    {
        return match ($this) {
            self::Hero => ['title' => '', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => null],
            self::Slider => ['slides' => [
                ['title' => '', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => ''],
            ]],
            self::UspStrip => ['items' => [
                ['icon' => 'truck', 'title' => '', 'subtitle' => null],
                ['icon' => 'clock', 'title' => '', 'subtitle' => null],
            ]],
            self::ProductRow => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []],
            self::ProductTabs => ['heading' => 'Nabídka', 'tabs' => [
                ['label' => 'Novinky', 'mode' => 'latest', 'count' => 6, 'category_id' => null, 'product_ids' => []],
                ['label' => 'Doporučujeme', 'mode' => 'latest', 'count' => 6, 'category_id' => null, 'product_ids' => []],
            ]],
            self::CategoryGrid => ['heading' => 'Kategorie', 'category_ids' => []],
            self::CategoryMosaic => ['heading' => 'Kategorie', 'layout' => self::MOSAIC_LAYOUTS[0], 'category_ids' => []],
            self::Text => ['heading' => null, 'html' => ''],
            self::Banner => ['image_path' => null, 'url' => null, 'alt' => ''],
            self::BannerGrid => ['banners' => [
                ['image_path' => null, 'url' => null, 'alt' => ''],
                ['image_path' => null, 'url' => null, 'alt' => ''],
            ]],
        };
    }
}
