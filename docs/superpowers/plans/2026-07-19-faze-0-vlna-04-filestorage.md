# Fáze 0 / vlna 0.4 — FileStorage — implementační plán

> **Pro agenta:** superpowers:executing-plans / subagent-driven-development. Kroky `- [ ]`.

**Cíl:** Modul umí uložit a servírovat soubor, aniž zná disk — a soubor jednoho tenanta je fyzicky i přes URL nedostupný jinému tenantovi.

**Architektura:** `FileStorage` služba jádra nad Laravel Filesystem. Veřejné soubory (obrázky produktů) na disku symlinkovaném do webu, cachovatelné. Privátní (faktury, exporty) mimo web, přístup jen přes podepsanou dočasnou URL s kontrolou tenanta. Podkladový disk je `local`; přechod na S3 = změna configu, kód modulů se nedotkne.

**Tech stack:** Laravel 13, PHP 8.3, lokální disk. **Spec:** §15.1 (`FileStorage`), §16.6 (doklady) · Navazuje na [kernel služby](../../as-is/2026-07-19-kernel-sluzby.md)

**Rozhodnutí (2026-07-19):** úložiště lokální, ne S3 (CLAUDE.md). Veřejné i privátní soubory. Role: nahrává `TENANT_ADMIN`, veřejné čte kdokoliv (storefront), privátní jen vlastník-tenant.

---

## Bezpečnostní jádro (čte se první)

Tohle je celý důvod, proč to je služba jádra a ne `Storage::put()` v modulu:

1. **Každá cesta je vynuceně pod `tenants/{aktuální_id}/`.** Modul předá `products/5/main.jpg`, uloží se `tenants/12/products/5/main.jpg`. Tenant se nikdy nedostane mimo svůj prefix.
2. **Path traversal se odmítá, ne čistí potichu.** `../`, absolutní cesta, `..\\`, null byte → výjimka. Test na každou variantu.
3. **Privátní soubor nemá veřejnou URL.** Přístup jen přes `URL::temporarySignedRoute` s TTL; controller ověří podpis **a** že soubor patří aktuálnímu tenantovi. Lokální disk nemá nativní signed URL jako S3, proto přes podepsanou routu.
4. **Veřejné URL obsahuje tenant prefix** — soubory jsou veřejné, takže prefix v URL není únik; je to jen cesta.
5. **`deleteTenantPrefix()`** smaže vše tenanta z obou disků — pro purge tenanta (§6.0 AK).

---

## Kroky

### A. Disky a konfigurace

- [ ] A1. `config/filesystems.php` — dva disky: `tenant_public` (root `storage/app/tenant-public`, visibility public) a `tenant_private` (root `storage/app/tenant-private`, mimo web).
- [ ] A2. Symlink `public/media` → `storage/app/tenant-public` (přes `artisan storage:link` custom link v configu). Veřejné soubory pak jedou přímo z web serveru.
- [ ] A3. `.env.example` — `FILESYSTEM_DISK` zůstává `local`; doplnit komentář, že tenant disky jsou oddělené.
- [ ] A4. Commit `chore: add tenant storage disks`.

### B. `FileStorage` — jádro

- [ ] B1. Test `FileStorageTest`: uložení pod tenant prefix; čtení; přepis; `exists`; smazání; velikost. Vše přes dva tenanty, A nevidí soubory B. Červený.
- [ ] B2. `app/Core/Storage/FileStorage.php`:
  - `putPublic(string $path, $contents): string` → klíč
  - `putPrivate(string $path, $contents): string`
  - `get(string $path): string`, `exists`, `delete`, `size`
  - `publicUrl(string $path): string`
  - `signedUrl(string $path, int $ttl = 300): string`
  - `deleteTenantPrefix(): void`
- [ ] B3. `app/Core/Storage/PathGuard.php` — normalizace a odmítnutí traversalu. Samostatná třída, ať jde testovat izolovaně.
- [ ] B4. Vynucení tenant prefixu z `TenantContext`; bez kontextu výjimka `MissingTenantContext`.
- [ ] B5. Zeleně B1. Commit `feat: add FileStorage kernel service`.

### C. Path traversal — samostatná pojistka

- [ ] C1. Test `PathGuardTest`: odmítne `../x`, `/etc/passwd`, `a/../../b`, `..\\x`, cestu s null bytem, prázdnou cestu; **přijme** `products/5/main.jpg`, `a/b/c.png`. Červený.
- [ ] C2. Implementace v `PathGuard`. Zeleně. Commit `feat: reject path traversal in storage keys`.

### D. Servírování privátních souborů

- [ ] D1. Test `PrivateFileServingTest`: podepsaná URL vydá soubor; propadlá URL → 403; **URL tenanta A pro soubor A, otevřená v kontextu tenanta B → 404**; nepodepsaná → 403. Červený.
- [ ] D2. Route + controller `app/Http/Controllers/Storage/PrivateFileController.php` — `signed` middleware + kontrola tenanta, stream souboru.
- [ ] D3. Zeleně. Commit `feat: serve private files via signed tenant-scoped urls`.

### E. Napojení na modul + limit storage

- [ ] E1. `LimitCounter` pro `storage_mb` — sečte velikost souborů tenanta. Registrace do `LimitsService`. (Konečně první skutečné počítadlo — zavře poznámku z as-is 0.3.)
- [ ] E2. Test: uložení souboru zvedne `usage('storage_mb')`; překročení limitu tarifu → `putPublic`/`putPrivate` odmítne s čitelnou chybou.
- [ ] E3. Modul **Pages** dostane nahrání obrázku na důkaz řetězu (volitelně, když zbude čas — jinak jen test proti fixture).
- [ ] E4. Zeleně. Commit `feat: enforce storage_mb limit on upload`.

### F. Uzavření

- [ ] F1. `pint --test` celý projekt.
- [ ] F2. `php artisan test` zeleně, výstup do PR.
- [ ] F3. `docs/as-is/…-filestorage.md` + `STATUS.md`.
- [ ] F4. `VERSION` → `0.5.0` + `CHANGELOG.md`.
- [ ] F5. Merge.

---

## Strategie testů

| Vrstva | Co |
|---|---|
| Unit | `PathGuard` — všechny varianty traversalu |
| Feature | `FileStorage` přes dva tenanty, izolace |
| Feature | Privátní soubor: podpis, expirace, cizí tenant → 404 |
| Feature | Limit `storage_mb` při nahrání |

Soubory se v testech ukládají do izolovaného tmp rootu (`Storage::fake` nebo dedikovaný test disk), ať test nešahá na reálné `storage/`.

## Rizika a mitigace

| Riziko | Dopad | Mitigace |
|---|---|---|
| Path traversal mimo tenant prefix | **kritický** (únik mezi tenanty) | `PathGuard` + vlastní test sada C |
| Privátní soubor dostupný cizímu tenantovi | **kritický** | Kontrola tenanta v controlleru, test D1 |
| Symlink v CI/produkci chybí | střední | `storage:link` v deploy kroku; test čte přes disk, ne přes URL |
| Lokální disk = jeden server | střední | Vědomé (CLAUDE.md); abstrakce drží swap na S3 |
| Neošetřený upload (typ, velikost) | střední | Validace v místě nahrání; limit `storage_mb` v E |

## Mimo rozsah

- Obrázkové řezy / resize (spec §4.4) — job, přijde s modulem produktů
- CDN — fáze 2
- Antivir sken uploadu — až bude potřeba, poznámka do `security_warnings.md`
