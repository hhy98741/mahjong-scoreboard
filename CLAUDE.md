# Mahjong Scoreboard — Project Instructions

A web scoreboard for **offline** Hong Kong mahjong games played in person. The app records
who won each hand, how many **faan**, and how the points moved. It does not simulate tiles
or gameplay.

## Memory

`.claude/memory/MEMORY.md` (gitignored, local-only) — notes Claude Code keeps across
sessions on this project.

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
  DB columns, API fields, and UI copy. `faan`, `min_faan`, `max_faan`, `table_max_faan`.
- **Spelling: `color`, never `colour`.** `players.color`, the API field, the CSS custom
  properties, and prose. See decision D27.
- **Nothing is hardcoded to four players.** `N` is `games.player_count` (2, 3 or 4) and the
  occupied chairs are chosen per game. Winds are always `% 4`; only the deal skips empty
  chairs. A stray `% 4` where `N` belongs — or `N` where `4` belongs — is the most likely
  bug in this codebase. See D23, D23b, D23c, D24.
- **Hands are an append-only log.** Player scores and round/dealer state are *always*
  derived by replaying `hands` in order. Never mutate a hand in place; undo deletes the
  last hand and recomputes.
- **Rulesets are snapshotted onto a game at creation.** Editing a ruleset must never
  change the scores of a game already played.
- **All point values are integers.** Points only, no decimals, no currency. Nothing in
  this app is denominated in money, and no label should imply otherwise.
- **Rank by net points within a game; by rate across games.** See decision D13.
- **No display strings in the API.** It returns enums (`"self_pick"`, `wind_index: 2`);
  the frontend translates. See `docs/07-terminology.md`.
- **Strict types, PDO with prepared statements only.** No ORM, no framework.
- **No secrets and no host details in the repo — it is public.** Real domains, usernames,
  server paths, and credentials belong in `config/config.php` and `deploy/deploy.conf`,
  both gitignored. The committed `.example` counterparts carry placeholders only. Docs
  refer to `$DOCROOT`, `$APPDIR`, `$SITE`, `$REMOTE` or `example.com`, never real values.
- **One `.htaccess`, at the document root**, version-controlled at `deploy/remote/.htaccess`.
  Never add one in a subdirectory — mod_rewrite rules are not inherited, so it would bypass
  the firewall and the HTTPS redirect. See D20.
- **Seed data lives in `bin/seed.php`, never in a migration.** `migrations/` is schema only.
- **One `package.json`, at the repo root.** `frontend/` holds source and `vite.config.ts`
  only. Vite's root is `frontend/`, so `build.outDir: '../dist'` lands at repo-root `dist/`
  — the path `deploy.sh` ships. A nested manifest breaks the root `bun install` the deploy
  depends on. See D28.

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
bun run serve:api      # php -S localhost:8080 -t public_html public_html/router.php
composer test          # PHPUnit — scoring engine tests must stay green
bun run test:e2e       # Playwright UI regression suite — see § End-to-end regression tests
./deploy/deploy.sh     # rsync build + PHP app to shared hosting (code only)
./deploy/migrate.sh    # schema changes - deliberate and separate from deploy
./deploy/backup.sh     # database dump + avatar pull
```

## Browser verification

When Claude Code needs to visually confirm frontend work (screenshots, checking a UI
change actually renders, clicking through a flow), use **Playwright, run ad hoc via
`bunx playwright`** — launch headless Chromium, drive it with a throwaway script, screenshot,
read the result. Do not use the Claude in Chrome extension for this project: the owner runs
multiple Chrome profiles, only one of which would have the extension, and there is no way to
target a specific profile when Claude Code connects to Chrome.

For a one-off check, keep Playwright ad hoc — install/run it via `bunx playwright ...` rather
than reaching for the devDependency below. `bunx playwright install chromium` only needs to
run once per machine; the browser binary is cached under `~/Library/Caches/ms-playwright`.

`playwright` **is** a real devDependency now (`package.json` / `bun.lock`), added specifically
so `tests/e2e/regression.mjs` resolves — `bunx -p playwright` fails once the script lives
under the repo root, because the repo's own `node_modules` (vite, preact, …) shadows bunx's
temp install during module resolution. Don't remove it as unused; it backs that suite. Keep
using plain `bunx playwright ...` for throwaway one-off scripts outside `tests/e2e/`.

## End-to-end regression tests

`tests/e2e/regression.mjs` is a saved, manually-invoked Playwright suite — not wired into
`composer test` or any CI (there is none for this project). It drives the real frontend —
clicks and the documented keyboard shortcuts (`docs/04-frontend.md` § Keyboard shortcuts),
never the API directly for anything under test — through: Setup player creation, three full
games (N=4, N=3, and N=2 seated at the *non-default* East+South pair), every win type and
both 包 flavours (discard-bao and self-pick-bao), a full 4-round wrap, undo/redo of both a
mid-game hand and the hand that completes a game, and the Tier 1 history reports (Games list,
Game detail, Leaderboard, Player detail), plus a no-crash smoke pass over the Tier 2/3 tabs.

Every assertion diffs the DOM against an independently-fetched `GET /api/games/{id}` (or
stats endpoint) rather than recomputing scores or dealer state itself — that math is already
covered by `composer test` (`GamesIntegrationTest`, `GameStateTest`, `ScoringTest`). This
suite exists to catch UI/API *wiring* regressions (a button not calling the right endpoint, a
report rendering the wrong number, the seating diamond misrendering wind rotation or empty
chairs), not to re-verify the scoring engine.

```bash
bun run test:e2e        # = bun run tests/e2e/regression.mjs
```

Requires, all running locally first:
- `bun run dev` (port 5173) and `bun run serve:api` (port 8080), and the local DB reachable —
  see [[local-db-needs-sandbox-off]] in memory; direct DB/API access needs the Bash sandbox
  disabled.
- One login account with `is_admin=1` — cleanup hard-deletes the games it creates via the
  admin-only `DELETE /api/games/{id}?confirm=1`:
  ```bash
  php bin/create-user.php --username=e2e --display-name="E2E Runner" --admin
  ```
  The script defaults to username `e2e`; override with the `MJSB_E2E_USER` / `MJSB_E2E_PASS`
  env vars if you used a different account, or `MJSB_E2E_BASE` for a non-default Vite URL.
  Credentials for the account already created for this are in local memory (gitignored), not
  here — this file is public.

The suite creates its own uniquely-named players (`E2E <name> <run-id>`) each run and cleans
up after itself (deletes its games, retires its players) — safe to rerun repeatedly. If a
prior run crashed mid-script, it can leave one `in_progress` or `abandoned` game and some
active `E2E …` players behind; an `in_progress` game blocks the next run's `POST /api/games`
with a 409, so delete it first (`DELETE FROM games WHERE status='in_progress'` locally, or
`DELETE /api/games/{id}?confirm=1` as the admin account).

## Primary display target

A **laptop connected to a large TV**, in landscape. One person enters hands on that
laptop; everyone reads the TV. Design for large type and generous spacing at 1920x1080
first; make it usable on a phone, but do not optimise for phone. There is **no** multi-device
sync requirement — no polling, no websockets.
