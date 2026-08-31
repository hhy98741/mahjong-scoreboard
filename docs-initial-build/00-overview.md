# 00 — Overview & Decisions

## What this is

A private web app for keeping score at an in-person Hong Kong mahjong table. Two, three or
four people play with real tiles; one person types the result of each hand into a laptop
that is plugged into a TV, and everyone watches the running scoreboard.

It is **not** a mahjong game, a tile engine, or a hand validator. It never sees the tiles.
A human decides the faan count and types it in.

## Glossary

| Term | Meaning |
|---|---|
| **Faan** | The score rank of a winning hand. Higher faan = bigger payout. Typed in by a human. |
| **Hand** | One deal, ending in a win, a draw, or a penalty. The unit of data entry. |
| **Round** | One full pass of the deal around the occupied chairs — `N` deals. Named by wind: East, South, West, North round. |
| **Game** | Four rounds (East → South → West → North), whatever the player count. Minimum `4N` hands: 16 at four players, 12 at three, 8 at two. |
| **Seat** | A fixed physical chair at one of the four compass points, assigned at game start: East, South, West, North in **counterclockwise** order. Players never move seats, and with fewer than four players some chairs stay empty. |
| **Dealer** | The player currently holding East wind. The deal rotates counterclockwise between hands, skipping empty chairs. |
| **Self-pick** | Winner drew the winning tile themselves. All `N-1` losers pay. |
| **Discard** | Winner took another player's discarded tile. The discarder pays double. |
| **Bao (包)** | House liability rule: one player is deemed responsible for the whole hand and pays the entire table's obligation alone. On a discard win that is always the discarder; on a self-pick it is named explicitly (D7b). |
| **Draw / washout** (黃莊) | Wall exhausted with no winner. No points move; the dealer stays — which is what 黃莊 literally names. |

## Core flows

1. **Setup (rare).** Create player records with names and avatar photos. Create/edit a
   ruleset — the editable faan → base points table, and the penalty default.
2. **New game.** Pick a player count (2, 3 or 4), a ruleset, the selectable faan band, and
   which chair each player takes (East is always occupied — the opening dealer sits there).
3. **Play (the main screen).** After each real-world hand, enter the result. The board
   updates scores, ranks players, advances the dealer and round, and appends to a
   scrollable hand history. An undo button removes the last hand.
4. **End game.** Either automatic (North round completes) or a manual "End game" button.
5. **History.** Browse past games, per-player stats, and reports (see `06-history-reports.md`).

## Decisions log

These were decided with the owner. Do not change them without asking.

| # | Decision | Rationale |
|---|---|---|
| D1 | **Payment rule: Standard HK.** Discard → discarder pays 2 units, every other non-winner pays 1 (winner `+N·B`). Self-pick → every loser pays 2 (winner `+2(N-1)·B`). Shown here at `N=4`: +4 and +6. Generalised in D23. | Matches how they play. Stored as `payment_rule = 'hk_standard'` for future extension, but only this strategy is implemented. |
| D2 | **The points table is fully user-editable**, one row per faan value from 0 to `table_max_faan`. Seeded with the Hong Kong banded doubling table. | House values differ from the reference PDF. |
| D3 | **Spelling is `faan`**, everywhere. | Cantonese phonetics. |
| D4 | **Plain PHP JSON API + Bun/Vite SPA.** No Laravel. | Shared hosting; rsync deploy with no server-side build or Composer install. |
| D5 | **Individual user accounts** with hashed passwords and PHP sessions. Accounts are created by an admin; there is no public signup. | The site is on the open internet. |
| D6 | **Single-device usage.** One laptop drives a TV. No polling, no realtime sync. | Explicitly confirmed by the owner. |
| D7 | **Supported outcomes:** win, draw/washout, penalty. Plus a **bao** liability flag on wins. | Dealer double-payment was declined; do not implement it. |
| D7b | **On a 出銃 discard win, the bao liable player is always the discarder — no other player may be named, and the attempt is rejected (V11).** On a 自摸 self-pick win there is no discarder, so the liable player **must** be picked explicitly. | Owner's call: a discard win with a third party held liable does not happen at this table, so allowing it only creates a way to mis-enter. Self-pick bao does happen periodically and has to be recordable. |
| D8 | **The faan entry control offers only valid values** — no typing, no free text. The selectable band is `min_faan..max_faan`, enforced in the UI *and* server-side. | A human judges the faan; the app makes an out-of-range value unrecordable. |
| D8b | **The points table and the selectable band are separate settings, and live in different places.** `table_max_faan` is a **ruleset** field setting how far the points table goes (0–13). `min_faan`/`max_faan` are **game** fields set on the New Game screen, defaulting to **2 and 8**. Narrowing the band never deletes points rows. | The points table rarely changes; the band varies night to night, so it belongs with the other per-game choices. |
| D9 | **Avatars are uploaded images**, with a generated default avatar when none is set. | |
| D10 | **Points only.** No currency conversion, no settle-up in dollars. | |
| D11 | **Hands are append-only; all state is derived.** Undo = delete last hand + recompute. | Makes correctness trivial and undo safe. |
| D12 | **Scoreboard layout follows the owner's sketch** (`docs/reference/scoreboard-dashboard.jpg`): menu bar across the top, a 45°-rotated seating diamond top-left, net-points standings top-right, scrolling hand history beneath the standings, data entry across the bottom. | Drawn by the owner. |
| D12b | **The sketch is superseded by the specs wherever they differ.** It fixes the four regions and nothing else. Chair-to-position mapping, the wind glyphs, and the two markers are defined in `04-frontend.md` § Seating diamond; where the sketch disagrees (it draws the marker beside the lower-left position and labels it "Dealer marker"), the spec wins. | The sketch was a first pass; the model was worked out afterwards. |
| D13 | **Within a game, rank by net points.** Across games, rank by rate — game win %, with points-per-hand alongside. | Same hands played, so within a game the raw total *is* the result. Across games a raw total just rewards attendance. |
| D14 | **Winds rotate on the diamond**, showing each player's real wind for the coming hand. Players never move. The current dealer is simply whoever shows 東. | Matches the physical table; your wind changes as the deal passes and it matters for scoring. |
| D14b | **The red dot is the *opening-dealer* marker (開莊)**, fixed on the East chair for the entire game — not a live dealer indicator. The *current* dealer is simply whoever is showing 東. | Every round starts and restarts at East, so the dot marks where each round begins and ends. |
| D14c | **東 is the only current-dealer marker.** No 莊 badge. The dealer's chair is shown by drawing 東 bolder, larger and in `--gold`. | Owner's call. East *is* the dealer by definition, so a badge could only ever land on the chair already showing 東 — two markers for one fact, both to read and to keep in sync. |
| D15 | **Bilingual terms are hardcoded** in `frontend/src/i18n/terms.ts`, not stored in the database. Language mode (`en`/`zh`/`both`) is a `localStorage` display preference. | Product copy with a closed set, not user data. See `07-terminology.md` for the reasoning and the full term list. |
| D16 | **Color scheme is drawn from the tiles**: bamboo green, character red, dot blue, bone-ivory faces, felt-green ground. Dark theme by default. | Owner's request; also the right choice for a TV in a dim room. |
| D17 | **No domain name in any config the application reads.** All frontend URLs are relative; the CSRF origin check compares `Origin` against the request's own `Host`. | Raised by the owner — correct, and it removes a config value. |
| D17b | **`SITE` in `deploy.conf` does not change D17.** It was reconsidered once the smoke test introduced a real domain into the project. `deploy.conf` is bash sourced by `deploy.sh` *on the developer's laptop*; the server-side application never reads it, so using it would mean copying the value into `config/config.php` — a second place to keep in sync that fails closed with a confusing 403 when it drifts. The self-referential check is also what makes the app work unchanged on localhost, on a staging host, and after a domain move. | The new fact is deploy-time, not runtime. |
| D18 | **Schema migrations are a separate command** (`deploy/migrate.sh`), never bundled into `deploy/deploy.sh`. Avatars are pulled down as a backup *before* every deploy, in addition to being excluded from `--delete`. | Code deploys are routine and reversible; migrations are neither. |
| D19 | **One folder per site in each tree**: `~/sites/<site-name>` (docroot) and `~/apps/<site-name>` (PHP source, outside the docroot), sharing the same folder name. | Owner's convention — everything for a site is findable by name, and future apps on other domains stay separate. The shared name also lets the API front controller locate the app without a hardcoded path. |
| D20 | **One `.htaccess`, version-controlled at `deploy/remote/.htaccess`** and shipped by the normal deploy. No subdirectory `.htaccess` files at all. Order inside it: HTTPS redirect → dotfile and avatar denies → 8G firewall → app routing → headers. | The owner does not hand-edit it on the server, so version control wins. Subdirectory files are avoided because mod_rewrite rules are not inherited — an `api/.htaccess` with `RewriteEngine On` would bypass the firewall and HTTPS redirect for every API request. |
| D20b | **Avatar script execution is blocked by a mod_rewrite deny, not `php_flag engine off`.** | `php_flag` is a mod_php directive; on CloudLinux with PHP-FPM/LSAPI it is typically ignored, so it would have looked like protection while doing nothing. |
| D20c | **`deploy.sh` backs up the live `.htaccess`, smoke-tests `/` and `/api/health`, and rolls the file back automatically on failure.** | An `.htaccess` syntax error 500s the entire site and cannot be validated ahead of time. |
| D21 | **Hash-based routing** (`#/game/41`). | Deep links need no server rewrite, keeping the app out of the owner's `.htaccess` entirely. |
| D22 | **The repository is public.** No real domain, username, server path, or credential appears in any tracked file. Host-specific values live in the gitignored `deploy/deploy.conf` and `config/config.php`; docs use `$DOCROOT`, `$APPDIR`, `$SITE`, `$REMOTE`, or `example.com`. | It is committed to a public GitHub repo. |
| D23 | **2, 3 or 4 players supported.** Payment shares are unchanged; only the number of payers varies. Winner receives `N·B` on a discard and `2(N-1)·B` on a self-pick. At `N=2` those are both `2B` — an accepted identity, not a bug. | They sometimes play short-handed. |
| D23b | **Which chairs are occupied is chosen per game** on the New Game screen, not derived from `N`. East is always occupied (the opening dealer); the others may take any of South, West, North — so two players can sit East+North or East+South, not only East+West. Defaults are pre-filled (2 → E+W, 3 → E+S+W) but never enforced. | Owner asked not to be locked into fixed seats. |
| D23c | **All four winds always exist; only the deal skips empty chairs.** A player's wind is always `(chair − dealer_wind_index + 4) % 4` — mod 4, never mod `N`. Empty chairs still absorb a wind each hand, so at two players sitting East+South the East-chair player alternates East ↔ **North**, never South. The scoreboard shows the current wind at empty chairs too, dimmed. | Owner's correction. It is how a real table works: the winds are a rigid rotation of the compass, not a relabelling among whoever showed up. |
| D24 | **Rounds are always four**, whatever `N` is. Deals per round is `N`, so a game is `4N` hands minimum: 16 / 12 / 8. At `N<4` a round wind can be one nobody sits at (a North round with three players); that is accepted. | Owner's choice — keeps a game a familiar length. |
| D25 | **Player count is a reporting filter, defaulting to 4.** Rate statistics are not comparable across player counts. Every stats endpoint accepts `?player_count=`. | A 2-player win rate is structurally ~1/2; a 4-player one ~1/4. |
| D26 | **Default player colors are assigned at player creation**, cycling the four tile colors (red, green, blue, gold) by creation order, and are overridable in Setup. Color is a property of the person, not of a chair. | It is stored on `players`, so it must be stable across games; assigning at game creation would have meant one player changing color night to night. |
| D27 | **Spelling is `color`, not `colour`** — in the `players.color` column, the API field, the CSS, and prose. | Owner's preference; matches CSS, which has no British spelling. |
| D28 | **One `package.json`, at the repository root.** `frontend/` holds source and `vite.config.ts` only — no second manifest, no second lockfile. Vite's root is `frontend/`, so `build.outDir: '../dist'` resolves to repo-root `dist/`. | `deploy.sh` runs `bun install` and `bun run build` from the repo root, and `serve:api` is a PHP command that does not belong to the frontend package. A nested manifest would also make `outDir: '../dist'` ambiguous — resolved against the wrong root it writes *outside* the repository, the build reports success, and the deploy ships a stale bundle. |

### Explicitly out of scope

- Dealer pays/receives double (declined in D7).
- Continuation/streak bonuses for a repeating dealer (連莊).
- Tile-level input or automatic faan calculation.
- Multi-device live sync, spectator links, push notifications.
- Real-money settlement.
- Public registration or password reset by email.

## Reference material

`docs/reference/hongkong-mahjong-rules.pdf` — the ALEA Hong Kong rules sheet. Useful pages:

- **p.7** — dealer/round rotation: *"If East (the Dealer) wins he stays as East. In case of
  a Dead Hand, the wind/seating position stays in place. Otherwise the player to the right
  becomes the new dealer as the wind/seating position rotates counterclockwise."*
  This is the state machine in `02-scoring-engine.md`.
- **p.7–8** — the faan value list (what earns faan). Informational only; the app does not
  compute faan.
- **p.10** — the payment table. Source of the seeded default points table and of D1.
  Note the PDF has an arithmetic typo in the self-drawn column for 2 faan (prints `32`,
  should be `24` = 8+8+8). Use the rule, not the printed totals.
