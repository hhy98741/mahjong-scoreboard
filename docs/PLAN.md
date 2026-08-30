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

- `git init` and `.gitignore` are **already done** — the repo exists and the ignore file is
  committed. Start at `package.json`.
- `package.json` with the Bun scripts from `CLAUDE.md`; `composer.json` with PHPUnit as
  the only (dev) dependency.
- `app/bootstrap.php`: autoloader, config load, JSON error handler that never leaks
  internals.
- `Http/Router.php`, `Request.php`, `Response.php`. One route: `GET /api/health` →
  `{"ok":true,"data":{"status":"ok","php":"8.x"}}`. It touches no database, and it is
  **exempt from auth** for the whole life of the project — `deploy.sh` smoke-tests it.
- `public_html/router.php` — the four-line dev-server router from `03-api.md` § Layout.
  Without it `php -S` serves paths literally and `/api/health` 404s. There is **no**
  `.htaccess` under `public_html/`.
- `Repo/Db.php`: PDO factory, `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`,
  `utf8mb4`.
- `config/config.example.php` committed; a real local `config/config.php` created.
- Vite + Preact frontend that renders "Hello" and successfully calls `/api/health`
  through the dev proxy.

**Done when:** `bun run dev` and `bun run serve:api` are both running and the browser
shows the health payload fetched from PHP. Also verify `curl -s localhost:8080/api/health`
returns the JSON directly — that proves `router.php` works, not just the Vite proxy.

---

## Phase 1 — Schema

**Read:** `docs/01-data-model.md`

- `bin/migrate.php` — applies `migrations/*.sql` in filename order, records them in
  `schema_migrations`, is idempotent, and refuses to run half a file (each migration in
  a transaction where the statements permit it).
- `migrations/001_initial.sql` — the full schema, **including `login_attempts`**. Schema
  only: no seed data in any migration, ever.
- `bin/seed.php` — the "Hong Kong Standard" ruleset and its 14 points rows. Idempotent:
  it inserts only if no ruleset of that name exists and never overwrites the owner's edits.
- `bin/verify.php` — stub for now; it will grow the consistency checks from
  `docs/02-scoring-engine.md` § Replay.

**Done when:** a dropped database can be rebuilt from scratch with
`php bin/migrate.php && php bin/seed.php`, twice in a row, with no errors — and the second
`seed.php` leaves a hand-edited `base_points` value untouched.

---

## Phase 2 — Auth

**Read:** `docs/03-api.md` § Auth

- `bin/create-user.php --username= --display-name= [--admin]`, hidden password entry.
- `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.
- `Http/Middleware/Auth.php` guarding every route except `/api/health`, `/api/auth/login`
  and `/api/auth/me`.
- Session cookie flags, id regeneration on login, and the self-referential origin check on
  state-changing requests: `Origin` host vs the request's own `Host`, port stripped, absent
  `Origin` allowed. No configured origin (D17b).
- Login failure rate limiting against the `login_attempts` table — 5 failures per username
  per 15 minutes, keyed on the username **as typed** so a bad username throttles the same
  way a bad password does.

**Done when:** a write through the Vite dev proxy succeeds — if it 403s, `changeOrigin` is
set on the proxy and must be removed; an unauthenticated `curl` to a protected route gets a
`401` JSON envelope;
`/api/health` still returns `200` with no cookie; a login/`me`/logout cycle works with a
cookie jar; and six wrong passwords return `429` while a correct login on a different
username in the same window still succeeds.

---

## Phase 3 — Scoring engine

**Read:** `docs/02-scoring-engine.md` (all of it)

Pure PHP, no database, no HTTP. **This phase is tests-first.**

- `Domain/Ruleset.php`, `Domain/Scoring.php`, `Domain/GameState.php`.
- `tests/ScoringTest.php` and `tests/GameStateTest.php` covering **every** vector in
  § Part 4 — P1–P22 (all three player counts; **P4 is retired, do not reinstate it** — the
  case it covered is now rejection V11), W1–W12 including W4b (wind rotation with empty
  chairs), S1–S15, I1–I4, V1–V12. W3 and W4 are the two-player cases the owner
  confirmed and must pass exactly: at East+South the East-chair player alternates East ↔
  North, at East+West they alternate East ↔ West.
- The engine must be parameterised on `N` from the start. Retrofitting a player count into
  code that assumes four seats is the kind of change that leaves one `% 4` behind.

**Done when:** `composer test` is green and every listed vector has a named test. This is
the one phase where "it looks right" is not acceptable — the numbers are the product.

---

## Phase 4 — Players & rulesets API

**Read:** `docs/03-api.md` §§ Players, Rulesets; `docs/01-data-model.md`

- `PlayerRepo`, `RulesetRepo`, and their routes.
- `Service/AvatarService.php`: `finfo` type check, GD re-encode to 256×256 WebP,
  random filename, old-file cleanup.
- `frontend/public/default.svg` — a neutral mahjong tile, served at `/default.svg`. It goes
  in `frontend/public/` so the build copies it into `dist/`; it must **not** live in
  `avatars/`, which holds uploads only. (Avatar execution blocking lives in the site's
  single `.htaccess`, shipped in Phase 9 — see D20b.)
- Default player colors assigned at creation, cycling the four tile colors (D26).
- Ruleset validation: contiguous faan rows 0..`max_faan`, bounds, delete guards.

**Done when:** the full CRUD works over `curl`, an uploaded JPEG comes back as a square
WebP, a file renamed `evil.php.jpg` is rejected or safely re-encoded, and a player with no
avatar returns `"avatar_url": "/default.svg"` that actually resolves.

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
  **Run the same script at `N=3` and `N=2`**, with a non-default seat pair at `N=2`
  (East+South), so a hardcoded `4` cannot pass.
- `bin/verify.php` implemented: replay every game and check integrity rules 1–15, including
  rule 14 (at most one `in_progress` game) and rule 3 (every player id named on a hand is
  seated in that game).

**Done when:** the integration test passes at all three player counts, a second
`POST /api/games` while one is live returns `409`, and `php bin/verify.php` reports no
drift between stored hand state and a full replay.

---

## Phase 6 — Scoreboard UI

**Read:** `docs/04-frontend.md` (all of it), `docs/07-terminology.md`

The screen everyone actually looks at. Follows the owner's sketch at
`docs/reference/scoreboard-dashboard.jpg`.

- Router, `api.ts`, `store.ts`, `types.ts`, login screen, session bootstrap.
- `styles/tokens.css` — the tile palette, dark default plus light theme.
- `i18n/terms.ts` and the `t()` helper; language selector in the menu bar.
- `SeatingDiamond.tsx` — inline SVG, exact coordinates in `04-frontend.md`. Winds rotate
  with the deal; the current dealer is shown by drawing 東 heavier, with no separate 莊
  badge (D14c). Empty chairs render dimmed, with their rotating wind and no name.
- `Standings.tsx` (net points, descending, FLIP animation on rank change).
- `HandHistory.tsx` beneath it, scrolling independently.
- `EntryBar.tsx`: winner, 番, win type, 包 toggle, draw, penalty modal, undo with
  confirmation.
- Keyboard shortcuts and the `?` overlay — three chair-ordered rows: `Q W E R` winner,
  `A S D F` discarder (`G` = self-pick), `Z X C V` 包 liable player. `B` toggles 包, digits
  pick faan. Only occupied chairs bind, and `Z X C V` are live only on a self-pick with 包 on.
- 包 in `EntryBar.tsx` is asymmetric by win type (D7b): a bare toggle on a discard win,
  a required picker on a self-pick. Record stays disabled until a self-pick bao names one.
- Game-complete state with final standings.

**Done when:** a real game can be scored start to finish on a 1920×1080 screen without
touching the API directly; every score on screen matches `bin/verify.php`; all three
language modes render without the diamond or the faan row wrapping; a two-player game at
East+South shows the two empty chairs dimmed with their rotating winds; and a full discard
hand can be entered with the keyboard alone in three keystrokes plus Enter.

---

## Phase 7 — New game & setup UI

**Read:** `docs/04-frontend.md` §§ Routes, Setup

- `#/new`: ruleset picker and seat assignment for E/S/W/N.
- `#/setup` Players tab: cards, inline edit, avatar upload with preview, retire.
- `#/setup` Rulesets tab: the faan table editor with the live payout preview column,
  fill-by-doubling and fill-linear helpers, duplicate.

**Done when:** the owner can go from an empty database to a running game entirely through
the UI, including a two-player game at East+North — a seat pair no default pre-fills.

---

## Phase 8 — History & Tier 1 reports

**Read:** `docs/06-history-reports.md` Tier 1; `docs/03-api.md` § Stats

- `Repo/StatsRepo.php`.
- `#/history` game list with filters, `#/history/game/:id` with the score curve,
  leaderboard, `#/history/player/:id`.
- Leaderboard sorts by **game win %** by default, with points-per-hand alongside and
  `Games` always visible so a thin sample is obvious (decision D13).
- The reconciliation test: net points across any range sum to zero.

**Done when:** past games are browsable, the leaderboard's numbers tie out against a manual
`SUM(points_delta)` query, and switching `player_count` visibly changes the rate columns
while net points and games played stay reconcilable.

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
- `deploy/backup.sh` — dump plus avatar pull with retention pruning, for scheduled use.
  Written out in full in `05-deployment.md`.
- Remote first-time setup, migrate, seed, create the admin user.
- Work through the post-deploy checklist at the end of `05-deployment.md`.

**Done when:** the whole post-deploy checklist in `05-deployment.md` passes with the
firewall on, the site is live, a game has been scored on it from the TV, and a second
deploy has been run without losing data, avatars, or `config/config.php`.

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
