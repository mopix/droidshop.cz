<!DOCTYPE html>
{{--
    Editorial — the shop as a magazine: full-bleed image bands, capitals with
    letterspacing, no card chrome.

    A copy of the base layout, which is the easiest place in this codebase to
    lose something important. Everything below that is not about looks — the
    skip link, the consent version attribute, the pre-paint banner script, the
    SEO component, the @stack hooks, the empty mini-cart placeholder, the
    cookie-settings link, the tracking includes and the banner itself — is
    carried over deliberately and is covered by tests.
--}}
<html lang="cs" data-consent-version="{{ config('consent.version') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-storefront::seo-meta
        :seo="$seo"
        :shop-name="$shopName"
        :shop-noindex="$shopSettings->noindex"
        :default-image="$shopOgImage ?? null" />

    @if ($theme->faviconUrl)
        <link rel="icon" href="{{ $theme->faviconUrl }}">
    @endif

    {{--
        One face preloaded, not both: latin-ext only matters once the page
        actually contains an accented glyph, and the browser fetches it then.
        Preloading both would spend the connection budget on a file half the
        shops never render.
    --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ asset('fonts/archivo/latin.woff2') }}">

    @stack('head')

    <script>
        (function () {
            var m = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]*)/);
            if (!m) return;
            try {
                var c = JSON.parse(decodeURIComponent(m[1]));
                if (String(c.v) === @json((string) config('consent.version'))) {
                    document.documentElement.classList.add('consent-decided');
                }
            } catch (e) { /* a broken cookie just means the banner shows */ }
        })();
    </script>
    <style>.consent-decided #cookie-banner { display: none; }</style>

    @vite($theme->assets())

    {{--
        After @vite, not before it: storefront.css carries a :root of fallback
        tokens, and between two :root rules of equal specificity the later
        sheet wins. Printed above the bundle, every theme's tokens were
        overwritten by the defaults — the markup changed and the palette did
        not, which is exactly the kind of bug no HTML assertion catches.
    --}}
    <style>
        :root {
            {!! $theme->css() !!}
            --brand-primary: {{ $theme->primary }};
            --brand-primary-contrast: {{ $theme->primaryContrast }};
            --brand-accent: {{ $theme->accent }};
        }
    </style>
</head>
<body class="min-h-screen bg-surface text-ink antialiased">
    {{-- WCAG 2.4.1: keyboard users must be able to jump the navigation. --}}
    <a href="#obsah"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-contrast">
        Přeskočit na obsah
    </a>

    {{--
        The promotional band. Rendered only when the merchant wrote a tagline,
        because an empty dark strip across the top of the page reads as a
        broken template rather than as restraint.
    --}}
    @if ($shopSettings->tagline)
        <p class="bg-ink px-4 py-2 text-center text-sm font-medium text-surface">
            {{ $shopSettings->tagline }}
        </p>
    @endif

    <header class="border-b border-line bg-surface">
        @if ($shopSettings->hasContactDetails())
            <div class="hidden border-b border-line sm:block">
                <div class="shop-container flex justify-end gap-6 py-1.5 text-xs text-ink-muted">
                    @foreach ($shopSettings->contactLines() as $line)
                        <span>
                            @if ($line['href'])
                                <a href="{{ $line['href'] }}" class="hover:underline">{{ $line['value'] }}</a>
                            @else
                                {{ $line['value'] }}
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="shop-container flex flex-wrap items-center gap-6 py-5">
            <a href="/" class="shrink-0">
                @if ($theme->logoUrl)
                    <img src="{{ $theme->logoUrl }}" alt="{{ $shopName }}" class="h-8 w-auto">
                @else
                    <span class="text-xl font-semibold uppercase tracking-[0.2em] text-ink">{{ $shopName }}</span>
                @endif
            </a>

            @if ($navCategories->isNotEmpty())
                <nav aria-label="Kategorie" class="order-3 w-full sm:order-none sm:w-auto">
                    <ul class="flex flex-wrap gap-x-7 gap-y-2 text-sm">
                        @foreach ($navCategories as $category)
                            {{-- Colour is never the only signal on hover (WCAG 1.4.1). --}}
                            <li>
                                <a href="{{ $category->url() }}"
                                   class="font-medium uppercase tracking-[0.12em] text-ink hover:underline">
                                    {{ $category->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <div class="ml-auto flex items-center gap-5">
                <form action="/hledani" method="get" role="search">
                    <label for="hledani" class="sr-only">Hledat v e-shopu</label>
                    <div class="flex">
                        {{--
                            Folded exactly as PageCacheKey::foldSearchTerm folds
                            it for the cache key: this layout is shared by every
                            page an entry can be built from, so echoing the raw
                            query here would leave one unfolded fragment on an
                            otherwise folded page.
                        --}}
                        <input id="hledani" name="q" type="search"
                               value="{{ \App\Core\PageCache\PageCacheKey::foldSearchTerm((string) request()->query('q', '')) }}"
                               class="w-40 border border-line bg-surface px-3 py-1.5 text-sm focus:border-ink focus:outline-none focus:ring-0 sm:w-56"
                               placeholder="Hledat…">
                        <button type="submit" class="bg-ink px-4 py-1.5 text-sm font-medium uppercase tracking-widest text-surface">
                            Hledat
                        </button>
                    </div>
                </form>

                @if ($customerAreaEnabled)
                    <nav aria-label="Účet zákazníka" class="text-sm font-medium">
                        @if ($signedInCustomer)
                            <a href="{{ route('storefront.customers.account') }}" class="text-ink hover:underline">Můj účet</a>
                        @else
                            <a href="{{ route('storefront.customers.login') }}" class="text-ink hover:underline">Přihlásit se</a>
                        @endif
                    </nav>
                @endif

                @if ($cartEnabled)
                    {{--
                        A plain link with no item count. This layout is shared
                        by every page the page cache may store, and a cached
                        entry is served to the next anonymous visitor — a count
                        baked in here would hand one shopper's basket to
                        another. The mini-cart island fetches its own.
                    --}}
                    <a href="{{ route('storefront.checkout.show') }}"
                       class="text-sm font-medium uppercase tracking-widest text-ink hover:underline">Košík</a>
                @endif
            </div>
        </div>
    </header>

    {{--
        The container stays on <main>. Views this theme does not override —
        the cart, the checkout, the customer's account — are written expecting
        one, and a theme must not knock the layout out from under the pages it
        is not allowed to touch. Full-width bands opt out with .bleed instead.
    --}}
    <main id="obsah" class="shop-container py-10">
        @yield('content')
    </main>

    <footer class="mt-20 border-t border-line bg-surface-muted">
        <div class="shop-container py-12 text-sm text-ink-muted">
            <nav aria-label="Informace o obchodě" class="mb-8">
                <ul class="flex flex-wrap gap-x-8 gap-y-2 uppercase tracking-[0.12em]">
                    @foreach (($footerPages ?? collect()) as $page)
                        <li>
                            <a href="{{ url('/'.$page->slug) }}" class="hover:underline">{{ $page->title }}</a>
                        </li>
                    @endforeach
                    {{-- Permanent: withdrawing consent has to be as easy as giving it. --}}
                    <li>
                        <a href="{{ route('consent.show') }}" class="hover:underline">Nastavení cookies</a>
                    </li>
                </ul>
            </nav>

            @if ($shopSettings->show_footer_contact && $shopSettings->hasContactDetails())
                <div class="mb-10 grid gap-8 sm:grid-cols-2">
                    @if ($shopSettings->contactLines())
                        <div>
                            <h2 class="mb-3 text-xs uppercase tracking-[0.2em] text-ink">Kontakt</h2>
                            <ul class="space-y-1">
                                @foreach ($shopSettings->contactLines() as $line)
                                    <li>
                                        <span class="text-ink-muted">{{ $line['label'] }}:</span>
                                        @if ($line['href'])
                                            <a href="{{ $line['href'] }}" class="underline hover:no-underline">{{ $line['value'] }}</a>
                                        @else
                                            {{ $line['value'] }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($shopSettings->socialLinks())
                        <div>
                            <h2 class="mb-3 text-xs uppercase tracking-[0.2em] text-ink">Sledujte nás</h2>
                            <ul class="flex flex-wrap gap-x-5 gap-y-1">
                                @foreach ($shopSettings->socialLinks() as $link)
                                    <li>
                                        {{-- rel="noopener" even without target: the merchant may point these anywhere. --}}
                                        <a href="{{ $link['url'] }}" rel="noopener nofollow" class="underline hover:no-underline">{{ $link['network'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <p class="text-xs uppercase tracking-[0.16em]">&copy; {{ date('Y') }} {{ $shopName }}</p>
        </div>
    </footer>

    @if (($trackingCodes ?? []) !== [])
        @include('analytics::tracking')
    @endif

    @stack('tracking')

    <x-consent-banner />
</body>
</html>
