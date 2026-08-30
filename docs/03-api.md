# 03 — HTTP API

Plain PHP 8.1+, one front controller, no framework, no runtime Composer dependencies.

## Layout

```
app/                          # OUTSIDE the web root in production
  Http/Router.php             # regex route table -> handler
  Http/Request.php            # method, path, JSON body, query, uploaded files
  Http/Response.php           # json(), error(), noContent()
  Http/Middleware/Auth.php    # session guard
  Domain/Ruleset.php
  Domain/Scoring.php
  Domain/GameState.php
  Repo/Db.php                 # PDO factory (ERRMODE_EXCEPTION, no emulated prepares)
  Repo/PlayerRepo.php
  Repo/RulesetRepo.php
  Repo/GameRepo.php
  Repo/HandRepo.php
  Repo/StatsRepo.php
  Service/AvatarService.php
  bootstrap.php               # autoloader + config + error handler
config/
  config.example.php          # committed
  config.php                  # gitignored: db creds, session name, paths
public_html/
  api/index.php               # front controller: require ../../app/bootstrap.php
  api/.htaccess               # rewrite everything to index.php
bin/
  migrate.php  seed.php  create-user.php  verify.php
tests/
```

Use a PSR-4-ish autoloader hand-rolled in `bootstrap.php` (`App\` → `app/`). Composer is
dev-only, for PHPUnit.

## Conventions

- Base path `/api`. All requests and responses are `application/json; charset=utf-8`,
  except avatar upload (`multipart/form-data`).
- Success: `200`, body `{"ok":true,"data":<payload>}`. Creation returns `201`.
  Deletion returns `200` with `{"ok":true,"data":null}`.
- Failure: appropriate status, body
  `{"ok":false,"error":{"code":"validation_failed","message":"...","fields":{"faan":"Below the 3 faan minimum"}}}`.
  Codes: `unauthenticated` (401), `forbidden` (403), `not_found` (404),
  `validation_failed` (422), `conflict` (409), `server_error` (500).
- Never leak SQL or stack traces. Log them server-side, return a generic `server_error`.
- All ids are integers. All timestamps are ISO-8601 UTC strings.
- Every write endpoint runs inside a transaction.

## Auth

Native PHP sessions, cookie flags `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS.
Session lifetime long (30 days) — this is a living-room app, nobody wants to log in at
the table. Regenerate the session id on login.

**CSRF:** the API only accepts `Content-Type: application/json` on state-changing
requests and rejects any request whose `Origin` header is present and does not match the
configured site origin. Together with `SameSite=Lax` that is sufficient here. Do not build
a token system.

There is no public signup. `bin/create-user.php` creates accounts interactively:

```
php bin/create-user.php --username=ann --display-name="Ann" [--admin]
# prompts for the password twice, with echo off
```

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/auth/login` | — | `{username, password}`. Rate-limit: 5 failures per username per 15 min, then 429. Constant-time compare via `password_verify`. |
| POST | `/api/auth/logout` | ✓ | Destroys the session. |
| GET | `/api/auth/me` | — | `200` with the user, or `401` if not logged in. The SPA calls this on boot. |

Every route below requires authentication. Admin-only routes are marked **A**.

## Players

| Method | Path | Body / notes |
|---|---|---|
| GET | `/api/players` | `?include_inactive=1` to include retired players. |
| POST | `/api/players` | `{name, colour?}` → 201 |
| PATCH | `/api/players/{id}` | `{name?, colour?, is_active?}` |
| POST | `/api/players/{id}/avatar` | `multipart/form-data`, field `avatar`. See below. |
| DELETE | `/api/players/{id}/avatar` | Reverts to the generated default. |
| DELETE | `/api/players/{id}` | Soft delete (`is_active = 0`). **409** if the player is in an `in_progress` game. Never hard-delete — history depends on the row. |

**Avatar upload** (`Service/AvatarService.php`): accept `image/jpeg|png|webp|gif`, max 8 MB.
Verify the real type with `finfo`, never trust the client's `Content-Type` or the filename
extension. Re-encode through GD to a 256×256 centre-cropped WebP (fall back to JPEG if the
GD build lacks WebP), which also strips EXIF and neutralises any polyglot payload. Save as
`{avatar_dir}/{player_id}-{random8}.webp` (path from `config.php`) and delete the previous
file. Script execution under `avatars/` is blocked by a mod_rewrite deny in the site's
single `.htaccess` — **not** by `php_flag`, which this server ignores. See D20b.

**Default avatar:** when `avatar_path` is null the API returns
`"avatar_url": "/avatars/default.svg"` and the frontend overlays the player's initials in
their `colour`. Ship a neutral mahjong-tile `default.svg` in the repo; do not generate
per-player images server-side.

## Rulesets

| Method | Path | Body / notes |
|---|---|---|
| GET | `/api/rulesets` | Each includes its full `points` map. |
| POST | `/api/rulesets` | `{name, table_max_faan, penalty_default, points:{faan:base}}`. `?copy_from={id}` clones an existing one. Rulesets no longer carry a selectable band — that is per game. |
| GET | `/api/rulesets/{id}` | |
| PUT | `/api/rulesets/{id}` | Full replace of the points table. Validate `0 <= table_max_faan <= 30`, a row present for **every** faan 0..`table_max_faan`, all `base_points >= 0`. Shrinking `table_max_faan` deletes the now-orphaned rows. |
| DELETE | `/api/rulesets/{id}` | **409** if it is `is_default` or referenced by an `in_progress` game. Completed games are unaffected — they carry their own snapshot. |

## Games

### `POST /api/games`

```json
{ "ruleset_id": 1, "name": "Sunday night",
  "player_count": 4,
  "min_faan": 2, "max_faan": 8,
  "seats": [ { "wind": 0, "player_id": 12 },
             { "wind": 3, "player_id": 7 } ] }
```

- `player_count` — 2, 3 or 4. Defaults to 4.
- `min_faan` / `max_faan` — the selectable band for **this game**. Default `2` and `8`.
  Must satisfy `0 <= min_faan <= max_faan <= ruleset.table_max_faan`.
- `seats` — one entry per player, each naming the **wind that player starts at**
  (`0`=East, `1`=South, `2`=West, `3`=North). Length must equal `player_count`, winds must
  be distinct, and **wind `0` (East) must be present** — the opening dealer sits there.
  Order is irrelevant. The example above is a two-player game at East and North.

Player ids must be distinct and active. Copies the ruleset into `ruleset_snapshot`. **409** if another
game is already `in_progress` (the app supports one live game at a time — simplifies the
UI and matches how they actually play). → 201 with the full game state.

### `GET /api/games/{id}` — the payload the scoreboard renders

One request, everything the main screen needs.

```json
{ "ok": true, "data": {
  "game": { "id": 41, "name": "Sunday night", "status": "in_progress",
            "player_count": 4, "min_faan": 2, "max_faan": 8,
            "started_at": "2026-08-30T18:04:00Z", "ended_at": null },
  "ruleset": { "name": "House rules", "table_max_faan": 13,
               "penalty_default": 128, "points": { "3": 8, "4": 16 } },
  "seats": [
    { "chair": 0, "player": { "id": 12, "name": "Ann", "colour": "#b91c1c",
                              "avatar_url": "/avatars/12-9f3a2b71.webp" },
      "current_wind_index": 2, "current_wind": "West",
      "total": 144, "rank": 1 }
  ],
  "state": { "round_wind": 0, "round_name": "East", "dealer_wind_index": 2,
             "dealer_player_id": 3, "deal_in_round": 3, "next_hand_number": 7,
             "is_complete": false },
  "hands": [
    { "id": 88, "hand_number": 6, "round_wind": 0, "dealer_wind_index": 1,
      "outcome": "win", "winner_player_id": 12, "faan": 5, "win_type": "discard",
      "discarder_player_id": 7, "liable_player_id": null, "base_points": 16,
      "offender_player_id": null, "penalty_per_player": null, "note": null,
      "scores": { "12": 64, "7": -32, "3": -16, "19": -16 },
      "created_at": "2026-08-30T19:12:00Z" }
  ]
} }
```

`seats` has `player_count` entries. `chair` is fixed for the game and decides where the
player is drawn on the diamond; `current_wind_index` is `(chair - dealer_wind_index + 4) % 4`
and changes every time the deal passes. `seats[].total` and `rank` come from the replay. Rank is 1-based, dense, ties share a rank.
`hands` is ordered **descending** by `hand_number` so the history panel renders newest-first
without client-side sorting.

### `POST /api/games/{id}/hands`

The only interesting write. Three body shapes discriminated by `outcome`:

```json
{ "outcome": "win", "winner_player_id": 12, "faan": 5,
  "win_type": "discard", "discarder_player_id": 7,
  "liable_player_id": null, "note": null }

{ "outcome": "draw", "note": null }

{ "outcome": "penalty", "offender_player_id": 7,
  "penalty_per_player": 128, "note": "false win" }
```

Server-side, in one transaction:

1. `SELECT ... FOR UPDATE` the game; reject unless `status = 'in_progress'`.
2. Replay to get the authoritative `round_wind`, `dealer_wind_index`, `hand_number`.
   **Never trust client-supplied state.**
3. Validate against rules 3–8 in `01-data-model.md` and V1–V7 in `02-scoring-engine.md`.
4. Resolve `base_points` from the **snapshot**, compute deltas via `Scoring`.
5. Assert the deltas sum to zero.
6. Insert `hands` + `player_count` `hand_scores` rows.
7. Apply the transition; if COMPLETE, set `status='completed'`, `ended_at=NOW()`.
8. Commit and return the same payload shape as `GET /api/games/{id}` (→ 201).

Returning the whole state means the frontend never patches its own store — it replaces it.

### Remaining game routes

| Method | Path | Notes |
|---|---|---|
| GET | `/api/games` | `?status=&from=&to=&player_id=&limit=&offset=`. Also accepts `?player_count=`. Summary rows only — id, name, status, dates, player count, the seated players and their final totals. No hands. |
| GET | `/api/games/current` | The single `in_progress` game, or `404`. The SPA's landing lookup. |
| DELETE | `/api/games/{id}/hands/last` | Undo. **409** if the game has no hands. Reopens a completed game. Returns full state. |
| POST | `/api/games/{id}/end` | `{status: "completed"\|"abandoned"}`. Manual early finish. |
| PATCH | `/api/games/{id}` | `{name}` only. |
| DELETE | `/api/games/{id}` | **A**. Hard delete with cascade. Requires `?confirm=1`. |

## Stats

Read-only, backing `06-history-reports.md`. All accept `?from=&to=&player_ids=`.

| Path | Returns |
|---|---|
| `GET /api/stats/leaderboard` | Per player: net points, games, hands played/won, win rate, self-pick rate, biggest hand, average faan. Accepts `?player_count=` and **defaults to 4** (D25). |
| `GET /api/stats/players/{id}` | The above plus a cumulative points-over-time series and a faan histogram. |
| `GET /api/stats/flow` | 4×N matrix of net points transferred from player A to player B. |
| `GET /api/stats/seats` | Net points and win rate grouped by `wind_index` at time of win — does East really win more? |
| `GET /api/stats/games/{id}/curve` | Per-hand cumulative totals for one game, for the replay chart. |

Compute these in SQL against `hand_scores` and `hands`. They are read-only and cheap;
do not cache.
