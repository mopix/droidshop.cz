{{--
    $shopNoindex and $defaultImage come from the shop's SEO settings
    (wave 3.6), which apply to every page rather than to the one the
    controller built its Seo for.

    The two are OR-ed, never overridden: a page that is noindex on its own
    (cart, checkout, thank-you) has to stay that way whatever the shop-wide
    switch says.
--}}
@props(['seo', 'shopName' => null, 'shopNoindex' => false, 'defaultImage' => null])

@php($image = $seo->image ?? $defaultImage)
@php($robots = ($shopNoindex || $seo->noindex) ? 'noindex, follow' : $seo->robots())

<title>{{ $seo->title }}</title>
@if ($seo->description)
    <meta name="description" content="{{ $seo->description }}">
@endif
<meta name="robots" content="{{ $robots }}">
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
@if ($image)
    <meta property="og:image" content="{{ $image }}">
@endif

<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo->title }}">
@if ($seo->description)
    <meta name="twitter:description" content="{{ $seo->description }}">
@endif
@if ($image)
    <meta name="twitter:image" content="{{ $image }}">
@endif
