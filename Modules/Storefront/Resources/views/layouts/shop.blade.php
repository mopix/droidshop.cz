<!DOCTYPE html>
{{--
    data-consent-version is read by storefront.js to tell a current decision
    from one recorded against older wording. Per tenant, not per visitor, so
    it is safe inside cached HTML.
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

    {{--
        Theme tokens first, the merchant's own brand after them: a shop with a
        red logo must not turn orange because its owner liked a theme's
        layout. Token values are printed unescaped on purpose — they have been
        through ThemeResolver::sanitizeToken(), and {{ }} would only escape
        HTML, which is not the syntax that matters inside a <style> block.
    --}}
    @if ($theme->faviconUrl)
        <link rel="icon" href="{{ $theme->faviconUrl }}">
    @endif

    @stack('head')

    {{--
        Hides the cookie banner before the first paint.

        The banner is baked into every cached page (see the component for
        why), so for a visitor who already decided it would flash on every
        single page load if this waited for the deferred bundle at the end of
        the body. Inline, in the head, it costs one class on <html>.

        Deliberately not a full consent parser — it only asks "is there a
        decision recorded against the current version". The real reading is
        done by storefront.js, which also decides what may load.
    --}}
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

    {{-- The shared bundle plus this theme's own stylesheet, when it has one. --}}
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
<body class="min-h-screen bg-white text-slate-900 antialiased">
    {{-- WCAG 2.4.1: keyboard users must be able to jump the navigation. --}}
    <a href="#obsah"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-contrast">
        Přeskočit na obsah
    </a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-4">
            <a href="/" class="flex items-center gap-3">
                @if ($theme->logoUrl)
                    <img src="{{ $theme->logoUrl }}" alt="{{ $shopName }}" class="h-8 w-auto">
                @else
                    <span class="text-lg font-semibold tracking-tight text-slate-900">{{ $shopName }}</span>
                @endif

                {{-- Hidden on the narrowest screens: the header there is
                     already the logo, search and cart competing for one row. --}}
                @if ($shopSettings->tagline)
                    <span class="hidden text-sm text-slate-500 sm:inline">{{ $shopSettings->tagline }}</span>
                @endif
            </a>

            <form action="/hledani" method="get" role="search" class="order-last w-full sm:order-none sm:ml-auto sm:w-auto">
                <label for="hledani" class="sr-only">Hledat v e-shopu</label>
                <div class="flex gap-2">
                    {{--
                        Folded the same way PageCacheKey::foldSearchTerm() folds
                        the term for the cache key and SearchController folds it
                        for the heading/title/canonical: this layout is shared by
                        every storefront page a page-cache entry can be built
                        from, so echoing the raw query string here would leave
                        one raw fragment on an otherwise-folded cached page —
                        exactly the half-fold this must not do.
                    --}}
                    <input id="hledani" name="q" type="search"
                           value="{{ \App\Core\PageCache\PageCacheKey::foldSearchTerm((string) request()->query('q', '')) }}"
                           class="field-input mt-0 w-full sm:w-64"
                           placeholder="Hledat…">
                    <button type="submit" class="btn btn-primary">Hledat</button>
                </div>
            </form>

            @if ($cartEnabled)
                {{--
                    A plain link, no item count: the count is per-visitor
                    state and this layout is shared by every storefront page,
                    including ones a page-cache layer may one day serve from
                    a shared cache (spec §15.6). The mini-cart island fetches
                    its own count from GET /api/kosik/souhrn instead.
                --}}
                <a href="{{ route('storefront.checkout.show') }}"
                   class="text-sm font-medium text-slate-700 hover:text-brand hover:underline">Košík</a>
            @endif

            @if ($customerAreaEnabled)
                <nav aria-label="Účet zákazníka" class="text-sm font-medium">
                    @if ($signedInCustomer)
                        <a href="{{ route('storefront.customers.account') }}"
                           class="text-slate-700 hover:text-brand hover:underline">Můj účet</a>
                    @else
                        <a href="{{ route('storefront.customers.login') }}"
                           class="text-slate-700 hover:text-brand hover:underline">Přihlásit se</a>
                    @endif
                </nav>
            @endif
        </div>

        @if ($navCategories->isNotEmpty())
            <nav aria-label="Kategorie" class="border-t border-slate-100 bg-slate-50">
                <ul class="mx-auto flex max-w-6xl flex-wrap gap-1 px-4 py-1 text-sm">
                    @foreach ($navCategories as $category)
                        <li>
                            {{-- Hover state pairs colour with an underline so
                                 it does not rely on colour alone (WCAG 1.4.1). --}}
                            <a href="{{ $category->url() }}"
                               class="inline-block rounded-lg px-3 py-2 font-medium text-slate-600 hover:text-brand hover:underline">
                                {{ $category->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    <main id="obsah" class="mx-auto max-w-6xl px-4 py-8">
        @yield('content')
    </main>

    <footer class="mt-16 border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-slate-600">
            <nav aria-label="Informace o obchodě" class="mb-4">
                <ul class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (($footerPages ?? collect()) as $page)
                        <li>
                            <a href="{{ url('/'.$page->slug) }}" class="underline hover:text-slate-900">
                                {{ $page->title }}
                            </a>
                        </li>
                    @endforeach
                    {{--
                        Permanent, on every page: withdrawing consent has to be
                        as easy as giving it, and the banner is gone once a
                        decision exists.
                    --}}
                    <li>
                        <a href="{{ route('consent.show') }}" class="underline hover:text-slate-900">
                            Nastavení cookies
                        </a>
                    </li>
                </ul>
            </nav>

            {{--
                Contact details, if the merchant filled any in and left the box
                switched on. Nothing filled in renders nothing at all — an
                empty "Kontakt" heading reads as a shop that lost its own
                phone number.
            --}}
            @if ($shopSettings->show_footer_contact && $shopSettings->hasContactDetails())
                <div class="mb-6 grid gap-6 sm:grid-cols-2">
                    @if ($shopSettings->contactLines())
                        <div>
                            <h2 class="mb-2 font-semibold text-slate-900">Kontakt</h2>
                            <ul class="space-y-1">
                                @foreach ($shopSettings->contactLines() as $line)
                                    <li>
                                        <span class="text-slate-500">{{ $line['label'] }}:</span>
                                        @if ($line['href'])
                                            <a href="{{ $line['href'] }}" class="underline hover:text-slate-900">{{ $line['value'] }}</a>
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
                            <h2 class="mb-2 font-semibold text-slate-900">Sledujte nás</h2>
                            <ul class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($shopSettings->socialLinks() as $link)
                                    <li>
                                        {{-- rel="noopener" on a target-less link too: the
                                             merchant may point these anywhere. --}}
                                        <a href="{{ $link['url'] }}" rel="noopener nofollow"
                                           class="underline hover:text-slate-900">{{ $link['network'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <p>&copy; {{ date('Y') }} {{ $shopName }}</p>
        </div>
    </footer>

    {{--
        Measurement snippets, if the shop runs the analytics module and has
        anything configured. After the banner's own script, so
        window.droidshopConsent already exists when this runs.
    --}}
    @if (($trackingCodes ?? []) !== [])
        @include('analytics::tracking')
    @endif

    @stack('tracking')

    <x-consent-banner />
</body>
</html>
