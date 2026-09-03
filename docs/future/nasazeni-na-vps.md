# Nasazení na VPS — zjištěné podmínky a otevřené rozhodnutí

**Datum:** 2026-09-03
**Kontext:** brainstorming vlny nasazení. Rozhodnutí **odloženo**, práce se vrací k featurám.
**Stav:** nic se neimplementovalo, prázdná větev `feature/vlna-30-nasazeni` zůstává (číslo 3.0 mezitím
obsadila page cache, vlna se bude muset přečíslovat).

Tenhle dokument existuje proto, aby se příště nezačínalo od nuly. Otázky, které tehdy brainstorming
zastavily, jsou zodpovězené — až na čtyři, na které umí odpovědět jen poskytovatel.

## Co je rozhodnuto

| Věc | Rozhodnutí |
|---|---|
| Server | NETIO **Optimal** — 4 vCPU, 8 GB RAM, 80 GB NVMe. Koupený, prázdný. |
| Prostředí | Produkce **i staging na témže stroji** (`stage.droidshop.cz`). 8 GB to unese. |
| Větvení | `main` je zdroj, nedeployuje se z něj. Merge `main` → `stage` nasadí staging, merge `stage` → `production` nasadí ostrou. Stejný vzor jako `srsne`, `opletal.dev`, `droidcrm`. |
| Přenos | FTP, jako u ostatních projektů vlastníka. Secrets typu `FTP_HOST` dodá vlastník. |
| Migrace | **Vlastník si je pouští sám.** Ke každé vlně se připraví SQL skript, jako u droidcrm. |
| OS | Neověřeno — NETIO nabízí Debian s panelem, nebo čistý Debian 13. Zjistit `cat /etc/os-release`. |

## Co bylo ověřeno v kódu

- **Redis není závislost.** `config/pagecache.php` výslovně říká, že file, database i Redis fungují
  stejně (nikde se nepoužívají tagy), a `.env.example` už dnes jede na `database` pro cache, fronty
  i session. Když Redis na serveru nebude, nic se nepřepisuje.
- **Fronty snesou cron.** V aplikaci je pět jobů — `ExportTenantData`, `ExpireUnpaidOrder`,
  `RunProductImport`, `GenerateDocumentPdf`, `ProbeDomainCertJob` — plus e-maily přes `SendTenantMail`.
  Žádný nepotřebuje sekundovou odezvu, takže `queue:work --stop-when-empty --max-time=55` z cronu po
  minutě stačí. Cena: potvrzovací e-mail objednávky může přijít až o minutu později.
- **Plánovač potřebuje jen `schedule:run`** — `billing:sweep-lifecycle` denně ve 3:00 a
  `domains:sweep-pending` hodinově (`routes/console.php`).

## Co NETIO panel umí

Zdroj: [tarify](https://www.netio.cz/virtualni-servery), [popis panelu](https://www.netio.cz/cs/hosting/virtual).

Apache **nebo** OpenLiteSpeed, PHP a jeho parametry, MySQL, FTP/FTPS účty, **SSH a SFTP účty**, CRON
úlohy, SSL, firewall, zálohy (příplatek), REST API pro automatizaci. Debian s panelem má root „na
vyžádání", čistý Debian 13 má root automaticky.

Stránky **nezmiňují** Redis, trvalé procesy (démon, supervisor) ani wildcard subdomény.

## Skutečný konflikt: vlna 2.1 na panelu neběží

Vlastní domény nájemců stojí na **Caddy on-demand TLS**: Caddy se při prvním HTTPS požadavku na
neznámou doménu zeptá našeho `/internal/tls-check` a teprve pak vydá certifikát. Runbook je
v [`docs/as-is/2026-07-23-custom-domains.md`](../as-is/2026-07-23-custom-domains.md).

NETIO panel jede na Apache/OpenLiteSpeed, on-demand TLS neumí a porty 80/443 drží sám. Aplikační
strana vlny 2.1 je hotová a funguje; chybí jí infrastruktura, kterou panel v téhle podobě nedodá.

**To je jádro odloženého rozhodnutí.** Není to volba mezi release symlinkem, `git pull` a Dockerem —
je to volba mezi panelem a čistým OS, a určuje, jestli hotová funkce zůstane funkční.

## Tři cesty

### A) NETIO panel + Apache + FTP

Přesně zavedený postup vlastníka a to, co je koupené. Panel řeší certifikáty, poštu i zálohy.

Cena: vlastní domény se musí přepsat z on-demand TLS na **provisioning přes REST API panelu**
(doména se ověří → aplikace zavolá NETIO API → panel založí vhost a vydá Let's Encrypt). To je nová
etapa vlny, ne konfigurace. Druhá cena: FTP nahrává `vendor/` po jednotlivých souborech, tedy tisíce
souborů při každém nasazení.

### B) Čistý Debian 13 + Caddy + SSH

Všechno postavené funguje tak, jak bylo navrženo — wildcard `*.droidshop.cz` i on-demand TLS.

Cena: server si spravuje vlastník sám (čistý OS NETIO nemanaguje), FTP by se musel doinstalovat,
a odchyluje se to od toho, jak jsou vedené ostatní projekty.

### C) Panel + SSH účet — *doporučeno v brainstormingu*

Panel nabízí SSH/SFTP účty, tedy neprivilegovaný přístup, ne root. Deploy pak zvládne `composer
install`, `artisan migrate` i rozbalení jednoho archivu místo tisíců FTP souborů; panel dál drží
Apache, certifikáty a poštu. Migrace může vlastník dál pouštět ručně ze SQL, když chce.

Vlastní domény mají stejný problém jako u A. Pokud SSH účet v tarifu není, spadne se na A bez ztráty
práce.

## Čtyři otázky na podporu NETIO

Na tyhle neumí odpovědět kód ani dokumentace na webu. NETIO dává **10 dní zdarma** na vyzkoušení.

1. Jde nastavit **wildcard vhost** `*.droidshop.cz` na jeden dokumentový kořen, nebo se každý nájemce
   klikne ručně? Bez wildcardu padá cíl „funkční e-shop do 10 minut od registrace".
2. Umí **REST API** přidat doménu a vydat na ni certifikát? To je náhrada za Caddy a podmínka cesty A i C.
3. Je k dispozici **SSH účet** (neprivilegovaný) a smí spouštět `php` z příkazové řádky?
4. Je **Redis**? Když ne, jede se na database driverech — nic se nepřepisuje.

## Co ještě čeká na server, až se rozhodne

- **Page cache etapa 2** — statický soubor servírovaný web serverem (dnes middleware ušetří render
  a DB dotazy, ale bootování Laravelu ~30–60 ms zůstává). Na Apache/OLS přes rewrite pravidlo.
  Nese s sebou nerozhodnutou otázku CSRF u staticky servírovaných stránek.
- **Caddy on-demand TLS + ask endpoint**, nebo jeho náhrada — viz výše.
- **`edge.droidshop.cz` A záznam** na IP serveru (cíl CNAME custom domén nájemců).
- **Wildcard DNS + TLS** `*.droidshop.cz`.
- **Cron `schedule:run`** a cronem řízený `queue:work`.
- **Produkční `.env`** — `PLATFORM_SERVER_IP`, `PLATFORM_EDGE_HOST`, silný `PLATFORM_TLS_CHECK_TOKEN`,
  `BILLING_COMPANY_*`, reálné klíče Stripe / Comgate / Packeta / GA4.
- **Staging nesmí sáhnout na ostrá data** — nesmí poslat e-mail reálnému zákazníkovi ani mluvit na
  živý Stripe, Comgate a Packeta. Vlastní DB, vlastní prefix, mailer do logu nebo do zachytávače.
- **Zálohy** jsou u NETIO příplatek, ne standard.
