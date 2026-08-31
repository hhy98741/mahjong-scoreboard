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
public_html/                  # LOCAL docroot only; production ships deploy/remote/
  api/index.php               # front controller; a verbatim copy of deploy/remote/api/index.php
  router.php                  # dev-server router, see below. Not deployed.
  avatars/
bin/
  migrate.php  seed.php  create-user.php  verify.php  dbdump.php
tests/
```

Use a PSR-4-ish autoloader hand-rolled in `bootstrap.php` (`App\` → `app/`). Composer is
dev-only, for PHPUnit.

**There is no `.htaccess` anywhere under `public_html/`, and none in production outside the
document root's single file.** Routing in production is done by that one file, shipped from
`deploy/remote/.htaccess` (D20). A subdirectory `.htaccess` under `api/` would need
`RewriteEngine On`, and mod_rewrite rules are not inherited — so it would silently bypass
the HTTPS redirect and the 8G firewall for every API request.

### `public_html/router.php` — the local dev server

`php -S` serves paths literally and ignores `.htaccess` entirely, so `/api/health` would
404 without a router script. `bun run serve:api` is therefore:

```bash
php -S localhost:8080 -t public_html public_html/router.php
```

```php
<?php
// Dev only. Production routing is deploy/remote/.htaccess.
// Return false for real files so the built-in server serves them itself.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}
return false;
```

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

## Health

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/health` | **none** | `{"ok":true,"data":{"status":"ok","php":"8.3.33"}}`. Touches no database. |

This is the **only** unauthenticated route besides `/api/auth/login` and `/api/auth/me`.
`Http/Middleware/Auth.php` must exempt it explicitly — `deploy/deploy.sh` smoke-tests it
after every deploy and rolls back the `.htaccess` on a non-200, so guarding it would make
every deploy roll itself back.

## Auth

Native PHP sessions, cookie flags `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS.
Session lifetime long (30 days) — this is a living-room app, nobody wants to log in at
the table. Regenerate the session id on login.

**CSRF:** the API only accepts `Content-Type: application/json` on state-changing requests
and rejects any request whose `Origin` header is present and whose host does not match the
request's own `Host` header. **There is no configured site origin** (D17, D17b): the check is
self-referential, so it works unchanged on localhost, on a staging host, and after a domain
move. Together with `SameSite=Lax` that is sufficient here. Do not build a token system.

**The one exception is `POST /api/players/{id}/avatar`**, which is `multipart/form-data` by
necessity — a file upload cannot be a JSON body. The content-type check must allow
`multipart/form-data` on that route specifically; **the `Origin` check still applies to it,
unchanged, and is what actually protects it.** Note that multipart is a CORS "simple"
content type, so it is exactly the shape a cross-origin form could submit without a
preflight — which is why the origin check is the load-bearing control here and the
content-type rule never was. Do not widen the multipart allowance to any other route.

Why the self-referential form is enough: the attack is a page on `evil.com` firing
`fetch('https://<site>/api/games', {method:'POST'})`. The browser sets **both** headers, and
it sets `Origin: https://evil.com` while setting `Host` to the site it is actually
connecting to. A cross-origin page cannot forge `Host` — so the mismatch is exactly the
signal, and comparing against a configured constant would reject the same request for the
same reason. The one thing a configured origin would additionally catch is a `Host` supplied
by an upstream proxy that trusts the client, which does not apply: Apache serves this vhost
directly with no CDN in front of it.

**`Host` is compared host-only** — strip any `:port`, compare case-insensitively, and treat
a missing `Origin` as a pass (non-browser clients like `curl` and the smoke test send none;
`SameSite=Lax` is what covers browsers that omit it).

⚠ **Vite dev proxy:** leave `changeOrigin` unset (its default is `false`) in
`vite.config.ts`. With it on, the proxy rewrites `Host` to `localhost:8080` while forwarding
`Origin: http://localhost:5173`, and every write in local development fails with a `403`
that looks like an app bug.

There is no public signup. `bin/create-user.php` creates accounts interactively:

```
php bin/create-user.php --username=ann --display-name="Ann" [--admin]
# prompts for the password twice, with echo off
```

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/auth/login` | — | `{username, password}`. Rate-limited, see below. Constant-time compare via `password_verify`. |
| POST | `/api/auth/logout` | ✓ | Destroys the session. |
| GET | `/api/auth/me` | — | `200` with the user, or `401` if not logged in. The SPA calls this on boot. |

**Rate limiting.** State lives in the `login_attempts` table (`01-data-model.md`), not in
the session — the attacker controls their own session. On each attempt, delete rows older
than 15 minutes for that username, count what remains, and return **429** with
`{"code":"rate_limited"}` at 5 or more. Insert a row on failure; delete that username's
rows on success. Key on the username **as typed**, so attempts against an account that does
not exist are throttled identically and the endpoint cannot be used to enumerate usernames.
Prune the whole table opportunistically on login (`DELETE WHERE attempted_at < NOW() - INTERVAL 1 DAY`).

Every route below requires authentication. Admin-only routes are marked **A**.

## Players

| Method | Path | Body / notes |
|---|---|---|
| GET | `/api/players` | `?include_inactive=1` to include retired players. |
| POST | `/api/players` | `{name, color?}` → 201 |
| PATCH | `/api/players/{id}` | `{name?, color?, is_active?}` |
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
`"avatar_url": "/default.svg"` and the frontend overlays the player's initials in their
`color`. Do not generate per-player images server-side.

The file is **not** in `avatars/`. It lives at `frontend/public/default.svg`, so Vite copies
it into `dist/` and the normal deploy lands it at `$DOCROOT/default.svg`, served at
`/default.svg` in dev and production alike. `avatars/` holds user uploads only: it is
excluded from every `--delete`, pulled down as a backup before each deploy, and never
written to by a deploy — a shipped asset placed there would be deleted on the first restore
and is not covered by the mod_rewrite execution deny's intent.

## Rulesets

| Method | Path | Body / notes |
|---|---|---|
| GET | `/api/rulesets` | Each includes its full `points` map. |
| POST | `/api/rulesets` | `{name, table_max_faan, penalty_default, points:{faan:base}}`. `?copy_from={id}` clones an existing one. Rulesets no longer carry a selectable band — that is per game. |
| GET | `/api/rulesets/{id}` | |
| PUT | `/api/rulesets/{id}` | Full replace of the points table. Validate `0 <= table_max_faan <= 13`, a row present for **every** faan 0..`table_max_faan`, all `base_points >= 0`. Shrinking `table_max_faan` deletes the now-orphaned rows. |
| DELETE | `/api/rulesets/{id}` | **409** if it is `is_default` or referenced by an `in_progress` game. Completed games are unaffected — they carry their own snapshot. |

`table_max_faan` is capped at **13** on both `POST` and `PUT` — the ceiling of the seeded
Hong Kong table (D8b). It is not a technical limit; it is the range the points table is
specified over, and `Domain\Scoring::basePoints` clamps to it defensively (P12). Raising it
later means changing this bound, the Setup dropdown, and nothing else.

## Games

### `POST /api/games`

```json
{ "ruleset_id": 1, "name": "Sunday night",
  "player_count": 2,
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
  Order is irrelevant. The example above is a two-player game at East and North, so
  `player_count` is `2` and `seats` has two entries — the two must always agree.

Player ids must be distinct and active. Copies the ruleset into `ruleset_snapshot`. Validate V9 (`player_count` in 2–4, `seats` length equal to it). **409** if another game is
already `in_progress` — integrity rule 14; the app supports one live game at a time, which
simplifies the UI and matches how they actually play. → 201 with the full game state.

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
    { "chair": 0, "player": { "id": 12, "name": "Ann", "color": "#b91c1c",
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

{ "outcome": "win", "winner_player_id": 12, "faan": 5,
  "win_type": "self_pick", "discarder_player_id": null,
  "liable_player_id": 3, "note": "bao - flush" }

{ "outcome": "draw", "note": null }

{ "outcome": "penalty", "offender_player_id": 7,
  "penalty_per_player": 128, "note": "false win" }
```

Server-side, in one transaction:

1. `SELECT ... FOR UPDATE` the game; reject unless `status = 'in_progress'`.
2. Replay to get the authoritative `round_wind`, `dealer_wind_index`, `hand_number`.
   **Never trust client-supplied state.**
3. Validate against rules 3–9 and 16 in `01-data-model.md` and V1–V7, V10–V12 in
   `02-scoring-engine.md`. (V8 belongs to the undo route, V9 to game creation.)

   **`liable_player_id` is asymmetric by win type**, and this is the one place a client can
   get bao wrong:

   | `win_type` | Accepted `liable_player_id` |
   |---|---|
   | `discard` | `null` (no bao), or **exactly `discarder_player_id`**. Any other player → `422` (V11). |
   | `self_pick` | `null` (no bao), or any seated player other than the winner. |

   On a discard win the server may equally derive it from a `bao: true` flag; the frontend
   sends the explicit id so the two shapes stay uniform. Either way the stored column is the
   discarder.
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

Read-only, backing `06-history-reports.md`.

**Every stats endpoint accepts the same four filters:** `?from=`, `?to=`, `?player_ids=`,
and `?player_count=`. `player_count` **defaults to 4** (D25) on all of them, not just the
leaderboard — rate statistics are meaningless when blended across player counts. Pass
`player_count=all` to blend deliberately; endpoints that return a rate must then break the
figure out per count rather than averaging.

They also accept `?include_abandoned=1`; by default games with `status='abandoned'` are
excluded and `in_progress` and `completed` games are included.

| Path | Returns |
|---|---|
| `GET /api/stats/leaderboard` | Per player: net points, games, hands played/won, win rate, self-pick rate, biggest hand, average faan. |
| `GET /api/stats/players/{id}` | The above plus a cumulative points-over-time series and a faan histogram. |
| `GET /api/stats/flow` | `N×N` matrix of net points transferred from player A to player B, over the players in scope. See payload below. |
| `GET /api/stats/seats` | Net points and win rate grouped by the wind actually held when the hand was played, `(chair - dealer_wind_index + 4) % 4` — does East really win more? At `N<4` some winds never occur; return only the winds that do. See payload below. |
| `GET /api/stats/games/{id}/curve` | Per-hand cumulative totals for one game, for the replay chart. |
| `GET /api/stats/records` | A board of superlatives — streaks and records (`06-history-reports.md` #7). Each one links to the game (and, where relevant, the specific hand) it came from. See payload below. |
| `GET /api/stats/feeders` | Per player, as the **discarder**: hands dealt into, points paid as discarder, and discard rate vs. the table average (`06-history-reports.md` #8). See payload below. |
| `GET /api/stats/win-types` | Per player, self-pick vs discard as a share of their wins, plus a table-wide draw rate and 包 (bao) incidents broken out by win type (`06-history-reports.md` #9). See payload below. |

Compute these in SQL against `hand_scores` and `hands`. They are read-only and cheap;
do not cache.

### `GET /api/stats/flow` — the points flow matrix (`06-history-reports.md` #5)

```json
{ "ok": true, "data": {
  "players": [
    { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/avatars/12-9f3a2b71.webp" },
    { "id": 7,  "name": "Ben", "color": "#1d4ed8", "avatar_url": "/default.svg" }
  ],
  "matrix": [
    [0, 96],
    [32, 0]
  ]
} }
```

`players` lists every player who appears on either side of at least one attributed
transfer within scope, ordered by `id` ascending — a player who only drew or sat out
never appears, even if seated in a scoped game. `matrix` is square with the same length
and order as `players`; `matrix[i][j]` is the total net points `players[i]` paid to
`players[j]` across the range (always `>= 0`), and the diagonal is always `0`.

Attribution, per `06-history-reports.md` #5: for each `win` hand, every loser's negative
delta is attributed to the winner (`matrix[loser][winner] += -delta`); for a `penalty`,
the offender's loss is attributed to each recipient (`matrix[offender][recipient] +=
delta`); `draw` hands contribute nothing. This is a strict subset of the deltas
`GET /api/stats/leaderboard` sums — draws are always zero-sum anyway — so for every
player in scope, `SUM(matrix[j][player]) - SUM(matrix[player][j])` (received minus paid,
summed over the other axis) equals that player's `net_points` for the same filters, and
the two figures are asserted to tie out in `tests/StatsRepoTest.php`.

`?player_ids=` restricts to transfers where **both** payer and receiver are in the list;
a transfer with only one side named is dropped rather than attributed to a player outside
the filter — including it would break the reconciliation above for the players who are in
scope. `?player_count` still defaults to `4` (D25): a flow matrix blended across player
counts is exactly the kind of average `06-history-reports.md` § "Player count is a
first-class filter" warns against, so treat `?player_count=all` as a deliberate,
non-default choice here too.

### `GET /api/stats/seats` — seat luck (`06-history-reports.md` #6)

```json
{ "ok": true, "data": [
  { "wind_index": 0, "wind_name": "East",  "hands": 412, "net_points": -1240, "hands_won": 148, "win_rate": 0.3592 },
  { "wind_index": 1, "wind_name": "South", "hands": 401, "net_points": 380,   "hands_won": 96,  "win_rate": 0.2394 },
  { "wind_index": 2, "wind_name": "West",  "hands": 398, "net_points": 512,   "hands_won": 88,  "win_rate": 0.2211 },
  { "wind_index": 3, "wind_name": "North", "hands": 405, "net_points": 348,   "hands_won": 84,  "win_rate": 0.2074 }
] }
```

A flat list, one row per wind actually held, `wind_index` ascending; `wind_name` is the
English name only (`06-history-reports.md` reports return enums, not display strings —
`07-terminology.md` covers translating `wind_index` in the UI, same as everywhere else
`wind_index` appears). Aggregated across every seated player and every hand in scope, not
per player — `?player_ids=` narrows which players' hands count, it does not return one row
per player. `hands` is the number of hands played while holding that wind; `net_points` is
the sum of `points_delta` for those hand/player rows (can be negative); `win_rate` is
`hands_won / hands`, or `null` if `hands` is `0` (never emitted — see below).

`wind_index` is the wind **actually held**, `(chair - dealer_wind_index + 4) % 4`, not the
player's starting chair — it rotates with the deal like everywhere else in this app. At
`player_count < 4` at least one wind never occurs (a 2-player game only ever holds two of
the four), so only winds with `hands > 0` are returned; the frontend must not assume four
rows. The player holding wind_index `0` is always the hand's dealer (East rotates with the
deal, and dealing is what defines East), so that row's `win_rate` **is** the dealer win
rate — no separate field, per `06-history-reports.md` #6's "break out dealer win rate
separately": the UI calls that row out rather than the API duplicating it.

### `GET /api/stats/records` — streaks and records (`06-history-reports.md` #7)

```json
{ "ok": true, "data": {
  "biggest_hand_points": { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" }, "game_id": 42, "hand_id": 501, "points": 384 },
  "biggest_hand_faan":   { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" }, "game_id": 40, "hand_id": 480, "faan": 10 },
  "longest_win_streak":  { "player": { "id": 7,  "name": "Ben", "color": "#1d4ed8", "avatar_url": "/default.svg" }, "game_id": 42, "hand_id": 508, "length": 5 },
  "longest_drought":     { "player": { "id": 9,  "name": "Cal", "color": "#15803d", "avatar_url": "/default.svg" }, "game_id": 37, "hand_id": 190, "length": 14 },
  "biggest_comeback":    { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" }, "game_id": 42, "hand_id": 503, "deficit": 220 },
  "most_dealer_defences":{ "player": { "id": 7,  "name": "Ben", "color": "#1d4ed8", "avatar_url": "/default.svg" }, "game_id": 42, "hand_id": 497, "defences": 4 },
  "best_night":  { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" }, "date": "2026-03-05", "net_points": 640, "game_ids": [40, 41, 42] },
  "worst_night": { "player": { "id": 9,  "name": "Cal", "color": "#15803d", "avatar_url": "/default.svg" }, "date": "2026-02-14", "net_points": -410, "game_ids": [38] }
} }
```

Eight fixed keys, always present; any single one is `null` when scope has no qualifying
data (e.g. no game in range ever had a lead change, so `biggest_comeback` is `null` even
though other keys are populated). Every non-null entry carries `player` (the record
holder) and `game_id`; entries that pin down one specific hand also carry `hand_id` — the
frontend links to `#/history/game/{game_id}`, the same target `leaderboard.best_hand`
already uses, since hand history renders on that page (there is no standalone per-hand
route). `?player_ids=` narrows the players considered throughout, same as `/seats`: a game
with only one of its seated players in scope can still set `biggest_hand_points` etc., but
never `biggest_comeback` (a comeback needs an opponent to be behind).

- **`biggest_hand_points` / `biggest_hand_faan`** — the single `outcome='win'` hand with
  the largest winner's `points_delta`, and separately the largest `faan`, each with its own
  tie-break (earliest `hand_id` first).
- **`longest_win_streak` / `longest_drought`** — scoped to **one game each**; a streak or
  drought never spans two games, because the deal and the hand sequence both reset at
  `hand_number = 1`. Symmetric definition: a hand a player wins zeroes their drought and
  extends their streak; any other hand in that game (someone else's win, a draw, or a
  penalty) zeroes their streak and extends their drought. `length` is hands; `hand_id` is
  the run's last hand.
- **`most_dealer_defences`** — the longest run of consecutive hands within one game sharing
  the same `dealer_wind_index`, regardless of outcome (a draw or a penalty keeps the same
  dealer per `02-scoring-engine.md`). `defences` is the run length minus one — the hand that
  first won the deal is not itself a defence — and `hand_id` is the run's last hand.
- **`biggest_comeback`** — per game, take its real winner(s) by final net points (ties count
  individually), then walk the same per-hand running totals `GET /api/stats/games/{id}/curve`
  exposes and find the largest gap between the eventual winner and whichever opponent was
  ahead at that moment. `deficit` is that gap in points; `hand_id` is the hand after which
  it peaked. The single largest gap across every game in scope wins.
- **`best_night` / `worst_night`** — group every game in scope by the calendar date of
  `games.started_at`, sum `points_delta` per player across that date's games, and report the
  single highest and lowest player/date totals. `game_ids` lists every game that date the
  player was seated in, oldest first — there is no separate route per game-night (that is
  Tier 3 #10), so this is the whole night's context in one array.

### `GET /api/stats/feeders` — feeder stats (`06-history-reports.md` #8)

```json
{ "ok": true, "data": {
  "table_avg_discard_rate": 0.0823,
  "players": [
    { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" },
      "hands": 412, "discards": 34, "points_paid": 512,
      "discard_rate": 0.0825, "vs_table_avg": 0.0002 },
    { "player": { "id": 7,  "name": "Ben", "color": "#1d4ed8", "avatar_url": "/default.svg" },
      "hands": 405, "discards": 41, "points_paid": 688,
      "discard_rate": 0.1012, "vs_table_avg": 0.0189 }
] } }
```

The complement of the win stats (`06-history-reports.md` #8): for each player, **as the
discarder** — how often they dealt into someone else's win, and how many points that cost
them, versus the table's overall rate. One row per player who was seated on at least one
hand in scope. `?player_ids=` narrows the population itself — same convention as `/seats`,
different from `/records`: a player left out of the filter contributes no hands and no
discards to anyone's numbers, including `table_avg_discard_rate`, rather than still
counting in the background.

- **`hands`** — hands the player was seated for in scope (the same denominator
  `leaderboard`'s `hands` column uses).
- **`discards`** — hands in scope where this player is `hands.discarder_player_id` on an
  `outcome='win'` `win_type='discard'` hand. A bao discard (`liable_player_id =
  discarder_player_id`, rule 16) still counts as one dealt-into hand here — bao changes who
  pays, not who dealt.
- **`points_paid`** — the sum of this player's own `points_delta` across exactly those
  hands, negated so it reads as a positive amount paid (`>= 0`); on a plain discard win
  that is this player's discarder share, on a bao discard the whole hand.
- **`discard_rate`** — `discards / hands`, or `null` if `hands` is `0`.
- **`vs_table_avg`** — `discard_rate - table_avg_discard_rate`, or `null` if either side is
  `null`. Positive means this player feeds more often than the table as a whole.
- **`table_avg_discard_rate`** (top-level) — `SUM(discards) / SUM(hands)` over every row
  returned, i.e. the same population `?player_ids=` and the other filters have already
  narrowed to. `null` when every returned row has `hands = 0` (an empty range).

Rows are ordered by `discard_rate` descending (`null` last), ties broken by `discards`
descending then `player.id` ascending — the frontend may re-sort by any column, same as the
leaderboard.

### `GET /api/stats/win-types` — win-type split (`06-history-reports.md` #9)

```json
{ "ok": true, "data": {
  "table_draw_rate": 0.0421,
  "players": [
    { "player": { "id": 12, "name": "Ann", "color": "#b91c1c", "avatar_url": "/default.svg" },
      "hands": 412, "wins": 42, "self_pick_wins": 14, "discard_wins": 28,
      "self_pick_win_share": 0.3333,
      "discard_bao": { "liable": 3, "won": 5 },
      "self_pick_bao": { "liable": 2, "won": 1 } },
    { "player": { "id": 7,  "name": "Ben", "color": "#1d4ed8", "avatar_url": "/default.svg" },
      "hands": 405, "wins": 38, "self_pick_wins": 6, "discard_wins": 32,
      "self_pick_win_share": 0.1579,
      "discard_bao": { "liable": 1, "won": 2 },
      "self_pick_bao": { "liable": 0, "won": 0 } }
] } }
```

Self-pick vs discard as a share of each player's wins (`06-history-reports.md` #9), plus a
table-wide draw rate and 包 (bao) incidents. Bao is broken out by the win type it occurred
on rather than blended into one count — a discard bao is always the discarder's own doing
(rule 16: `liable_player_id = discarder_player_id`), while a self-pick bao names a *different*
player who was already on the hook before the hand ended (rule 5b: `liable_player_id !=
winner_player_id`); merging the two would hide the more interesting of the pair. One row per
player who was seated on at least one hand in scope. `?player_ids=` narrows the population
itself, same convention as `/seats` and `/feeders` — a player left out contributes nothing to
anyone's counts, including `table_draw_rate`'s denominator; unlike `/flow`, the two roles in a
bao pairing (who's liable, who won) are narrowed independently, not as a matched pair, since
each is reported as that one player's own count, not a transfer between two named players.

- **`table_draw_rate`** (top-level) — `draws / hands` over every hand in scope (the scope
  filters only — `from`/`to`/`player_count`/`include_abandoned` — never `?player_ids=`: a draw
  belongs to the whole table, not to any subset of players). `null` when scope has zero hands.
- **`hands`** — hands the player was seated for in scope (the same denominator `leaderboard`
  and `feeders` use).
- **`wins`** — `self_pick_wins + discard_wins`.
- **`self_pick_wins` / `discard_wins`** — this player's `outcome='win'` hands, split by
  `win_type`.
- **`self_pick_win_share`** — `self_pick_wins / wins`, or `null` if `wins` is `0`.
- **`discard_bao`** — `{ liable, won }` over hands where `win_type='discard'` and
  `liable_player_id` is set. `liable` counts hands where this player is the (always-liable)
  discarder; `won` counts hands where this player is the winner who benefited.
- **`self_pick_bao`** — `{ liable, won }` over hands where `win_type='self_pick'` and
  `liable_player_id` is set. `liable` counts hands where this player is the named liable
  player; `won` counts hands where this player is the self-pick winner who benefited.

Rows are ordered by `self_pick_win_share` descending (`null` last), ties broken by `wins`
descending then `player.id` ascending — the frontend may re-sort by any column, same as the
leaderboard.

**These eight cover Tier 1 and five Tier 2 reports that need bespoke shapes.** The rest of
`06-history-reports.md` — session summary (#10), head-to-head (#11), and
`GET /api/stats/export.csv` (#13) — has **no endpoint specified yet**, deliberately. They
depend on nothing else, and they are easier to shape once there are real games to look at.
Add them to this table as they are built, keeping the same four filters and the same
`player_count=4` default (D25).
