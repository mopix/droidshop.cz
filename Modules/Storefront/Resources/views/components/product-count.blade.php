@props(['count'])

{{--
    "1 výrobek / 3 výrobky / 12 výrobků".

    Not trans_choice: Laravel's message selector has no Czech rule, so it picks
    the second form for everything above four and writes "12 výrobky". Czech
    needs one form for 1, a second for 2–4, and a third for 0 and 5 upwards.
--}}
@php
    $count = (int) $count;
    $word = match (true) {
        $count === 1 => 'výrobek',
        $count >= 2 && $count <= 4 => 'výrobky',
        default => 'výrobků',
    };
@endphp
{{ $count }} {{ $word }}
