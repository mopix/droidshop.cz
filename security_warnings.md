# Security warnings — DroidShop.cz

Zaznamenávej potenciální bezpečnostní rizika nalezená během vývoje.
Formát: datum, oblast, popis, závažnost, stav (open / mitigated / accepted).

## 2026-07-22 — CSV formula injection ve VAT exportu (CWE-1236)

- **Oblast:** `Modules/Docs/Support/VatCsvWriter.php` (accountant VAT CSV export, vlna 1.6)
- **Popis:** zákaznická billing pole (`odberatel`/name, `ico`, `dic`) jsou jen délkově/typově validovaná při checkoutu, bez omezení znaků. Hodnota jako `=HYPERLINK("http://evil","click")` zapsaná do CSV buňky je Excelem/LibreOffice interpretována jako vzorec a spustí se u účetní nájemce (customer→tenant-staff trust boundary).
- **Závažnost:** important
- **Stav:** mitigated — `VatCsvWriter::neutralize()` prefixuje buňku uvozovkou `'`, pokud první znak je `=`, `+`, `-`, `@`, TAB, CR nebo LF. Aplikováno na textové sloupce (`cislo`, `typ`, `vystaveno`, `duzp`, `odberatel`, `ico`, `dic`, `mena`); peněžní sloupce (`zaklad_*`, `dph_*`, `celkem`) jsou záměrně vyňaty, protože je generuje interně `number_format()` a legitimní záporná částka dobropisu začínající `-` by se jinak nechtěně o-escapovala. Test: `tests/Feature/Modules/Docs/VatExportTest.php`.

## 2026-07-23 — On-demand TLS abuse / Let's Encrypt rate-limit (vlna 2.1)

- **Oblast:** `app/Http/Controllers/Internal/TlsCheckController.php`, Caddy on-demand TLS
- **Popis:** Caddy on-demand vydá TLS cert pro libovolný host, který ask endpoint schválí. Bez ověření vlastnictví by nájemce mohl nechat vydat cert na cizí doménu a vyčerpat LE rate-limit.
- **Závažnost:** critical (kdyby bez ochran)
- **Stav:** mitigated — emisi autorizuje `/internal/tls-check` jen pro doménu s `verified_at != null` **a** `type=Custom` **a** tenanta s `allowsStorefront()`. Vlastnictví (`DomainVerifier`: TXT challenge token + routing) je povinné před `ssl_status=pending`, který teprve odemkne emisi. Výsledek cachován (`tls_check_ttl`). Test: `tests/Feature/Domains/TlsCheckTest.php`, `DomainVerifierTest.php`.

## 2026-07-23 — /internal/tls-check enumeration oracle za reverse proxy (vlna 2.1)

- **Oblast:** `app/Http/Controllers/Internal/TlsCheckController.php`, `app/Http/Middleware/AllowLocalOnly.php`
- **Popis:** `AllowLocalOnly` gatuje na `$request->ip()`, ale Caddy reverse-proxuje na app na témže hostu, takže `REMOTE_ADDR` je `127.0.0.1` pro **všechny** proxovan requesty (i veřejný provoz). IP guard sám neodliší Caddy ask od veřejného requestu → 200-vs-404 by prozradilo, které custom domény jsou verified+aktivní (info-disclosure, ne mis-issuance — endpoint neinicializuje emisi a bool vždy odráží reálný stav).
- **Závažnost:** important
- **Stav:** mitigated — **shared-secret token** (`config('platform.tls_check_token')`, `hash_equals`, fail-closed na prázdný config) ověřen před lookupem; token je zapečený v Caddyfile ask URL, nezávislý na topologii. `AllowLocalOnly` ponechán jako obrana do hloubky. **Deploy:** Caddyfile musí zamítnout veřejný `/internal/*` (viz `docs/as-is/2026-07-23-custom-domains.md` runbook). Test: `tests/Feature/Domains/TlsCheckTest.php` (missing/wrong token → 404).

## 2026-08-10 — HtmlSanitizer::isSafeUrl přijímá protocol-relative a backslash open redirecty (rich text editor, vlna admin RTE)

- **Oblast:** `app/Core/Html/HtmlSanitizer.php::isSafeUrl()`
- **Popis:** metoda považuje **cokoli** začínající `/` za bezpečné (`str_starts_with($url, '/')`), včetně `//evil.com` (prohlížeč to vyhodnotí jako `https://evil.com` — protocol-relative URL) a `/\evil.com` (WHATWG normalizace zpětného lomítka na `/` v URL parseru dělá totéž). Tenant-authored `<a href>` v popisu produktu tak může nést odkaz, který na první pohled vypadá jako interní cesta, ale opustí e-shop — phishing/open-redirect vektor v obsahu, který nájemce sám edituje a zákazník mu důvěřuje o to víc, že vypadá "domácí".
- **Kontext nálezu:** objeveno při psaní klientského zrcadla této funkce pro dialog "Vložit odkaz" v `resources/js/Components/Ui/RichTextEditor.vue`. `BlockUrl::isSafe` (homepage bloky, rozhodnutí 2026-07-26) už přesně tenhle guard má (`/` ano, ale `//` a `/\` ne) — `HtmlSanitizer::isSafeUrl` ho nikdy nedostal. Editor je proto vědomě **přísnější** než server: odmítne to, co by server tiše propustil, aby nikdy neřekl "uloženo" u hodnoty, kterou by pak server nechal projít nebezpečnou.
- **Závažnost:** important (ne critical — vyžaduje nájemce, který si sám vloží/nechá vložit škodlivý odkaz do vlastního popisu; skript se nespouští, jde jen o cíl odkazu)
- **Stav:** mitigated (2026-09-02) — guard přestal existovat dvakrát. Nový `app/Core/Html/UrlGuard.php` je jediné místo, které rozhoduje, zda tenantem psaná URL zůstane na hostu nájemce; volají ho `HtmlSanitizer::isSafeUrl` i `BlockUrl::isSafe` a zrcadlí ho klient v `RichTextEditor.vue`. Kromě nahlášeného `//` a `/\` guard nově maže tab, CR a LF **kdekoli** v URL — WHATWG parser je z URL odstraní dřív, než ji vyhodnotí, takže `/<TAB>/evil.com` se načte jako `//evil.com` a původní návrh opravy (jen prefixy) by ho propustil. Backslash je odmítnutý kdekoli v cestě, ne jen na druhé pozici. Testy: `tests/Unit/Html/HtmlSanitizerTest.php` (5 nových), `tests/Unit/Storefront/BlockUrlTest.php` (1 nový).
- **Poznámka k dopadu:** reálně unikal jen tvar `//evil.com`. `DOMDocument` při serializaci enkóduje backslash na `%5C` a tab na `%09`, takže ty dvě varianty tlumil vedlejší efekt serializace, ne guard — na to se spoléhat nedá a explicitní odmítnutí je levnější než ověřovat to při každé změně sanitizeru.
- **Klient byl po opravě mírnější než server** (neznal tab ani backslash uprostřed) — srovnán ve stejné změně. Komentář v `RichTextEditor.vue`, který tvrdil, že editor je *přísnější* než server, přestal platit a byl přepsán.
