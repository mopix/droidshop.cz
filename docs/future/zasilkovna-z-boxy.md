# Z-BOXy a AlzaBoxy Zásilkovny

**Stav:** návrh, nerealizováno
**Vzniklo:** 2026-08-11, jako nález při vlně „doručení na adresu"

## Co se zjistilo

Vlna z 2026-08-11 měla mimo jiné odlišit samoobslužné Z-BOXy od poboček s obsluhou.
Vycházela z domněnky, že boxy už v katalogu jsou a chybí jen příznak, protože denní
sync bere z feedu všechna místa bez filtru.

**Ta domněnka byla mylná a úkol se proto neimplementoval.**

Voláme `https://www.zasilkovna.cz/api/v4/{key}/branch.json` (`config/packeta.php`), což
je feed **jen poboček**. Z-BOXy v něm nejsou vůbec a není v něm ani pole, které by typ
místa neslo. Boxy má Zásilkovna ve **zvláštním feedu** `box.json` na jiném hostu
(`pickup-point.api.packeta.com`) a v jiné verzi API; teprve tam existuje pole `type`
s hodnotami `zbox` a `alzabox`.

Doloženo třemi nezávislými zdroji: oficiální dokumentace Packety, cizí PHP klient
volající tutéž v4 URL jako my (jeho entita větve žádné pole typu nemá), a odpověď
podpory Packety citovaná v issue toho klienta.

Odvodit typ z názvu místa („Z-BOX Praha 4") bylo zvažováno a zamítnuto: je to
heuristika nad cizím volným textem, která se rozbije při prvním přejmenování poboček —
a rozbije se **tiše**, takže se na to přijde až tím, že zákazník dojede k místu, které
neexistuje v podobě, v jaké ho čekal.

## Co by bylo potřeba

### Druhý sync, ne „ještě jedno stažení"

Katalog má sloupec `pickup_points.carrier` od vlny 2.5, takže datově je připravený.
Práce je jinde:

- **Vlastní guard proti prázdné odpovědi, per feed.** `PickupPointSync` má dnes dva
  guardy (na počet položek v odpovědi a na počet použitelných kódů po zpracování) a
  oba měří celý katalog. Kdyby druhý feed vrátil prázdno a deaktivace běžela nad celou
  tabulkou, smaže místa toho prvního. Guard i deaktivace musí být omezené na množinu
  právě syncovaného feedu.
- **Ověřit, jestli stačí `PACKETA_FEED_API_KEY`.** Jiný host a jiná verze API mohou
  chtít jiné ověření. Bez reálného účtu to nejde zjistit — je to první věc k ověření
  při implementaci, ne předpoklad.
- **Sloupec `type`** (`branch` | `box`), výchozí `branch` u existujících řádků: jsou to
  pobočky, dokud další sync neřekne jinak, a to je pravdivější než `null`. Neznámou
  hodnotu z feedu mapovat na `branch`, nikdy nevyhazovat výjimku — cizí feed, který
  přidá třetí typ, nesmí shodit denní sync a s ním celý katalog.

### Pokladna

Filtr „jen výdejní boxy" jako **odkaz s query parametrem**, ne JavaScript — celý výběr
místa je server-rendered a musí fungovat bez JS (§16.3). U každého místa vypsat typ
**textem**, ne jen ikonou: barva ani obrázek nic nesdělí tomu, kdo je nevidí
(WCAG 1.4.1).

### Co je hotové a nebrání

Tři místa přidrátovaná k Zásilkovně jsou rozpojená vlnou z 2026-08-11:
`PickupPointCatalog::search()` má parametr dopravce, `PickupPointController` si
dopravce odvozuje z košíku, a `shipping_snapshot` nese `provider` na top-level.

## Proč to stojí za to

Z-BOX je otevřený nonstop a je jich hodně. E-shop, který je nabízí, aniž by to zákazník
poznal, přichází o výhodu, kterou už fakticky má — a zákazník, který box chce, ho v
seznamu nepozná od pobočky s otevírací dobou do 17:00.

## Odhad

Menší vlna: druhý sync s vlastním guardem, sloupec, filtr v pokladně. Nejistota je v
ověření (jiný host, možná jiný klíč), ne v rozsahu.
