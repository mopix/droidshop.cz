<?php

namespace Modules\Storefront\Support;

/**
 * The icons a benefits strip may ask for.
 *
 * A closed set, drawn as inline SVG by the storefront component. A tenant does
 * not upload one: an SVG is active content (it can carry a script), and
 * accepting uploads here would reopen exactly the hole the favicon rules
 * deliberately close — while the reward would be a slightly different picture
 * of a lorry.
 */
final class UspIcons
{
    public const ICONS = [
        'truck',
        'clock',
        'shield',
        'leaf',
        'award',
        'heart',
        'gift',
        'headset',
        'refresh',
        'lock',
        'sparkles',
        'wallet',
    ];

    public static function isKnown(?string $icon): bool
    {
        return $icon !== null && in_array($icon, self::ICONS, true);
    }
}
