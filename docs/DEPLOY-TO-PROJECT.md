# Nasazení šablony do Laravel projektu

Tento repozitář (`claude-laravel-vue`) je **balík konfigurace**, ne hotová aplikace. Zkopíruješ ho do kořene cílového Laravel + Vue projektu.

## Nový projekt

1. Vytvoř Laravel aplikaci (installer nebo starter):

   ```bash
   # Příklad — vlastní výběr starteru
   composer create-project laravel/laravel my-app
   # nebo
   git clone https://github.com/gdarko/laravel-vue-starter.git my-app
   ```

2. Zkopíruj soubory z tohoto balíku (viz níže).

3. Postupuj podle [`SETUP.md`](SETUP.md).

4. V Claude Code spusť **`/init`**.

## Existující projekt

1. **Záloha** — commit nebo větev před merge.

2. Zkopíruj soubory; u konfliktů **slouč** ručně:
   - existující `CLAUDE.md` → přidej sekce z našeho `CLAUDE.md`,
   - existující `.claude/` → nesmaž vlastní rules/agents, doplň chybějící.

3. Vyplň [`PROJECT-PROFILE.md`](PROJECT-PROFILE.md) (`/init` nebo ručně).

4. Založ prázdné složky dokumentace, pokud chybí:

   ```bash
   mkdir -p docs/superpowers/{specs,plans,errors} docs/as-is
   ```

## Co kopírovat

Z kořene **tohoto** repozitáře do kořene **cílového** projektu:

```
CLAUDE.md
.claude/
docs/
```

**Nekopíruj** z tohoto repa: `README.md` (nebo přejmenuj na `docs/CLAUDE-TEMPLATE-README.md`), `.git/` — cílový projekt má vlastní git.

### rsync (doporučeno)

```bash
TEMPLATE=/cesta/k/claude-laravel-vue
TARGET=/cesta/k/muj-laravel-projekt

rsync -av --ignore-existing \
  "$TEMPLATE/CLAUDE.md" \
  "$TEMPLATE/.claude/" \
  "$TEMPLATE/docs/" \
  "$TARGET/"
```

`--ignore-existing` nepřepíše tvé úpravy. Pro vynucení přepsání použij bez `--ignore-existing` (opatrně).

### Ruční kopie

```bash
cp CLAUDE.md /target/
cp -R .claude /target/
cp -R docs /target/   # slouč s existujícím docs/ pokud je
```

## Po nasazení — checklist

- [ ] `docs/PROJECT-PROFILE.md` vyplněný (`/init`)
- [ ] `composer install` && `npm install`
- [ ] `.env` a Sanctum (u SPA)
- [ ] Claude Code spuštěný z **kořene** projektu
- [ ] První spec pro aktivní feature: `docs/superpowers/specs/YYYY-MM-DD-nazev.md`

## Aktualizace šablony

Při nové verzi balíku `claude-laravel-vue`:

1. Porovnej diff `.claude/rules/` a `CLAUDE.md`.
2. Ručně slouč změny — nepřepisuj projektové spec/as-is.
3. Zapiš do `docs/as-is/` pokud měníš týmové konvence.

## Odkazy na startery

| Starter | Použití |
|---------|---------|
| [laravel-vue-starter](https://github.com/gdarko/laravel-vue-starter) | SPA, DaisyUI, Pinia |
| [claude-vue-starter-kit](https://github.com/laravel-agent-kits/claude-vue-starter-kit) | Inertia, agenti, Pest |

Tento balík je **nezávislý** na konkrétním starteru — profil projektu určí režim.
