<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-storefront::seo-meta :seo="$seo" :shop-name="$shopName" />

    <style>
        :root {
            --brand-primary: {{ $theme->primary }};
            --brand-primary-contrast: {{ $theme->primaryContrast }};
            --brand-accent: {{ $theme->accent }};
        }
    </style>
    @if ($theme->faviconUrl)
        <link rel="icon" href="{{ $theme->faviconUrl }}">
    @endif

    @stack('head')

    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    {{-- WCAG 2.4.1: keyboard users must be able to jump the navigation. --}}
    <a href="#obsah"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-contrast">
        Přeskočit na obsah
    </a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-4">
            <a href="/" class="flex items-center">
                @if ($theme->logoUrl)
                    <img src="{{ $theme->logoUrl }}" alt="{{ $shopName }}" class="h-8 w-auto">
                @else
                    <span class="text-lg font-semibold tracking-tight text-slate-900">{{ $shopName }}</span>
                @endif
            </a>

            <form action="/hledani" method="get" role="search" class="order-last w-full sm:order-none sm:ml-auto sm:w-auto">
                <label for="hledani" class="sr-only">Hledat v e-shopu</label>
                <div class="flex gap-2">
                    <input id="hledani" name="q" type="search" value="{{ request()->query('q') }}"
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
            &copy; {{ date('Y') }} {{ $shopName }}
        </div>
    </footer>
</body>
</html>
