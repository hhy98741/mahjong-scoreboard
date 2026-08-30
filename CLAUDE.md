# Mahjong Scoreboard — Project Instructions

A web scoreboard for **offline** Hong Kong mahjong games played in person. The app records
who won each hand, how many **faan**, and how the points moved. It does not simulate tiles
or gameplay.

## Read this first

Specs live in `docs/`. Build order and the definition of done for each step is in
[docs/PLAN.md](docs/PLAN.md). Do not start coding a phase before reading its listed specs.

| Spec | Covers |
|---|---|
| `docs/00-overview.md` | Product scope, glossary, decisions log |
| `docs/01-data-model.md` | MariaDB schema, migrations, seed data |
| `docs/02-scoring-engine.md` | Payment math + round/dealer state machine + test vectors |
| `docs/03-api.md` | PHP JSON API contract |
| `docs/04-frontend.md` | SPA structure and screen designs |
| `docs/05-deployment.md` | Build + rsync deploy to shared hosting |
| `docs/06-history-reports.md` | Long-term reporting features |
| `docs/07-terminology.md` | Bilingual (English / 中文) term list and language modes |

## Non-negotiable conventions

- **Spelling: `faan`, never `fan`.** Cantonese romanisation, used in code identifiers,
  DB columns, API fields, and UI copy. `faan`, `min_faan`, `max_faan`, `points_per_faan`.
- **Hands are an append-only log.** Player scores and round/dealer state are *always*
  derived by replaying `hands` in order. Never mutate a hand in place; undo deletes the
  last hand and recomputes.
- **Rulesets are snapshotted onto a game at creation.** Editing a ruleset must never
  change the scores of a game already played.
- **All money-like values are integers.** Points only, no decimals, no currency.
- **Rank by net points within a game; by rate across games.** See decision D13.
- **No display strings in the API.** It returns enums (`"self_pick"`, `wind_index: 2`);
  the frontend translates. See `docs/07-terminology.md`.
- **Strict types, PDO with prepared statements only.** No ORM, no framework.
- **No secrets and no host details in the repo — it is public.** Real domains, usernames,
  server paths, and credentials belong in `config/config.php` and `deploy/deploy.conf`,
  both gitignored. The committed `.example` counterparts carry placeholders only. Docs
  refer to `$DOCROOT`, `$APPDIR`, `$SITE`, `$REMOTE` or `example.com`, never real values.

## Stack

- Backend: plain PHP, PDO/MariaDB, native sessions. Server runs **PHP 8.3**; code targets
  8.1+ so it stays runnable locally. No Composer dependencies required
  at runtime (Composer is dev-only, for PHPUnit).
- Frontend: TypeScript + Preact + `@preact/signals`, bundled with Vite. **Bun** is the
  package manager and task runner (`bun install`, `bun run build`).
- Deploy: `rsync` over SSH. No build tooling runs on the server. Document root is
  `~/sites/<site-name>`; PHP source lives outside it at `~/apps/<site-name>`.
  The site's single `.htaccess` is version-controlled at `deploy/remote/.htaccess`.

## Commands

```bash
bun install            # install frontend deps
bun run dev            # Vite dev server (proxies /api to the local PHP server)
bun run build          # production build into dist/
bun run serve:api      # php -S localhost:8080 -t public_html (local API)
composer test          # PHPUnit — scoring engine tests must stay green
./deploy/deploy.sh     # rsync build + PHP app to shared hosting
```

## Primary display target

A **laptop connected to a large TV**, in landscape. One person enters hands on that
laptop; everyone reads the TV. Design for large type and generous spacing at 1920x1080
first; make it usable on a phone, but do not optimise for phone. There is **no** multi-device
sync requirement — no polling, no websockets.
