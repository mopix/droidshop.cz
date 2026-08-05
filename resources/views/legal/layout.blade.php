{{--
    Standalone layout for the platform's legal documents.

    Deliberately not the storefront layout (that one belongs to a tenant, on
    a different host, with the tenant's own colours) and not the Inertia app
    shell (these pages must render without JavaScript and without a build).
    No Vite directive at all: a legal document that stops rendering because
    an asset manifest is missing is worse than a plain one.
--}}
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — DroidShop.cz</title>
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="{{ $canonical }}">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.65;
            color: #1e293b;
            background: #f8fafc;
        }
        .page { max-width: 46rem; margin: 0 auto; padding: 2rem 1.25rem 5rem; }
        header.site { border-bottom: 1px solid #e2e8f0; background: #fff; }
        header.site .inner { max-width: 46rem; margin: 0 auto; padding: 1rem 1.25rem; }
        header.site a { color: #0f172a; font-weight: 600; text-decoration: none; }
        h1 { font-size: 1.875rem; line-height: 1.25; margin: 0 0 .5rem; color: #0f172a; }
        h2 { font-size: 1.25rem; margin: 2.5rem 0 .75rem; color: #0f172a; }
        h3 { font-size: 1.05rem; margin: 1.75rem 0 .5rem; color: #0f172a; }
        p, li { font-size: 1rem; }
        ul, ol { padding-left: 1.25rem; }
        a { color: #1d4ed8; }
        .meta { color: #64748b; font-size: .875rem; margin: 0 0 2rem; }
        .lead { font-size: 1.05rem; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .9375rem; }
        th, td { border: 1px solid #e2e8f0; padding: .5rem .625rem; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 600; }
        .table-scroll { overflow-x: auto; }
        .callout {
            border-left: 4px solid #0ea5e9;
            background: #f0f9ff;
            padding: .875rem 1rem;
            margin: 1.5rem 0;
        }
        .callout p { margin: 0; }
        footer.site {
            border-top: 1px solid #e2e8f0;
            margin-top: 3rem;
            padding-top: 1.5rem;
            font-size: .875rem;
            color: #64748b;
        }
        footer.site ul { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 1rem; }
    </style>
</head>
<body>
<header class="site">
    <div class="inner"><a href="{{ url('/') }}">DroidShop.cz</a></div>
</header>

<main class="page">
    <h1>@yield('title')</h1>
    <p class="meta">Účinnost od {{ $effectiveFrom }}@yield('version')</p>

    @yield('body')

    <footer class="site">
        <ul>
            <li><a href="{{ route('legal.show', 'obchodni-podminky') }}">Obchodní podmínky</a></li>
            <li><a href="{{ route('legal.show', 'ochrana-osobnich-udaju') }}">Ochrana osobních údajů</a></li>
            <li><a href="{{ route('legal.show', 'zpracovani-udaju') }}">Zpracování údajů (GDPR)</a></li>
            <li><a href="{{ route('legal.show', 'cookies') }}">Cookies</a></li>
        </ul>
        <p>{{ $company['name'] }}, IČO {{ $company['ico'] }}</p>
    </footer>
</main>
</body>
</html>
