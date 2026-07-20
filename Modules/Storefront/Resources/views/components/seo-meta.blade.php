@props(['seo', 'shopName' => null])

<title>{{ $seo->title }}</title>
@if ($seo->description)
    <meta name="description" content="{{ $seo->description }}">
@endif
<meta name="robots" content="{{ $seo->robots() }}">
<link rel="canonical" href="{{ $seo->canonical ?? url()->current() }}">
@if ($seo->prev)
    <link rel="prev" href="{{ $seo->prev }}">
@endif
@if ($seo->next)
    <link rel="next" href="{{ $seo->next }}">
@endif

<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:url" content="{{ $seo->canonical ?? url()->current() }}">
@if ($shopName)
    <meta property="og:site_name" content="{{ $shopName }}">
@endif
@if ($seo->description)
    <meta property="og:description" content="{{ $seo->description }}">
@endif
@if ($seo->image)
    <meta property="og:image" content="{{ $seo->image }}">
@endif

<meta name="twitter:card" content="{{ $seo->image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo->title }}">
@if ($seo->description)
    <meta name="twitter:description" content="{{ $seo->description }}">
@endif
@if ($seo->image)
    <meta name="twitter:image" content="{{ $seo->image }}">
@endif
