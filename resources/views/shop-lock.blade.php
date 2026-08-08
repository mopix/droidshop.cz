<!DOCTYPE html>
{{--
    The whole page for a shop behind a password. Deliberately standalone
    rather than an extension of the storefront layout: that layout renders the
    header, the category navigation and the footer, which is exactly the
    catalogue this page exists not to show.
--}}
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shopName }}</title>
    {{-- Locked means not ready, whatever the SEO screen says. --}}
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/css/storefront.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 text-slate-900 antialiased">
    <main class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold">{{ $shopName }}</h1>
        <p class="mt-2 text-sm text-slate-600">
            E-shop se právě připravuje. Pokud máte heslo, zadejte ho níže.
        </p>

        <form method="post" action="{{ route('shop.unlock') }}" class="mt-6">
            @csrf

            <label for="shop-password" class="block text-sm font-medium text-slate-700">Heslo</label>
            <input id="shop-password"
                   name="password"
                   type="password"
                   autocomplete="current-password"
                   required
                   autofocus
                   @error('password') aria-describedby="shop-password-error" aria-invalid="true" @enderror
                   class="field-input mt-1 w-full">

            @error('password')
                <p id="shop-password-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn btn-primary mt-4 w-full">Vstoupit</button>
        </form>
    </main>
</body>
</html>
