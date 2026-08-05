# E2E testy (Playwright)

Sada existuje kvůli tomu, co PHPUnit ověřit **nemůže**: cokoli, co potřebuje běžící JavaScript.

| Scénář | Co hlídá |
|---|---|
| `consent.spec.ts` | měřicí skripty mlčí, dokud návštěvník nesouhlasí (vlna 3.3) |
| `checkout-no-js.spec.ts` | celý nákup projde s vypnutým JS (AK §16.3) |
| `checkout.spec.ts` | běžný nákup včetně variant a mini-košíku |
| `accessibility.spec.ts` | axe nad klíčovými stránkami |

## Jednorázová příprava

```
npm install
npx playwright install chromium
```

`/etc/hosts` — **stejný záznam, jaký už potřebuje demo**, nic navíc:

```
127.0.0.1 droidshop obchod.droidshop admin.droidshop
```

## Spuštění

```
npm run e2e         # celá sada
npm run e2e:ui      # interaktivní režim
```

Sada si **sama** spustí `php artisan serve` na portu **8001** a před během provede `migrate:fresh` + `DemoShopSeeder`.

**Ve vlastní databázi `droidshop_e2e`**, kterou si při prvním běhu založí. Vaše lokální data zůstanou nedotčená — sada, která kvůli svému běhu maže cizí data, je horší než žádná.

Port 8001 je jiný než demo (8000) schválně — sada nesmí záviset na tom, jestli vám běží server, ani ho zabít.

## Proč žádná zvláštní doména

Vlna 3.4 byla naplánovaná na samostatnou `droidshop.test` (RFC 6761), a před tím na obcházení „omezení certifikátu", které v `docs/as-is/STATUS.md` viselo od vlny 1.x. **Neplatilo ani jedno:**

- Certifikát se týká `curl` přes **HTTPS**. Tahle sada mluví prostým HTTP proti `artisan serve`, kde žádné TLS není.
- Chromium prý doménu bez TLD upgraduje na HTTPS nebo pošle do vyhledávače. U explicitní `http://` URL s portem to nedělá — **ověřeno**, sada na `obchod.droidshop` běží.

Sada proto jede na téže doméně jako demo i PHPUnit sada a nepotřebuje žádný nový záznam v `/etc/hosts`. Kdyby to na nějakém stroji přece jen selhalo, je tu `E2E_HOST`.

## Page cache je pro E2E vypnutá

`PAGE_CACHE_ENABLED=false`. Jinak by scénář dostal HTML uložené tím předchozím a první červený test by byl nevysvětlitelný. Cache má vlastních 108 PHPUnit testů — tady se netestuje.

## Pravidla, ať sada neshnije

- **Žádné `waitForTimeout`.** Čeká se jen na stav (`toBeVisible`, `waitForURL`, `waitForResponse`). Pevná prodleva je nejrychlejší cesta k blikající sadě, a sada, která bliká, se začne přeskakovat — a pak se přeskočí i skutečný nález.
- **Žádný skutečný požadavek na cizí doménu.** Scénář souhlasu je odchytává přes `page.route()`; testuje se **pokus** o požadavek, ne odpověď.
- **Každý scénář si zakládá vlastní data.** Testy nesmí záviset na pořadí.
- **Test, o kterém nevíte, že umí spadnout, negarantuje nic.** U scénáře souhlasu to bylo ověřeno dočasným porušením gate — viz plán vlny 3.4, Task 3.
