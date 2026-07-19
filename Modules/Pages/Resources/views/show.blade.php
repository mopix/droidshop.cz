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
</head>
<body>
    <main>
        <h1>{{ $page->title }}</h1>
        <div>{!! nl2br(e($page->body)) !!}</div>
    </main>
</body>
</html>
