<section class="mb-10 border-y border-line bg-surface py-6">
    <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($items as $item)
            <li class="flex items-center gap-3">
                <x-storefront::usp-icon :name="$item['icon'] ?? ''" class="h-8 w-8 shrink-0 text-brand" />
                <span>
                    <span class="block font-semibold text-ink">{{ $item['title'] ?? '' }}</span>
                    @if (!empty($item['subtitle']))
                        <span class="block text-sm text-ink-muted">{{ $item['subtitle'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</section>
