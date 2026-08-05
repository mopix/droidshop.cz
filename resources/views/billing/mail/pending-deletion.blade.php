<x-mail::message>
# E-shop je připraven ke smazání

E-shop **{{ $tenant->name }}** byl označen ke smazání. Data zatím zůstávají uložená a smazání jde ještě zastavit.

@if ($reason !== '')
Důvod: {{ $reason }}
@endif

<x-mail::button :url="config('app.url')">Kontaktovat podporu</x-mail::button>

Pokud chcete e-shop zachovat, ozvěte se nám prosím co nejdříve. Po smazání už data obnovit nelze.

Děkujeme,<br>DroidShop.cz
</x-mail::message>
