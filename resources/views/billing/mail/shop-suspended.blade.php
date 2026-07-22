<x-mail::message>
# E-shop byl pozastaven

E-shop **{{ $tenant->name }}** byl pozastaven kvůli nedokončenému předplatnému. Storefront ani administrace nejsou dál dostupné, dokud platbu nedokončíte.

<x-mail::button :url="config('app.url')">Přejít na účet</x-mail::button>

Děkujeme,<br>DroidShop.cz
</x-mail::message>
