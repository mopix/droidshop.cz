<section class="py-8">
    @if (!empty($heading))
        <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ $heading }}</h2>
    @endif
    <ul class="flex flex-wrap gap-3">
        @foreach ($categories as $category)
            <li><a href="{{ $category->url() }}" class="btn btn-outline">{{ $category->name }}</a></li>
        @endforeach
    </ul>
</section>
