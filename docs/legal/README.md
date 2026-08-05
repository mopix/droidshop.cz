# Právní dokumenty platformy

Tato složka je **zdroj pravdy** pro právní texty DroidShop.cz. Renderované stránky pod `/pravni/*` z nich vycházejí, ale nečtou je za běhu — Blade šablony v `resources/views/legal/` nesou stejný text, aby se nemusel přidávat Markdown parser kvůli čtyřem dokumentům.

**Když měníš text, měň obojí.** Na to hlídá test `tests/Feature/Legal/LegalPagesTest.php` jen částečně (ověřuje klíčové pasáže, ne celý text).

## Dokumenty

| Soubor | Renderuje se na | Kdo jsme v tom vztahu |
|--------|-----------------|------------------------|
| [`vseobecne-obchodni-podminky.md`](vseobecne-obchodni-podminky.md) | `/pravni/obchodni-podminky` | poskytovatel služby nájemci |
| [`zasady-zpracovani-osobnich-udaju.md`](zasady-zpracovani-osobnich-udaju.md) | `/pravni/ochrana-osobnich-udaju` | **správce** údajů nájemce |
| [`zpracovatelska-smlouva.md`](zpracovatelska-smlouva.md) | `/pravni/zpracovani-udaju` | **zpracovatel** údajů zákazníků nájemce |
| [`zasady-cookies.md`](zasady-cookies.md) | `/pravni/cookies` | správce |

## Dvě různé role podle GDPR

Snadno se to plete a plete se to i v cizích šablonách, které kolují po internetu:

- Vůči **nájemci** jsme **správce**. Jeho jméno, e-mail a fakturační údaje zpracováváme pro sebe — plníme s ním smlouvu a fakturujeme mu.
- Vůči **koncovým zákazníkům nájemce** jsme **zpracovatel**. Jejich údaje zpracováváme jen proto, že nám je nájemce svěřil, a jen podle jeho pokynů. Správcem je nájemce.

Proto jsou to dva oddělené dokumenty, ne jeden. Zpracovatelskou smlouvu (čl. 28 GDPR) musíme mít s každým nájemcem uzavřenou — bez ní je zpracování protiprávní pro obě strany.

## Verzování

`config('legal.terms_version')` je datum, které se ukládá k souhlasu nájemce (`users.terms_version`). Bump ho, když je změna natolik věcná, že souhlas se starým zněním nové už nepokrývá. Historie znění je v gitu.

`config('legal.effective_from')` je datum účinnosti tištěné na stránkách. Je oddělené schválně: oprava překlepu se dá publikovat, aniž by zneplatnila souhlasy.

## Stav

**Drafty bez právní revize** (rozhodnutí vlastníka 2026-08-05). Formulace jsou vedené konzervativně — raději širší povinnosti nám a užší omezení nájemci — aby draft nebyl horší než žádný text.

Místa, kde je právní rozhodnutí opravdu potřeba, nesou v textu marker:

```
> **K PRÁVNÍ REVIZI:** …
```

Před spuštěním je projít s právníkem. Zejména limitace náhrady škody, SLA a rozsah auditního práva ve zpracovatelské smlouvě.
