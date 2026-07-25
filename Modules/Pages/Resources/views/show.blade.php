<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seo_title ?: $page->title }}</title>
    @if ($page->seo_description)
        <meta name="description" content="{{ $page->seo_description }}">
    @endif
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $page->seo_title ?: $page->title }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($page->seo_description)
        <meta property="og:description" content="{{ $page->seo_description }}">
    @endif

    @vite(['resources/css/storefront.css'])
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    <main class="mx-auto max-w-3xl px-4 py-10">
        <p class="mb-6">
            <a href="/" class="text-sm text-slate-600 hover:underline">&larr; Zpět do e-shopu</a>
        </p>

        <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">{{ $page->title }}</h1>

        <div class="prose-shop mt-6">{!! nl2br(e($page->body)) !!}</div>
    </main>
</body>
</html>
