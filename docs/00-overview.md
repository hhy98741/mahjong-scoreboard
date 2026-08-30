# 00 — Overview & Decisions

## What this is

A private web app for keeping score at an in-person Hong Kong mahjong table. Four people
play with real tiles; one person types the result of each hand into a laptop that is
plugged into a TV, and everyone watches the running scoreboard.

It is **not** a mahjong game, a tile engine, or a hand validator. It never sees the tiles.
A human decides the faan count and types it in.

## Glossary

| Term | Meaning |
|---|---|
| **Faan** | The score rank of a winning hand. Higher faan = bigger payout. Typed in by a human. |
| **Hand** | One deal, ending in a win, a draw, or a penalty. The unit of data entry. |
| **Round** | Four dealer rotations. Named by wind: East, South, West, North round. |
| **Game** | Four rounds (East → South → West → North). Minimum 16 hands. |
| **Seat** | A fixed physical chair, assigned a starting wind at game start: East, South, West, North in **counterclockwise** order. Players never move seats. |
| **Dealer** | The player currently holding East wind. Rotates counterclockwise between hands. |
| **Self-pick** | Winner drew the winning tile themselves. All three losers pay. |
| **Discard** | Winner took another player's discarded tile. The discarder pays double. |
| **Bao (包)** | House liability rule: one player is deemed responsible for the whole hand and pays the entire table's obligation alone. |
| **Draw / washout** (黃莊) | Wall exhausted with no winner. No points move; the dealer stays — which is what 黃莊 literally names. |

## Core flows

1. **Setup (rare).** Create player records with names and avatar photos. Create/edit a
   ruleset — the editable faan → points table, minimum faan, maximum faan.
2. **New game.** Pick a ruleset, pick four players, assign each to a starting seat
   (East/South/West/North).
3. **Play (the main screen).** After each real-world hand, enter the result. The board
   updates scores, ranks players, advances the dealer and round, and appends to a
   scrollable hand history. An undo button removes the last hand.
4. **End game.** Either automatic (North round completes) or a manual "End game" button.
5. **History.** Browse past games, per-player stats, and reports (see `06-history-reports.md`).

## Decisions log

These were decided with the owner. Do not change them without asking.

| # | Decision | Rationale |
|---|---|---|
| D1 | **Payment rule: Standard HK.** Discard → discarder pays 2 units, other two pay 1 each (winner +4). Self-pick → all three pay 2 each (winner +6). | Matches how they play. Stored as `payment_rule = 'hk_standard'` for future extension, but only this strategy is implemented. |
| D2 | **Faan table is fully user-editable**, one row per faan value from 0 to `max_faan`. Seeded with the Hong Kong banded doubling table. | House values differ from the reference PDF. |
| D3 | **Spelling is `faan`**, everywhere. | Cantonese phonetics. |
| D4 | **Plain PHP JSON API + Bun/Vite SPA.** No Laravel. | Shared hosting; rsync deploy with no server-side build or Composer install. |
| D5 | **Individual user accounts** with hashed passwords and PHP sessions. Accounts are created by an admin; there is no public signup. | The site is on the open internet. |
| D6 | **Single-device usage.** One laptop drives a TV. No polling, no realtime sync. | Explicitly confirmed by the owner. |
| D7 | **Supported outcomes:** win, draw/washout, penalty. Plus a **bao** liability flag on wins. | Dealer double-payment was declined; do not implement it. |
| D8 | **Minimum faan to win** is enforced by the entry form, configurable per ruleset. | |
| D9 | **Avatars are uploaded images**, with a generated default avatar when none is set. | |
| D10 | **Points only.** No currency conversion, no settle-up in dollars. | |
| D11 | **Hands are append-only; all state is derived.** Undo = delete last hand + recompute. | Makes correctness trivial and undo safe. |
| D12 | **Scoreboard layout follows the owner's sketch** (`docs/reference/scoreboard-dashboard.jpg`): menu bar across the top, a 45°-rotated seating diamond top-left, net-points standings top-right, scrolling hand history beneath the standings, data entry across the bottom. | Drawn by the owner. |
| D13 | **Within a game, rank by net points.** Across games, rank by rate — game win %, with points-per-hand alongside. | Same hands played, so within a game the raw total *is* the result. Across games a raw total just rewards attendance. |
| D14 | **Winds rotate on the diamond**, showing each player's real wind for the coming hand. Players never move. The current dealer is simply whoever shows 東. | Matches the physical table; your wind changes as the deal passes and it matters for scoring. |
| D14b | **The red dot is the *opening-dealer* marker (開莊)**, fixed on `seat_index 0` for the entire game — not a live dealer indicator. | A fixed reference point showing where each round began and how far the deal has travelled. |
| D15 | **Bilingual terms are hardcoded** in `frontend/src/i18n/terms.ts`, not stored in the database. Language mode (`en`/`zh`/`both`) is a `localStorage` display preference. | Product copy with a closed set, not user data. See `07-terminology.md` for the reasoning and the full term list. |
| D16 | **Colour scheme is drawn from the tiles**: bamboo green, character red, dot blue, bone-ivory faces, felt-green ground. Dark theme by default. | Owner's request; also the right choice for a TV in a dim room. |
| D17 | **No domain name in any config.** All frontend URLs are relative; the CSRF origin check compares `Origin` against the request's own `Host`. | Raised by the owner — correct, and it removes a config value. |
| D19 | **One folder per site in each tree**: `~/sites/<site-name>` (docroot) and `~/apps/<site-name>` (PHP source, outside the docroot), sharing the same folder name. | Owner's convention — everything for a site is findable by name, and future apps on other domains stay separate. The shared name also lets the API front controller locate the app without a hardcoded path. |
| D22 | **The repository is public.** No real domain, username, server path, or credential appears in any tracked file. Host-specific values live in the gitignored `deploy/deploy.conf` and `config/config.php`; docs use `$DOCROOT`, `$APPDIR`, `$SITE`, `$REMOTE`, or `example.com`. | It is committed to a public GitHub repo. |
| D20 | **One `.htaccess`, version-controlled at `deploy/remote/.htaccess`** and shipped by the normal deploy. No subdirectory `.htaccess` files at all. Order inside it: HTTPS redirect → dotfile and avatar denies → 8G firewall → app routing → headers. | The owner does not hand-edit it on the server, so version control wins. Subdirectory files are avoided because mod_rewrite rules are not inherited — an `api/.htaccess` with `RewriteEngine On` would bypass the firewall and HTTPS redirect for every API request. |
| D20b | **Avatar script execution is blocked by a mod_rewrite deny, not `php_flag engine off`.** | `php_flag` is a mod_php directive; on CloudLinux with PHP-FPM/LSAPI it is typically ignored, so it would have looked like protection while doing nothing. |
| D20c | **`deploy.sh` backs up the live `.htaccess`, smoke-tests `/` and `/api/health`, and rolls the file back automatically on failure.** | An `.htaccess` syntax error 500s the entire site and cannot be validated ahead of time. |
| D21 | **Hash-based routing** (`#/game/41`). | Deep links need no server rewrite, keeping the app out of the owner's `.htaccess` entirely. |
| D18 | **Schema migrations are a separate command** (`deploy/migrate.sh`), never bundled into `deploy/deploy.sh`. Avatars are pulled down as a backup *before* every deploy, in addition to being excluded from `--delete`. | Code deploys are routine and reversible; migrations are neither. |

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
