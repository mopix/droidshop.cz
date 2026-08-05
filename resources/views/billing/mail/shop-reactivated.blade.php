<x-mail::message>
# E-shop je opět aktivní

E-shop **{{ $tenant->name }}** je znovu v provozu. Vaše data zůstala beze změny — produkty, objednávky i nastavení najdete tam, kde jste je nechali.

<x-mail::button :url="config('app.url')">Přejít do administrace</x-mail::button>

Děkujeme,<br>DroidShop.cz
</x-mail::message>
