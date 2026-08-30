# 04 — Frontend

TypeScript + Preact + `@preact/signals`, bundled by Vite, managed with Bun.

**Design target: a laptop driving a large TV, landscape, 1920×1080.** One person types;
the others read from across the room. Type is large, contrast is high, the entry controls
are the only small things on screen. It must still work on a phone, but never at the
expense of the TV.

## Setup

```
bun create vite frontend --template preact-ts
bun add @preact/signals
```

Allowed dependencies: `preact`, `@preact/signals`. Everything else — routing, fetch
wrapper, charts, the seating diagram — is hand-written. A hash router in ~30 lines is less
trouble than a dependency. Charts and the diamond are inline SVG.

**Routing is hash-based (`#/game/41`), and that is deliberate.** Everything after the `#`
stays in the browser and is never sent to Apache, so deep links work with no server rewrite
rule at all. A path-based router would need a "send unknown paths to index.html" fallback in
`.htaccess` — a file that also carries the owner's firewall. Hash routing keeps our rules
and theirs from interacting. See `05-deployment.md`.

`vite.config.ts` sets `base: '/'`, `build.outDir: '../dist'`, and dev-proxies `/api` and
`/avatars` to `http://localhost:8080`.

## Structure

```
frontend/src/
  main.tsx            router mount, session bootstrap
  router.ts           hash router
  api.ts              typed fetch wrapper; unwraps {ok,data}; throws ApiError
  store.ts            signals: session, currentGame, players, rulesets, lang
  types.ts            mirrors the API payloads in 03-api.md
  i18n/terms.ts       see 07-terminology.md
  routes/
    Login.tsx  Home.tsx  NewGame.tsx  Scoreboard.tsx
    Setup.tsx  History.tsx  GameDetail.tsx  PlayerDetail.tsx
  components/
    SeatingDiamond.tsx  Standings.tsx  HandHistory.tsx  EntryBar.tsx
    Avatar.tsx  PlayerPicker.tsx  FaanPicker.tsx  HandRow.tsx
    Confirm.tsx  Toast.tsx  Sparkline.tsx
  styles/
    tokens.css  base.css  scoreboard.css
```

Plain CSS with custom properties in `tokens.css` — no Tailwind, no CSS-in-JS.

## Colour scheme

Themed on the tiles: bamboo green, character red, dot blue, bone-ivory faces, with a
felt-green table as the ground.

```css
:root {
  /* tile palette — the source of every colour in the app */
  --tile-bone:    #F2EAD8;   /* tile face */
  --tile-red:     #C1272D;   /* 萬 characters, red dragon */
  --tile-green:   #1B8A4B;   /* bamboo, green dragon */
  --tile-blue:    #1F5FA8;   /* dots */
  --tile-gold:    #B08A2E;   /* flowers, accents */
  --felt:         #12201B;   /* table felt */

  /* dark theme is the default: a lit TV in a dim room */
  --bg:           #12201B;
  --surface:      #1B2620;
  --surface-2:    #24322B;
  --border:       #33453B;
  --text:         #F2EAD8;
  --text-dim:     #A9B5AC;
  --accent:       #4CC27A;   /* bamboo, brightened for dark */
  --danger:       #E0473F;   /* character red, brightened */
  --info:         #4A90D9;   /* dot blue, brightened */
  --gold:         #D9B44A;
  --positive:     var(--accent);
  --negative:     var(--danger);
}

:root[data-theme="light"] {
  --bg: #F2EAD8; --surface: #FFFFFF; --surface-2: #F7F1E3;
  --border: #D8CBAE; --text: #1A1614; --text-dim: #6B6257;
  --accent: #1B8A4B; --danger: #C1272D; --info: #1F5FA8; --gold: #B08A2E;
}
```

**Default player colours** are the four tile colours, assigned in chair order at game
creation and overridable per player in Setup: red `#C1272D`, green `#1B8A4B`,
blue `#1F5FA8`, gold `#B08A2E`. Four players, four suits — it reads instantly.

Dark is the default. Offer a light toggle in the menu bar, persisted to `localStorage`.

## State discipline

`api.ts` returns the whole game state on every write. `store.ts` holds
`currentGame = signal<GameState|null>(null)` and every mutation is
`currentGame.value = await api.recordHand(...)`. **Never** locally patch scores, ranks, or
round state — that is exactly where a scoreboard drifts out of sync with its own math.

## Routes

| Hash | Screen | Notes |
|---|---|---|
| `#/login` | Login | Redirect target when `/api/auth/me` returns 401. |
| `#/` | Home | If a game is in progress, redirect straight to it. Otherwise: New game / History / Setup. |
| `#/new` | New game | Player count, ruleset, 番 range, seats. |
| `#/game/:id` | **Scoreboard** | The main screen. |
| `#/setup` | Setup | Tabs: Players, Rulesets. |
| `#/history` | History | Game list + reports. |
| `#/history/game/:id` | Game detail | Full hand log + score curve. |
| `#/history/player/:id` | Player detail | Career stats. |

---

## The Scoreboard (`#/game/:id`)

Four regions, per the owner's sketch (`docs/reference/scoreboard-dashboard.jpg`):

```
┌──────────────────────────────────────────────────────────────────────────┐
│ MENU BAR   Mahjong · Sunday night      [History] [Setup] [中/EN] [End]   │
├────────────────────────────────────┬─────────────────────────────────────┤
│  南圈  South Round · Deal 2 of 4   │  STANDINGS            (net points)  │
│  ("of 4" is the player count N -   │                                     │
│   rounds are always four)          │                                     │
│                                    │   1  ANN                    +144   │
│           Player 2                 │   2  CAL                     +32   │
│              ╱ ╲                   │   3  DEE                     −48   │
│            ╱ 東  北 ╲   Player 3   │   4  BEN                    −128   │
│           ╱   TABLE   ╲            ├─────────────────────────────────────┤
│            ╲ 南  西 ╱              │  HAND HISTORY                    ▲  │
│              ╲ ╱                   │   #6  Ann 5番 ◂ 出銃 Ben             │
│    ● Player 1          Player 4    │   #5  黃莊 Draw                     │
│                                    │   #4  Cal 3番 ● 自摸                │
│                                    │   #3  Ben 4番 · 包 Dee pays all     │
│                                    │   #2  罰 Cal pays 128 each          │
│                                    │                                  ▼  │
├────────────────────────────────────┴─────────────────────────────────────┤
│  DATA ENTRY AREA                                                         │
│  Winner [Ann][Ben][Cal][Dee]   番 [3][4][5][6][7]...                     │
│  (•) 自摸 Self-pick  ( ) 出銃 Discard by [ ]   [ ] 包 pays all: [ ]      │
│  [ Record hand ]   [ 黃莊 Draw ]  [ 罰 Penalty… ]      [ ↶ Undo hand 6 ] │
└──────────────────────────────────────────────────────────────────────────┘
```

CSS grid: `grid-template-columns: 1fr 1fr; grid-template-rows: auto 1fr auto;`
Menu bar and entry area span both columns.

### Menu bar
Game name, navigation, the language selector (`07-terminology.md`), the light/dark toggle,
and End game. Kept thin — it is chrome, not content.

### Seating diamond (top left) — `SeatingDiamond.tsx`

A square table rotated 45°, so every chair is one of the four *sides* of the diamond.
Player names and avatars sit **outside** each side; the wind character sits **inside**.
Above it, the round label (`南圈 South Round · Deal 2 of 4`).

Render as inline SVG, `viewBox="0 0 400 400"`. Do not use CSS `transform: rotate(45deg)` —
you would have to counter-rotate every label.

```
Diamond polygon:  (200,60) (340,200) (200,340) (60,200)

Chair       Side of the diamond    Wind glyph at    Name + avatar at
──────────────────────────────────────────────────────────────────────
0 East      upper-left             (155,155)        (86,86)
1 South     lower-left             (155,245)        (86,314)
2 West      lower-right            (245,245)        (314,314)
3 North     upper-right            (245,155)        (314,86)
```

Upper-left → lower-left → lower-right → upper-right traverses the diamond
**counterclockwise**, which is the direction the deal travels.

#### Chairs are fixed; wind characters rotate

This is the subtle part. Read `02-scoring-engine.md` → "All four winds always exist"
before implementing.

- **Positions are chairs.** A chair is named by the wind it *started* at, and a player
  never moves from theirs. The player drawn upper-left is in the East chair for the whole
  game.
- **The glyph shown at a chair is its CURRENT wind**, not its name:
  `currentWind(chair) = (chair - state.dealer_wind_index + 4) % 4`. So the upper-left
  position may well be displaying 北 partway through a game. That is correct, not a bug.
- **All four winds are always shown, including at empty chairs.** With fewer than four
  players, render the unoccupied sides as a dimmed outline with **their current wind glyph
  and no name**. This makes the model visible: the winds keep turning through all four
  positions, and the deal simply skips the empty ones.

Never derive which sides are empty from the player count — read it from `seats[].chair`.
Two players might be at East and North (adjacent) just as easily as East and West
(opposite).

#### Markers

- **Current dealer** — whichever chair is showing 東. Give it a brighter fill and the 莊
  glyph. It moves every time the deal passes.
- **Opening dealer (開莊)** — a filled dot in `--danger` beside the name at the East chair,
  static for the whole game. Since the deal always starts and every round always restarts
  at East, this dot marks where each round begins and ends. It is a fixed reference point,
  not a live indicator.

Together these two are exactly what the owner's sketch shows: Player 1 carries the dot —
they are in the East chair, where the deal began — while currently displaying 南, because
the deal has since passed on and the winds have rotated.

Fill the diamond with `--tile-green` at low opacity over `--felt`, with a `--gold` stroke.
Ring each avatar in that player's colour.

### Standings (top right) — `Standings.tsx`

**Ranked by net points, highest first** — within a single game that is the only ranking
that makes sense, since everyone has played the same hands (D13). One row per player: rank,
avatar, name in the player's colour, and the total as the largest text on screen (72px+,
`font-variant-numeric: tabular-nums`, `--positive` / `--negative`, always with an explicit
`+` or `−`). Ties share a rank and keep chair order.

Animate rank changes with a FLIP transition. It is cheap, and it lands on the exact moment
everyone is looking at the screen.

### Hand history (bottom right) — `HandHistory.tsx`

Directly beneath the standings, `overflow-y: auto` on its own so it scrolls without moving
anything else. Newest first — the API already sorts descending. Each row: hand number,
outcome, winner, faan, win type, and the deltas. Show a 包 badge when `liable_player_id` is
set. No pagination; a game is under ~40 hands.

### Entry area (bottom) — `EntryBar.tsx`

Progressive disclosure, so the common case is three clicks:

1. **Winner** — one button per seated player with avatars, not a `<select>`.
2. **番 Faan** — `FaanPicker.tsx`. Offers **only** the values in the game's band,
   `game.min_faan` through `game.max_faan` inclusive. Nothing outside it is selectable and
   there is no free-text entry, so an out-of-range faan cannot be recorded. With a band of
   2–8 the picker shows exactly 2,3,4,5,6,7,8 — even though the points table still defines
   values for 0, 1, and 9–13.

   Rendered as a row of number buttons wrapping at 8 per row rather than a `<select>`: one
   click instead of two, and every option stays visible from across the room. Swapping it
   for a dropdown is a change to this one component.
3. **Win type** — `自摸 Self-pick` / `出銃 Discard`. Choosing discard reveals a picker of
   the other players (the winner excluded). At two players the discarder is the only
   opponent, so preselect them.
4. **包 checkbox** — collapsed by default. When ticked, a player picker appears,
   pre-selected to the discarder if there is one; the winner is excluded.
5. **Record hand** — disabled until valid; clears the form on success.

`黃莊 Draw` records immediately. `罰 Penalty…` opens a modal: offender picker plus a
points-each field pre-filled from `ruleset.penalty_default`.

**Undo** always names what it will remove (`↶ Undo hand 6`) and confirms first.

When `state.is_complete`, replace the entry area with a final-standings banner and a
"Start new game" button. Keep Undo available — the last hand is the one most likely to have
been mistyped.

### Keyboard shortcuts

This runs on a laptop; make the operator fast.

| Key | Action |
|---|---|
| `1`–`4` | Select winner by standings position (only as many as there are players) |
| `Q W E R` | Select winner by chair: East, South, West, North. Only occupied chairs are bound. |
| digits | Type the faan value |
| `S` | 自摸 Self-pick |
| `D` | 出銃 Discard, then `Q W E R` for the discarder |
| `B` | Toggle 包 |
| `Enter` | Record hand |
| `Esc` | Clear the form |
| `Ctrl/Cmd + Z` | Undo last hand (still confirms) |

Show a `?` overlay listing them. Ignore shortcuts while a text input has focus.

---

## New game (`#/new`)

Every per-game decision lives here, in this order:

```
Players       ( ) 2      ( ) 3      (o) 4

Ruleset       [ House rules  v ]      (supplies the 番 -> points table)
番 range      [ 2 v ]  to  [ 8 v ]    (defaults 2 and 8)

Seats         pick who sits where - 東 East is required
   東 East    [ Ann      v ]   <- opening dealer
   南 South   [ -- empty v ]
   西 West    [ Ben      v ]
   北 North   [ -- empty v ]
                                      ┌──────────┐
                                      │   live   │  diamond preview,
                                      │  preview │  updates as you pick
                                      └──────────┘
                                            [ Start game ]
```

**All four wind rows are always shown**, whatever the player count. Fill exactly
`player_count` of them and leave the rest empty. The wind rows *are* the seat picker; there
is no separate step.

Rules the form enforces:

- **東 East is required** and cannot be emptied. The opening dealer sits there, and the
  round-boundary logic depends on it.
- Exactly `player_count` rows filled; Start stays disabled until then.
- A player may appear in only one row.
- Changing the player count does not clear the form — it only changes how many rows must be
  filled. Reducing it below the number already filled flags the surplus rather than
  silently dropping someone.

**Any combination is allowed** as long as East is one of them: East+North and East+South
are as valid as East+West. Pre-fill the defaults (2 → East + West, 3 → East + South + West,
4 → all) so the common case is one click, but never enforce them.

The form submits winds, never indices — see `POST /api/games` in `03-api.md`.

The live diamond preview matters here; it is the difference between reading a form and
seeing where everyone will sit. Reuse `SeatingDiamond.tsx` in a read-only mode.

---

## Setup (`#/setup`)

**Players tab.** Grid of cards: avatar, name, colour swatch. Click to edit inline — rename,
recolour, upload/remove avatar, retire. Upload shows a client-side preview and a progress
state; the server does the real cropping.

**Rulesets tab.** List, with the default marked. The editor:

```
Name              [ House rules          ]
Table extends to  [ 13 ▾ ]   <- how many rows the points table has
Penalty default (points each)  [ 128 ]

(The selectable 番 range is NOT here - it is chosen per game on #/new.)

  番     base points        Winner receives (4 players)
  ────────────────────────────────────────────────────────────
   0     [   1 ]            出銃 4  /  自摸 6
   1     [   2 ]            出銃 8  /  自摸 12
   ...
   3     [   8 ]            出銃 32 /  自摸 48
   ...
  13     [  64 ]            出銃 256 / 自摸 384

  [ Fill by doubling ]  [ Fill linear: __ per 番 ]  [ Duplicate ruleset ]
```

The live "winner receives" column is what makes this table comprehensible — show it, and
label it with the player count it assumes, since the multiplier changes with `N` (D23).
Changing **Table extends to** adds or removes rows immediately. On save, note that existing
games are unaffected because they hold snapshots.

## Error handling

`api.ts` throws `ApiError` carrying `code`, `message`, `fields`. Field errors bind to the
matching control; anything else raises a toast. A `401` clears the session signal and routes
to `#/login`.

## Accessibility & robustness

- Keyboard-navigable throughout; visible focus rings.
- Colour is never the only signal — totals carry `+`/`−`, the current dealer carries the 莊
  glyph as well as a brighter fill, and the opening-dealer dot is paired with 開莊.
- Ship an error boundary. A crash on the scoreboard mid-game is the worst possible outcome;
  it must offer "reload" and never blank the screen.
