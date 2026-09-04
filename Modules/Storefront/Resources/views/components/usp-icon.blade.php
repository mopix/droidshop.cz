@props(['name'])

{{--
    One icon from the closed set (Modules\Storefront\Support\UspIcons), drawn
    inline so a benefits strip costs no extra request.

    Decorative by definition: the label beside it carries the meaning, so the
    icon is hidden from assistive technology rather than described twice.
--}}
@php
    $paths = [
        'truck' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'shield' => '<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/>',
        'leaf' => '<path d="M4 20c0-8 6-14 16-15 1 10-5 16-13 16H4z"/><path d="M4 20c4-4 8-6 12-7"/>',
        'award' => '<circle cx="12" cy="9" r="5"/><path d="M9 13l-2 8 5-3 5 3-2-8"/>',
        'heart' => '<path d="M12 20s-7-4.5-7-9a4 4 0 017-2.6A4 4 0 0119 11c0 4.5-7 9-7 9z"/>',
        'gift' => '<path d="M4 11h16v9H4z"/><path d="M2 7h20v4H2z"/><path d="M12 7v13"/><path d="M12 7S9 3 7.5 4.5 9 7 12 7zM12 7s3-4 4.5-2.5S15 7 12 7z"/>',
        'headset' => '<path d="M4 13a8 8 0 0116 0"/><path d="M4 13v4a2 2 0 002 2h1v-6H6a2 2 0 00-2 2z"/><path d="M20 13v4a2 2 0 01-2 2h-1v-6h1a2 2 0 012 2z"/>',
        'refresh' => '<path d="M4 12a8 8 0 0113.6-5.6L20 9"/><path d="M20 4v5h-5"/><path d="M20 12a8 8 0 01-13.6 5.6L4 15"/><path d="M4 20v-5h5"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
        'sparkles' => '<path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="M18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="14" r="1.2"/>',
    ];
@endphp

@if (isset($paths[$name]))
    <svg {{ $attributes->merge(['class' => 'h-7 w-7', 'stroke-width' => '1.6']) }}
         viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        {!! $paths[$name] !!}
    </svg>
@endif
