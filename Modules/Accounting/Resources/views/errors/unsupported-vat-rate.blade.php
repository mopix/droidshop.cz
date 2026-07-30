<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Export nelze vytvořit</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #f9fafb;
        }
        main { max-width: 34rem; }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; }
        p { margin: 0 0 0.75rem; color: #4b5563; }
        a { color: #1f2937; }
        @media (prefers-color-scheme: dark) {
            body { color: #e5e7eb; background: #111827; }
            p { color: #9ca3af; }
            a { color: #e5e7eb; }
        }
    </style>
</head>
<body>
    <main>
        {{-- role="alert" so the message is announced, not just shown: the
             nájemce arrives here from a plain form submission, so this page
             is the only place the refusal is ever stated. --}}
        <h1>Export nelze vytvořit</h1>
        <p role="alert">{{ $message }}</p>
        <p><a href="{{ url()->previous() }}">Zpět na účetní export</a></p>
    </main>
</body>
</html>
