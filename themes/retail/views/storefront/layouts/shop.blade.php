<!DOCTYPE html>
{{--
    Retail — the shop as a catalogue: a contained page, bordered rounded
    cards, a wide search field, contact details in reach.

    A copy of the base layout. Everything below that is not about looks — the
    skip link, the consent version attribute, the pre-paint banner script, the
    SEO component, the @stack hooks, the countless mini-cart link, the
    cookie-settings link, the tracking includes and the banner — is carried
    over deliberately and is covered by ThemeStorefrontContractTest.
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

    <style>
        :root {
            {!! $theme->css() !!}
            --brand-primary: {{ $theme->primary }};
            --brand-primary-contrast: {{ $theme->primaryContrast }};
            --brand-accent: {{ $theme->accent }};
        }
    </style>
    @if ($theme->faviconUrl)
        <link rel="icon" href="{{ $theme->faviconUrl }}">
    @endif

    {{-- Only the latin face: latin-ext is fetched when the page actually
         contains an accented glyph, and preloading both would spend the
         connection budget on a file many shops never render. --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ asset('fonts/retail/source-sans-3-latin.woff2') }}">

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
</head>
<body class="min-h-screen bg-surface-muted text-ink antialiased">
    {{-- WCAG 2.4.1: keyboard users must be able to jump the navigation. --}}
    <a href="#obsah"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-button focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-contrast">
        Přeskočit na obsah
    </a>

    <header class="bg-surface shadow-sm">
        <div class="shop-container flex flex-wrap items-center gap-4 py-4">
            <a href="/" class="shrink-0">
                @if ($theme->logoUrl)
                    <img src="{{ $theme->logoUrl }}" alt="{{ $shopName }}" class="h-9 w-auto">
                @else
                    <span class="text-xl font-bold text-ink">{{ $shopName }}</span>
                @endif
            </a>

            {{-- The shop's own phone and hours, when the merchant filled them
                 in. A catalogue shop lives on being reachable, and this is the
                 data the settings screen already collects — no theme invents a
                 field of its own. --}}
            @if ($shopSettings->contact_phone)
                <p class="hidden text-sm leading-tight md:block">
                    <a href="tel:{{ preg_replace('/\s+/', '', $shopSettings->contact_phone) }}"
                       class="font-semibold text-ink hover:underline">{{ $shopSettings->contact_phone }}</a>
                    @if ($shopSettings->opening_hours)
                        <span class="block text-xs text-ink-muted">{{ $shopSettings->opening_hours }}</span>
                    @endif
                </p>
            @endif

            <form action="/hledani" method="get" role="search" class="order-last w-full md:order-none md:ml-auto md:max-w-md md:flex-1">
                <label for="hledani" class="sr-only">Hledat v e-shopu</label>
                <div class="flex overflow-hidden rounded-full border border-line bg-surface-muted">
                    {{-- Folded exactly as PageCacheKey::foldSearchTerm folds it
                         for the cache key: this layout is shared by every page
                         an entry can be built from. --}}
                    <input id="hledani" name="q" type="search"
                           value="{{ \App\Core\PageCache\PageCacheKey::foldSearchTerm((string) request()->query('q', '')) }}"
                           class="w-full border-0 bg-transparent px-4 py-2 text-sm focus:outline-none focus:ring-0"
                           placeholder="Co hledáte?">
                    <button type="submit" class="bg-brand px-5 py-2 text-sm font-semibold text-brand-contrast">
                        Hledat
                    </button>
                </div>
            </form>

            <div class="flex items-center gap-4 text-sm font-medium">
                @if ($customerAreaEnabled)
                    <nav aria-label="Účet zákazníka">
                        @if ($signedInCustomer)
                            <a href="{{ route('storefront.customers.account') }}" class="text-ink hover:underline">Můj účet</a>
                        @else
                            <a href="{{ route('storefront.customers.login') }}" class="text-ink hover:underline">Přihlásit se</a>
                        @endif
                    </nav>
                @endif

                @if ($cartEnabled)
                    {{-- No item count: a cached page is served to the next
                         anonymous visitor, so a count baked in here would hand
                         one shopper's basket to another. The mini-cart island
                         fetches its own. --}}
                    <a href="{{ route('storefront.checkout.show') }}"
                       class="rounded-button bg-brand px-4 py-2 font-semibold text-brand-contrast hover:opacity-90">
                        Košík
                    </a>
                @endif
            </div>
        </div>

        @if ($navCategories->isNotEmpty())
            <nav aria-label="Kategorie" class="border-t border-line">
                <div class="shop-container">
                    <ul class="flex flex-wrap gap-x-1 py-1 text-sm">
                        @foreach ($navCategories as $category)
                            <li>
                                {{-- Hover pairs colour with an underline, never
                                     colour alone (WCAG 1.4.1). --}}
                                <a href="{{ $category->url() }}"
                                   class="inline-block rounded-button px-3 py-2 font-medium text-ink-muted hover:text-brand hover:underline">
                                    {{ $category->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </nav>
        @endif
    </header>

    {{-- The container stays here: views this theme may not override — the
         cart, the checkout, the account — are written expecting one. --}}
    <main id="obsah" class="shop-container py-8">
        @yield('content')
    </main>

    <footer class="mt-16 border-t border-line bg-surface">
        <div class="shop-container py-10 text-sm text-ink-muted">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @if ($shopSettings->show_footer_contact && $shopSettings->contactLines())
                    <div>
                        <h2 class="mb-3 font-bold text-ink">Zákaznická podpora</h2>
                        <ul class="space-y-1">
                            @foreach ($shopSettings->contactLines() as $line)
                                <li>
                                    <span class="text-ink-muted">{{ $line['label'] }}:</span>
                                    @if ($line['href'])
                                        <a href="{{ $line['href'] }}" class="font-medium text-ink hover:underline">{{ $line['value'] }}</a>
                                    @else
                                        {{ $line['value'] }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <h2 class="mb-3 font-bold text-ink">Vše o nákupu</h2>
                    <nav aria-label="Informace o obchodě">
                        <ul class="space-y-1">
                            @foreach (($footerPages ?? collect()) as $page)
                                <li>
                                    <a href="{{ url('/'.$page->slug) }}" class="hover:text-brand hover:underline">{{ $page->title }}</a>
                                </li>
                            @endforeach
                            {{-- Permanent: withdrawing consent has to be as
                                 easy as giving it. --}}
                            <li>
                                <a href="{{ route('consent.show') }}" class="hover:text-brand hover:underline">Nastavení cookies</a>
                            </li>
                        </ul>
                    </nav>
                </div>

                @if ($shopSettings->socialLinks())
                    <div>
                        <h2 class="mb-3 font-bold text-ink">Sledujte nás</h2>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($shopSettings->socialLinks() as $link)
                                <li>
                                    {{-- rel="noopener" even without target: the
                                         merchant may point these anywhere. --}}
                                    <a href="{{ $link['url'] }}" rel="noopener nofollow" class="hover:text-brand hover:underline">{{ $link['network'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <p class="mt-10 border-t border-line pt-6 text-xs">&copy; {{ date('Y') }} {{ $shopName }}</p>
        </div>
    </footer>

    @if (($trackingCodes ?? []) !== [])
        @include('analytics::tracking')
    @endif

    @stack('tracking')

    <x-consent-banner />
</body>
</html>
