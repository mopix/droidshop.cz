<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-storefront::seo-meta :seo="$seo" :shop-name="$shopName" />

    @stack('head')

    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    {{-- WCAG 2.4.1: keyboard users must be able to jump the navigation. --}}
    <a href="#obsah"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-slate-900 focus:px-4 focus:py-2 focus:text-white">
        Přeskočit na obsah
    </a>

    <header class="border-b border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-4">
            <a href="/" class="text-lg font-semibold tracking-tight">{{ $shopName }}</a>

            <form action="/hledani" method="get" role="search" class="order-last w-full sm:order-none sm:ml-auto sm:w-auto">
                <label for="hledani" class="sr-only">Hledat v e-shopu</label>
                <div class="flex gap-2">
                    <input id="hledani" name="q" type="search" value="{{ request()->query('q') }}"
                           class="w-full rounded border border-slate-300 px-3 py-2 sm:w-64"
                           placeholder="Hledat…">
                    <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Hledat</button>
                </div>
            </form>

            @if ($customerAreaEnabled)
                <nav aria-label="Účet zákazníka" class="text-sm">
                    @if ($signedInCustomer)
                        <a href="{{ route('storefront.customers.account') }}" class="hover:underline">Můj účet</a>
                    @else
                        <a href="{{ route('storefront.customers.login') }}" class="hover:underline">Přihlásit se</a>
                    @endif
                </nav>
            @endif
        </div>

        @if ($navCategories->isNotEmpty())
            <nav aria-label="Kategorie" class="border-t border-slate-100">
                <ul class="mx-auto flex max-w-6xl flex-wrap gap-4 px-4 py-2 text-sm">
                    @foreach ($navCategories as $category)
                        <li>
                            <a href="{{ $category->url() }}" class="hover:underline">{{ $category->name }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    <main id="obsah" class="mx-auto max-w-6xl px-4 py-8">
        @yield('content')
    </main>

    <footer class="mt-16 border-t border-slate-200">
        <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-slate-600">
            &copy; {{ date('Y') }} {{ $shopName }}
        </div>
    </footer>
</body>
</html>
