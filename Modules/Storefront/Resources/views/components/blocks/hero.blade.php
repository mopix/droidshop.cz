<section class="border-b border-slate-100 pb-10">
    <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ $title ?? '' }}</h1>
    @if (!empty($subtitle))
        <p class="mt-3 max-w-2xl text-slate-600">{{ $subtitle }}</p>
    @endif
    @if (!empty($cta_label) && !empty($cta_url))
        <a href="{{ $cta_url }}" class="btn btn-primary mt-6 inline-block">{{ $cta_label }}</a>
    @endif
    @if (!empty($image_path))
        <img src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($image_path) }}"
             alt="{{ $alt ?? '' }}" loading="eager" class="mt-6 w-full rounded-lg">
    @endif
</section>
