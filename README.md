# Mahjong Scoreboard

A private web scoreboard for **offline** Hong Kong mahjong. Two, three or four people play
with real tiles at a table; one person enters each hand's result on a laptop plugged into a TV, and
everyone watches the running score.

It does not simulate tiles or validate hands. A human judges the 番 (faan) count and picks
it from the values the game allows — the picker offers only the faan in the configured
range, so an out-of-range score cannot be recorded.

**Status: built.** All ten core phases plus the Tier 1 and Tier 2 history reports are done
and tested; the project is now in maintenance mode (bug fixes, small features, design
tweaks). Four optional Tier 3 reports remain unbuilt — see [`docs/README.md`](docs/README.md).

---

## What it does

- **Live scoreboard** — a seating diamond showing each player's current wind, standings by
  net points, a scrolling hand history, and a one-line entry bar. Designed for a 1920×1080
  TV, readable across a room.
- **Automatic round tracking** — the dealer keeps the deal when the dealer wins, on a
  washout, or on a penalty, and otherwise the deal rotates counterclockwise, skipping empty
  chairs. Four rounds make a game whatever the player count, so 16 hands at four players,
  12 at three, 8 at two. No buttons to press; it is derived from who won.
- **2, 3 or 4 players** — chosen per game, along with **which seats** they take. East is
  always the opening dealer; everyone else can sit at any of South, West or North.
- **Hong Kong scoring** — a fully editable 番 → base points table on the ruleset, plus a
  per-game selectable range (the table may run 0–13 while a given game allows only 2–8),
  with standard HK payments
  (discarder pays double; self-pick pays all), 包 liability, 黃莊 draws, and penalties.
- **Reusable players** with uploaded avatars.
- **Long-term history** — past games, leaderboards, per-player stats, and reports.
- **Bilingual** — English, 中文 (Hong Kong Cantonese), or both.

## Stack

| | |
|---|---|
| Backend | Plain PHP 8.1+ (server runs 8.3), PDO, MariaDB. No framework, no runtime dependencies. |
| Frontend | TypeScript + Preact + `@preact/signals`, bundled with Vite, managed with Bun. |
| Auth | Individual accounts, hashed passwords, native PHP sessions. No public signup. |
| Deploy | `rsync` over SSH to cPanel shared hosting. Nothing is built on the server. |

Chosen over Laravel because the target is shared hosting with no Composer step and a
one-command rsync deploy.

## Commands

```bash
bun install            # install frontend deps
bun run dev            # Vite dev server, proxies /api to the local PHP server
bun run serve:api      # php -S localhost:8080 -t public_html public_html/router.php
bun run build          # production build into dist/
composer test          # PHPUnit - the scoring engine tests must stay green

./deploy/deploy.sh     # build + rsync code (backs up, smoke-tests, rolls back on failure)
./deploy/migrate.sh    # schema changes only - deliberate and separate
./deploy/backup.sh     # database dump + avatar pull
```

## Layout

```
package.json    the ONE manifest - Bun scripts for both halves of the project
app/            PHP source: Http/, Domain/, Repo/, Service/
bin/            migrate, seed, create-user, verify, dbdump
migrations/     NNN_description.sql, schema only - seed data lives in bin/seed.php
config/         config.example.php (committed); config.php (never)
frontend/src/   TypeScript SPA (source only - no package.json of its own)
frontend/public/ static assets copied verbatim into dist/, e.g. default.svg
public_html/    local docroot only - api/index.php, router.php, avatars/
deploy/         deploy.sh, migrate.sh, backup.sh, remote/ (the one .htaccess + api stub)
docs/           current docs - start with docs/README.md
docs-initial-build/ frozen pre-build specs (design history, not maintained)
scratchpad/     local working notes (gitignored)
```

## Documentation

Read [`docs/README.md`](docs/README.md) first — current status, the maintenance workflow,
and what (little) is left to build. [`docs/DECISIONS.md`](docs/DECISIONS.md) is the living
decisions log. The original pre-build specs (data model, API contract, scoring engine,
frontend design, deployment, terminology, the phase-by-phase plan) are frozen in
[`docs-initial-build/`](docs-initial-build/) — useful history, not kept in sync with the
code.

[`CLAUDE.md`](CLAUDE.md) holds the conventions any agent working in this repo must follow.

## Conventions worth knowing up front

- **It is spelled `faan`, never `fan`** — in code, columns, API fields, and UI copy.
- **It is spelled `color`, never `colour`** — column, API field, CSS, and prose.
- **Hands are append-only.** Scores and round state are always derived by replaying them;
  undo deletes the last hand and recomputes.
- **Rulesets are snapshotted onto a game.** Editing a ruleset never rewrites history.
- **Payment math and the dealer rotation carry the whole product.** They are pure PHP with
  no database, tested against the vectors in `docs-initial-build/02-scoring-engine.md`,
  before anything is wired up.

## Setup

Local development needs PHP 8.1+, MariaDB or MySQL, and Bun.

```bash
cp config/config.example.php config/config.php   # point at a local database
php bin/migrate.php && php bin/seed.php
php bin/create-user.php --username=you --display-name="You" --admin
bun install && bun run dev
```

First-time server setup is in [`docs-initial-build/05-deployment.md`](docs-initial-build/05-deployment.md).

## Licence

Private project. Not for distribution.
