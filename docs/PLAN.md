# Build Plan

Ten phases, each small enough for one focused session and each ending in something
runnable. Do them in order — later phases assume earlier ones.

Before starting any phase, read the specs it lists. Before finishing any phase, verify
its "Done when" list yourself; do not report a phase complete on the strength of the code
looking right.

---

## Phase 0 — Scaffold

**Read:** `CLAUDE.md`, `docs/03-api.md` § Layout, `docs/05-deployment.md` § Local development

Repo skeleton, tooling, and a request that reaches PHP and comes back as JSON.

- `git init`, `.gitignore` (`config/config.php`, `deploy/deploy.conf`, `node_modules/`,
  `dist/`, `vendor/`, `backups/`, `.DS_Store`, `public_html/avatars/*`).
- `package.json` with the Bun scripts from `CLAUDE.md`; `composer.json` with PHPUnit as
  the only (dev) dependency.
- `app/bootstrap.php`: autoloader, config load, JSON error handler that never leaks
  internals.
- `Http/Router.php`, `Request.php`, `Response.php`. One route: `GET /api/health` →
  `{"ok":true,"data":{"status":"ok","php":"8.x"}}`.
- `Repo/Db.php`: PDO factory, `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`,
  `utf8mb4`.
- `config/config.example.php` committed; a real local `config/config.php` created.
- Vite + Preact frontend that renders "Hello" and successfully calls `/api/health`
  through the dev proxy.

**Done when:** `bun run dev` and `bun run serve:api` are both running and the browser
shows the health payload fetched from PHP.

---

## Phase 1 — Schema

**Read:** `docs/01-data-model.md`

- `bin/migrate.php` — applies `migrations/*.sql` in filename order, records them in
  `schema_migrations`, is idempotent, and refuses to run half a file (each migration in
  a transaction where the statements permit it).
- `migrations/001_initial.sql` — the full schema.
- `bin/seed.php` — the "Hong Kong Standard" ruleset and its 14 points rows.
- `bin/verify.php` — stub for now; it will grow the consistency checks from
  `docs/02-scoring-engine.md` § Replay.

**Done when:** a dropped database can be rebuilt from scratch with
`php bin/migrate.php && php bin/seed.php`, twice in a row, with no errors.

---

## Phase 2 — Auth

**Read:** `docs/03-api.md` § Auth

- `bin/create-user.php` with hidden password entry.
- `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.
- `Http/Middleware/Auth.php` guarding every non-auth route.
- Session cookie flags, id regeneration on login, origin check on state-changing requests.
- Login failure rate limiting.

**Done when:** an unauthenticated `curl` to a protected route gets a `401` JSON envelope,
and a login/`me`/logout cycle works with a cookie jar.

---

## Phase 3 — Scoring engine

**Read:** `docs/02-scoring-engine.md` (all of it)

Pure PHP, no database, no HTTP. **This phase is tests-first.**

- `Domain/Ruleset.php`, `Domain/Scoring.php`, `Domain/GameState.php`.
- `tests/ScoringTest.php` and `tests/GameStateTest.php` covering **every** vector in
  § Part 4 — P1–P12, S1–S9, the seat-wind case, and V1–V8.

**Done when:** `composer test` is green and every listed vector has a named test. This is
the one phase where "it looks right" is not acceptable — the numbers are the product.

---

## Phase 4 — Players & rulesets API

**Read:** `docs/03-api.md` §§ Players, Rulesets; `docs/01-data-model.md`

- `PlayerRepo`, `RulesetRepo`, and their routes.
- `Service/AvatarService.php`: `finfo` type check, GD re-encode to 256×256 WebP,
  random filename, old-file cleanup.
- A committed `default.svg`. (Avatar execution blocking lives in the site's single
  `.htaccess`, shipped in Phase 9 — see D20b.)
- Ruleset validation: contiguous faan rows 0..`max_faan`, bounds, delete guards.

**Done when:** the full CRUD works over `curl`, an uploaded JPEG comes back as a square
WebP, and a file renamed `evil.php.jpg` is rejected or safely re-encoded.

---

## Phase 5 — Games API

**Read:** `docs/03-api.md` § Games; `docs/02-scoring-engine.md`

The other high-risk phase. Wire the engine to the database.

- `GameRepo`, `HandRepo`.
- `POST /api/games`, `GET /api/games/{id}`, `GET /api/games/current`, `GET /api/games`.
- `POST /api/games/{id}/hands` — the eight-step transaction in `03-api.md`.
- `DELETE /api/games/{id}/hands/last`, `POST /api/games/{id}/end`, `PATCH`, `DELETE`.
- Integration test: play a scripted 16+ hand game end to end, assert final totals sum to
  zero, assert the game auto-completes, undo the last hand, assert it reopens correctly.

**Done when:** the integration test passes and `php bin/verify.php` (now implemented)
reports no drift between stored hand state and a full replay.

---

## Phase 6 — Scoreboard UI

**Read:** `docs/04-frontend.md` (all of it), `docs/07-terminology.md`

The screen everyone actually looks at. Follows the owner's sketch at
`docs/reference/scoreboard-dashboard.jpg`.

- Router, `api.ts`, `store.ts`, `types.ts`, login screen, session bootstrap.
- `styles/tokens.css` — the tile palette, dark default plus light theme.
- `i18n/terms.ts` and the `t()` helper; language selector in the menu bar.
- `SeatingDiamond.tsx` — inline SVG, exact coordinates in `04-frontend.md`. Winds rotate
  with the deal; the dealer marker rides on 東.
- `Standings.tsx` (net points, descending, FLIP animation on rank change).
- `HandHistory.tsx` beneath it, scrolling independently.
- `EntryBar.tsx`: winner, 番, win type, 包 toggle, draw, penalty modal, undo with
  confirmation.
- Keyboard shortcuts and the `?` overlay.
- Game-complete state with final standings.

**Done when:** a real game can be scored start to finish on a 1920×1080 screen without
touching the API directly; every score on screen matches `bin/verify.php`; and all three
language modes render without the diamond or the faan row wrapping.

---

## Phase 7 — New game & setup UI

**Read:** `docs/04-frontend.md` §§ Routes, Setup

- `#/new`: ruleset picker and seat assignment for E/S/W/N.
- `#/setup` Players tab: cards, inline edit, avatar upload with preview, retire.
- `#/setup` Rulesets tab: the faan table editor with the live payout preview column,
  fill-by-doubling and fill-linear helpers, duplicate.

**Done when:** the owner can go from an empty database to a running game entirely through
the UI.

---

## Phase 8 — History & Tier 1 reports

**Read:** `docs/06-history-reports.md` Tier 1; `docs/03-api.md` § Stats

- `Repo/StatsRepo.php`.
- `#/history` game list with filters, `#/history/game/:id` with the score curve,
  leaderboard, `#/history/player/:id`.
- Leaderboard sorts by **game win %** by default, with points-per-hand alongside and
  `Games` always visible so a thin sample is obvious (decision D13).
- The reconciliation test: net points across any range sum to zero.

**Done when:** past games are browsable and the leaderboard's numbers tie out against a
manual `SUM(points_delta)` query.

---

## Phase 9 — Deploy

**Read:** `docs/05-deployment.md`

The server is confirmed: cPanel/CloudLinux, Apache 2.4.68, MariaDB 10.5.25, PHP 8.3.33
with every required extension present. See `05-deployment.md`.

**Host-specific values never enter the repo** (D22). Copy `deploy/deploy.conf.example` to
`deploy/deploy.conf` and fill in `REMOTE`, `SITE`, `DOCROOT`, `APPDIR` there; every script
and doc refers to those variables.

**Read the `--delete` warning in that spec before writing the sync commands.** The site
directory already contains `.well-known/` (AutoSSL) and `cgi-bin/`; deleting the former
breaks certificate renewal months later, silently.

- `deploy/remote/` — exactly two files: `.htaccess` and `api/index.php`. No subdirectory
  `.htaccess` files (mod_rewrite rules are not inherited; see D20).
- `deploy/deploy.sh` must back up the live `.htaccess`, smoke-test `/` and `/api/health`
  after syncing, and roll the file back on failure. Test that rollback deliberately.
- Ask the owner to paste their 8G blocklist between the markers in `deploy/remote/.htaccess`.
- `deploy/deploy.sh` — **code only**, and it pulls avatars down as a backup first.
- `deploy/migrate.sh` — separate and deliberate; dumps the database before migrating.
- `deploy/backup.sh` — dump plus avatar pull, for scheduled use.
- Remote first-time setup, migrate, seed, create the admin user.
- Work through the post-deploy checklist at the end of `05-deployment.md`.

**Done when:** the site is live, a game has been scored on it from the TV, and a second
deploy has been run without losing data or avatars.

---

## Phase 10 — Tier 2 reports

**Read:** `docs/06-history-reports.md` Tier 2

Money-flow matrix, seat luck, streaks and records, feeder stats, win-type split. Pure
addition; nothing else depends on it. Pick them off in any order once the app has enough
real games in it to be interesting.

---

## Risk notes

- **Phases 3 and 5 carry the whole product.** If the payment math or the dealer rotation
  is wrong, everything downstream is confidently wrong. Over-invest in their tests.
- **The avatar upload is the only untrusted input** that lands on disk. Phase 4's
  re-encode step is not optional.
- **`rsync --delete` and `avatars/`** — see the warning in `05-deployment.md`. This is the
  single most likely way to lose data.
- **Undo is load-bearing.** People mistype at the table constantly. If undo is unreliable
  they will stop trusting the board.
