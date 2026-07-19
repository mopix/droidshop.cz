# As-is: FileStorage (Fáze 0 / vlna 0.4)

Datum: **2026-07-19** · Verze: **0.5.0** · Větev: `feat/filestorage`

Plán: [`docs/superpowers/plans/2026-07-19-faze-0-vlna-04-filestorage.md`](../superpowers/plans/2026-07-19-faze-0-vlna-04-filestorage.md)
Spec: §15.1 (`FileStorage`), §16.6 · Navazuje na [kernel služby](2026-07-19-kernel-sluzby.md)

## Co je hotové

Modul umí uložit a servírovat soubor přes službu jádra, aniž zná disk. Soubor jednoho tenanta je nedostupný jinému — fyzicky (prefix) i přes URL (podpis vázaný na host + tenanta). **Soubory zůstávají na naší VPS**, ne v S3.

### Mapa kódu

| Oblast | Soubory |
|---|---|
| Služba | `app/Core/Storage/FileStorage.php` |
| Ochrana cest | `app/Core/Storage/PathGuard.php`, `Exceptions/UnsafePath.php` |
| Servírování privátních | `app/Http/Controllers/Storage/PrivateFileController.php`, routa `storage.private` |
| Limit úložiště | `app/Core/Storage/StorageLimitCounter.php`, `Exceptions/StorageLimitExceeded.php` |
| Disky | `config/filesystems.php` (`tenant_public`, `tenant_private`) |
| Registrace počítadla | `app/Providers/AppServiceProvider.php` |

### Klíčová rozhodnutí, která kód drží

1. **Lokální disk, ne S3** (rozhodnutí uživatele 2026-07-19). `FileStorage` drží abstrakci — modul nikdy nesahá na disk přímo, přechod na S3 je změna configu. Platí, dokud běžíme na jedné VPS.
2. **Dva disky.** `tenant_public` (obrázky) servíruje web server přímo, symlink `public/media`. `tenant_private` (faktury, exporty) nemá URL, jen podepsaná routa.
3. **Podpis váže host i tenant param.** Privátní URL se generuje na doméně tenanta (ne platformy), aby fungovala i z fronty. Přehození domény nebo tenant ID v cestě zneplatní podpis.
4. **Path traversal se odmítá**, ne čistí — `PathGuard` je samostatná testovaná pojistka.

## Testy

**228 passed (415 assertions)** — z toho 39 nových.

| Sada | Co ověřuje |
|---|---|
| `PathGuardTest` | traversal ve všech podobách (18 případů) |
| `FileStorageTest` | ukládání pod prefix, izolace dvou tenantů, usage |
| `PrivateFileServingTest` | podpis, expirace, host-binding, manipulace tenant ID |
| `StorageLimitTest` | kumulativní limit `storage_mb`, tenant bez tarifu |

## Odchylky od plánu

| # | Odchylka | Důvod |
|---|---|---|
| 1 | Modul Pages nedostal nahrání obrázku (plán E3, „když zbude čas") | Řetěz je dokázán testy `FileStorageTest`/`StorageLimitTest`; UI pro upload přijde s adminem. |
| 2 | Limit se počítá v celých MB, delta zaokrouhlená nahoru | Je to pojistka (allow/warn/block), ne účetnictví. Konzervativní zaokrouhlení blokuje spíš dřív. |

## Technický dluh a známá omezení

1. **`StorageLimitCounter::count` prochází všechny soubory tenanta** (`allFiles` + `size`). Při tisících souborů to bude pomalé — chce cache usage nebo průběžné počítadlo v DB. Pro MVP dostačuje.
2. **Bez validace typu/velikosti uploadu na vstupu.** `FileStorage` uloží cokoliv; validaci MIME a max velikosti musí udělat volající (Form Request u nahrání). Poznámka pro modul produktů.
3. **Bez antivir skenu** — až bude potřeba, do `security_warnings.md`.
4. **Obrázkové řezy/resize nejsou** (spec §4.4) — job s modulem produktů.
5. **Lokální disk = jeden server.** Víc app serverů = nutné S3 (vědomé).
6. **`storage:link` musí běžet při deployi** — jinak veřejné soubory nemají URL.

## Pre-deploy checklist (nesplněno)

- [ ] `storage:link` v deploy pipeline
- [ ] Validace MIME + max velikost při nahrání (v modulech)
- [ ] Cache/průběžné počítadlo `storage_mb` (výkon)
- [ ] Zálohování `storage/app/tenant-*` v záloze VPS
