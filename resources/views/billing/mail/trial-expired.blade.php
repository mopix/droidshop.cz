<x-mail::message>
# Zkušební období skončilo

E-shop **{{ $tenant->name }}** má za sebou 14denní zkušební období. E-shop je zatím dál dostupný, ale pro pokračování prosím dokončete předplatné.

<x-mail::button :url="config('app.url')">Přejít na účet</x-mail::button>

Děkujeme,<br>DroidShop.cz
</x-mail::message>
