# Mahjong Scoreboard

A private web scoreboard for **offline** Hong Kong mahjong. Two, three or four people play
with real tiles at a table; one person enters each hand's result on a laptop plugged into a TV, and
everyone watches the running score.

It does not simulate tiles or validate hands. A human judges the 番 (faan) count and picks
it from the values the game allows — the picker offers only the faan in the configured
range, so an out-of-range score cannot be recorded.

**Status: specified, not yet built.** Everything in `docs/` is written. Phase 0 of
`docs/PLAN.md` is the next step.

---

## What it does

- **Live scoreboard** — a seating diamond showing each player's current wind, standings by
  net points, a scrolling hand history, and a one-line entry bar. Designed for a 1920×1080
  TV, readable across a room.
- **Automatic round tracking** — the dealer keeps the deal on a win or a washout and
  otherwise rotates counterclockwise. Four rounds make a game whatever the player count, so
  16 hands at four players, 12 at three, 8 at two. No buttons to press; it is derived from
  who won.
- **2, 3 or 4 players** — chosen per game, along with **which seats** they take. East is
  always the opening dealer; everyone else can sit at any of South, West or North.
- **Hong Kong scoring** — a fully editable 番 → points table on the ruleset, plus a
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
bun run serve:api      # php -S localhost:8080 -t public_html
bun run build          # production build into dist/
composer test          # PHPUnit - the scoring engine tests must stay green

./deploy/deploy.sh     # build + rsync code (backs up, smoke-tests, rolls back on failure)
./deploy/migrate.sh    # schema changes only - deliberate and separate
./deploy/backup.sh     # database dump + avatar pull
```

## Layout

```
app/            PHP source: Http/, Domain/, Repo/, Service/
bin/            migrate, seed, create-user, verify, dbdump
migrations/     NNN_description.sql, applied in filename order
config/         config.example.php (committed); config.php (never)
frontend/src/   TypeScript SPA
public_html/    local docroot - api/index.php, avatars/
deploy/         deploy.sh, migrate.sh, backup.sh, remote/
docs/           the specs - start with PLAN.md
docs/reference/ source material: the HK rules PDF, the layout sketch
scratchpad/     local working notes (gitignored)
```

## Documentation

Read [`docs/PLAN.md`](docs/PLAN.md) first — ten phases, each with what to read, what to
build, and a "done when" test.

| Spec | Covers |
|---|---|
| [`00-overview.md`](docs/00-overview.md) | Scope, glossary, decisions log |
| [`01-data-model.md`](docs/01-data-model.md) | MariaDB schema and integrity rules |
| [`02-scoring-engine.md`](docs/02-scoring-engine.md) | Payment math, round state machine, test vectors |
| [`03-api.md`](docs/03-api.md) | JSON API contract |
| [`04-frontend.md`](docs/04-frontend.md) | Screens, scoreboard layout, colour scheme |
| [`05-deployment.md`](docs/05-deployment.md) | Server details, `.htaccess`, rsync scripts |
| [`06-history-reports.md`](docs/06-history-reports.md) | Reporting features |
| [`07-terminology.md`](docs/07-terminology.md) | Bilingual term list |

[`CLAUDE.md`](CLAUDE.md) holds the conventions any agent working in this repo must follow.

## Conventions worth knowing up front

- **It is spelled `faan`, never `fan`** — in code, columns, API fields, and UI copy.
- **Hands are append-only.** Scores and round state are always derived by replaying them;
  undo deletes the last hand and recomputes.
- **Rulesets are snapshotted onto a game.** Editing a ruleset never rewrites history.
- **Payment math and the dealer rotation carry the whole product.** They are pure PHP with
  no database, tested against the vectors in `02-scoring-engine.md`, before anything is
  wired up.

## Setup

Local development needs PHP 8.1+, MariaDB or MySQL, and Bun.

```bash
cp config/config.example.php config/config.php   # point at a local database
php bin/migrate.php && php bin/seed.php
php bin/create-user.php --username=you --admin
bun install && bun run dev
```

First-time server setup is in [`docs/05-deployment.md`](docs/05-deployment.md).

## Licence

Private project. Not for distribution.
