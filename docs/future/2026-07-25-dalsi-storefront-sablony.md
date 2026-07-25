# Budoucí storefront šablony (po vlně 2.2)

Vlna 2.2 dodá **jednu** výchozí šablonu v čistém/minimalistickém stylu + token systém (CSS proměnné, Tailwind preset). Rozhodnutí 2026-07-25: další dva styly přijdou jako samostatné vlny, každá = **nový preset nad stejným token systémem**, ne přepis šablony.

## Plánované šablony
- **Výrazná / marketingová** — velká typografie, silný hero, barevné plochy, výrazné CTA, gradienty. Cíl: móda, lifestyle, značkové e-shopy s „wow" efektem.
- **Technická / kompaktní** — hustší grid, parametry v tabulce, menší radius, monospace akcenty. Cíl: elektronika, náhradní díly, e-shopy s mnoha SKU (droid tematika).

## Předpoklad (staví se v 2.2)
Token systém a komponenty musí být navržené tak, aby výměna šablony byla volba presetu (barvy, typografie, hustota, radius), ne fork Blade šablon. Nájemce si v budoucnu vybere šablonu + přebarví.

## Otevřené (rozhodnout u příslušné vlny)
- Výběr šablony v adminu „Vzhled" (přepínač) + náhledy.
- Kolik struktury smí šablona měnit (jen tokeny vs. i rozložení sekcí).
- Migrace: nájemce na výchozí šabloně → zachovat při přidání dalších.
