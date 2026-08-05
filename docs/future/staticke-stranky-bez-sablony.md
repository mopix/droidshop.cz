# Statické stránky stojí mimo šablonu storefrontu

Nález z vlny 3.0 (page cache), 2026-08-05. Není to chyba page cache — je starší než ona a vlna ho jen odhalila.

## Co je špatně

`Modules/Pages/Resources/views/show.blade.php` je **samostatný HTML dokument**: vlastní `<!DOCTYPE html>`, vlastní `<head>`, `@vite(['resources/css/storefront.css'])`, jeden `<main>` a natvrdo zapsané `bg-white text-slate-900`. Nemá `@extends`, `@include` ani `<x-storefront::…>`, takže **nikdy nepoužije `storefront::layouts.shop`**.

Důsledky pro nájemce:

- **Žádný branding.** Vlna 2.2 prodává přebarvení storefrontu přes CSS proměnné injektované do layoutu shopu. VOP, GDPR, kontakt a další statické stránky z toho vypadávají — jsou bílé bez ohledu na to, co si nájemce nastaví ve „Vzhledu".
- **Žádná hlavička, navigace, patička ani košík.** Jediná cesta zpět je holý odkaz `← Zpět do e-shopu`.
- **Nekonzistentní zážitek.** Zákazník, který klikne na obchodní podmínky v patičce, opustí vizuální identitu e-shopu uprostřed nákupu.

## Jak to našla vlna 3.0

Závěrečné review tvrdilo, že routa statických stránek potřebuje dimenzi `catalog`, protože sdílený layout renderuje navigaci kategorií. Implementer napsal test, ten spadl už na zahřívací aserci — a tím se ukázalo, že se tam žádná kategorie nerenderuje, protože layout tam vůbec není. Nález byl **správně zamítnut**; přidání `catalog` by překreslovalo statické stránky při každém odpisu skladu, tedy při každé objednávce, kvůli obsahu, který na nich není.

V `tests/Feature/PageCache/` zůstal test `test_a_static_page_renders_no_catalogue_data`, který spadne v den, kdy se pohled přesune na sdílený layout — což je přesně ten okamžik, kdy se tahle položka stane aktuální.

## Co udělat

Přepsat `pages::show` tak, aby dědil ze `storefront::layouts.shop`. Pak:

- statické stránky převezmou branding, navigaci i patičku,
- routa bude potřebovat dimenze `page-cache:catalog,content,theme` místo dnešních `content,theme` (dnes je i `theme` dekorativní ze stejného důvodu),
- zmíněný test začne padat a musí se odstranit.

## Proč to nešlo hned

Mimo rozsah vlny 3.0 — je to změna prezentace modulu `pages`, ne cache. Cache je dnes nastavená správně **vůči tomu, co se skutečně renderuje**.
