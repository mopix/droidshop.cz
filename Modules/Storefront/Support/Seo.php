<?php

namespace Modules\Storefront\Support;

/**
 * The head of a storefront page, decided by the controller.
 *
 * Kept as one object rather than a handful of view variables so that every
 * public page is forced to answer the same questions — title, description,
 * canonical, indexability — instead of quietly omitting one.
 */
readonly class Seo
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public string $type = 'website',
        public bool $noindex = false,
        public ?string $prev = null,
        public ?string $next = null,
    ) {}

    /**
     * The canonical URL, absolute and on the shop's own host.
     *
     * Query strings are dropped unless a caller passes them in deliberately:
     * sort and filter parameters produce the same goods in a different order,
     * and each variant competing as its own URL is how a catalogue splits its
     * own ranking.
     */
    public static function canonicalFor(string $path, array $keep = []): string
    {
        $url = url($path);

        return $keep === [] ? $url : $url.'?'.http_build_query($keep);
    }

    public function robots(): string
    {
        return $this->noindex ? 'noindex, follow' : 'index, follow';
    }
}
