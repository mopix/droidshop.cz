@php
    $tenant = app(\App\Core\Tenancy\TenantContext::class)->current();
@endphp

@if ($tenant === null)
    {{-- Platform host: no shop template applies. --}}
    <!DOCTYPE html>
    <html lang="cs">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Stránka nenalezena</title>
    </head>
    <body><h1>Stránka nenalezena</h1></body>
    </html>
@else
    @include('storefront::shop-error', [
        'heading' => 'Stránka nenalezena',
        'message' => 'Adresa neexistuje nebo se změnila.',
        'seo' => new \Modules\Storefront\Support\Seo(title: 'Stránka nenalezena', noindex: true),
    ])
@endif
