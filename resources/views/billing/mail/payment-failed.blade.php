<x-mail::message>
# Platbu se nepodařilo zpracovat

Platbu za předplatné e-shopu **{{ $tenant->name }}** se nepodařilo zpracovat. E-shop běží dál, ale prosím zkontrolujte platební kartu.

<x-mail::button :url="config('app.url')">Spravovat předplatné</x-mail::button>

Pokud platba nedorazí ani po opakovaných pokusech, e-shop pozastavíme.

Děkujeme,<br>DroidShop.cz
</x-mail::message>
