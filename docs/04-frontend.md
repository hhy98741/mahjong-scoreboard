# 04 — Frontend

TypeScript + Preact + `@preact/signals`, bundled by Vite, managed with Bun.

**Design target: a laptop driving a large TV, landscape, 1920×1080.** One person types;
three people read from across the room. Type is large, contrast is high, the entry
controls are the only small things on screen. It must still work on a phone, but never at
the expense of the TV.

## Setup

```
bun create vite frontend --template preact-ts
bun add @preact/signals
```

Allowed dependencies: `preact`, `@preact/signals`. Everything else — routing, fetch
wrapper, charts, the seating diagram — is hand-written. A hash router in ~30 lines is less
trouble than a dependency. Charts and the diamond are inline SVG.

**Routing is hash-based (`#/game/41`), and that is deliberate.** Everything after the `#`
stays in the browser and is never sent to Apache, so deep links work with no server
rewrite rule at all. A path-based router would need a "send unknown paths to index.html"
fallback in `.htaccess` — a file the owner maintains by hand, with a firewall in it. Hash
routing keeps our rules and theirs from interacting. See `05-deployment.md`.

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

Themed on the tiles themselves: bamboo green, character red, dot blue, bone-ivory faces,
with a felt-green table as the ground.

`styles/tokens.css`:

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

**Default player colours** are the four tile colours, assigned in seat order at game
creation and overridable per player in Setup: red `#C1272D`, green `#1B8A4B`,
blue `#1F5FA8`, gold `#B08A2E`. Four players, four suits — it reads instantly.

Dark is the default. Offer a light toggle in the menu bar, persisted to `localStorage`.

## State discipline

`api.ts` returns the whole game state on every write. `store.ts` holds
`currentGame = signal<GameState|null>(null)` and every mutation is
`currentGame.value = await api.recordHand(...)`. **Never** locally patch scores, ranks,
or round state — that is exactly where a scoreboard drifts out of sync with its own math.

## Routes

| Hash | Screen | Notes |
|---|---|---|
| `#/login` | Login | Redirect target when `/api/auth/me` returns 401. |
| `#/` | Home | If a game is in progress, redirect straight to it. Otherwise: New game / History / Setup. |
| `#/new` | New game | Ruleset picker, then assign four players to E/S/W/N. |
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

A square table rotated 45°, so all four seats are visible as the four *sides* of the
diamond. Player names and avatars sit **outside** each side; the wind character sits
**inside**. Above it, the round label (`南圈 South Round · Deal 2 of 4`).

Render as inline SVG, `viewBox="0 0 400 400"`. Do not use CSS `transform: rotate(45deg)` —
you would have to counter-rotate every label.

```
Diamond polygon:  (200,60) (340,200) (200,340) (60,200)

Seat        Side of the diamond    Wind glyph at    Name + avatar at
──────────────────────────────────────────────────────────────────────
seat 0      upper-left             (155,155)        (86,86)
seat 1      lower-left             (155,245)        (86,314)
seat 2      lower-right            (245,245)        (314,314)
seat 3      upper-right            (245,155)        (314,86)
```

**The seat→screen-position order is upper-left → lower-left → lower-right → upper-right.**
That traverses the diamond counterclockwise, which is the direction winds rotate in
mahjong, so the East marker visibly walks around the table the correct way.

Players never move, and the wind characters are **not** fixed seat labels. What changes
each hand is the **wind character shown at each seat**, computed as
`wind_index = (seat_index - dealer_seat_index + 4) % 4`. So 東 walks counterclockwise from
one seat to the next as the deal passes, and every player's displayed wind is their real
wind for the hand about to be played — which is what matters for scoring.

The **opening-dealer marker** (開莊) is a filled dot in `--danger` beside the name of the
player at `seat_index 0` — whoever took the first deal of the game. **It never moves for
the whole game.** It is a fixed reference point, not a live indicator: it shows where each
round began, so you can see at a glance how far the deal has travelled and when the
rotation is about to complete.

The **current dealer** needs no separate marker — they are whoever is showing 東. Give
that seat a brighter fill and the 莊 glyph so it reads instantly.

Together these two are exactly what the owner's sketch shows: Player 1 carries the dot
(they opened the deal) while currently holding 南, because the deal has since passed on.

Fill the diamond with `--tile-green` at low opacity over `--felt`, with a `--gold`
stroke. Ring each avatar in that player's colour.

### Standings (top right) — `Standings.tsx`

**Ranked by net points, highest first** — this is a live game, and net points is the
only ranking that makes sense within one game. Four rows: rank, avatar, name in the
player's colour, and the total as the largest text on screen (72px+,
`font-variant-numeric: tabular-nums`, `--positive` / `--negative`, always with an explicit
`+` or `−`). Ties share a rank and keep seat order.

Animate rank changes with a FLIP transition. It is cheap, and it lands on the exact
moment everyone is looking at the screen.

### Hand history (bottom right) — `HandHistory.tsx`

Directly beneath the standings, `overflow-y: auto` on its own so it scrolls without moving
anything else. Newest first — the API already sorts descending. Each row: hand number,
outcome, winner, faan, win type, the four deltas, and a 包 badge when `liable_player_id`
is set. No pagination; a game is under ~40 hands.

### Entry area (bottom) — `EntryBar.tsx`

Progressive disclosure, so the common case is three clicks:

1. **Winner** — four buttons with avatars, not a `<select>`. Big targets.
2. **番 Faan** — a row of number buttons from `min_faan` to `max_faan`, wrapping at 8.
3. **Win type** — `自摸 Self-pick` / `出銃 Discard`. Choosing discard reveals a
   three-button picker (winner excluded).
4. **包 checkbox** — collapsed by default. When ticked, a player picker appears,
   pre-selected to the discarder if there is one; the winner is excluded.
5. **Record hand** — disabled until valid; clears the form on success.

`黃莊 Draw` records immediately. `罰 Penalty…` opens a modal: offender picker plus a
points-each field pre-filled from `ruleset.penalty_default`.

**Undo** always names what it will remove (`↶ Undo hand 6`) and confirms first.

When `state.is_complete`, replace the entry area with a final-standings banner and a
"Start new game" button. Keep Undo available — the last hand is the one most likely to
have been mistyped.

### Keyboard shortcuts

This runs on a laptop; make the operator fast.

| Key | Action |
|---|---|
| `1`–`4` | Select winner by standings position |
| `Q W E R` | Select winner by seat index 0–3 (upper-left, lower-left, lower-right, upper-right — the counterclockwise order the winds travel) |
| digits | Type the faan value |
| `S` | 自摸 Self-pick |
| `D` | 出銃 Discard, then `Q W E R` for the discarder |
| `B` | Toggle 包 |
| `Enter` | Record hand |
| `Esc` | Clear the form |
| `Ctrl/Cmd + Z` | Undo last hand (still confirms) |

Show a `?` overlay listing them. Ignore shortcuts while a text input has focus.

---

## Setup (`#/setup`)

**Players tab.** Grid of cards: avatar, name, colour swatch. Click to edit inline —
rename, recolour, upload/remove avatar, retire. Upload shows a client-side preview and a
progress state; the server does the real cropping.

**Rulesets tab.** List, with the default marked. The editor:

```
Name          [ House rules              ]
Minimum 番    [ 3 ▾ ]     Maximum 番   [ 13 ▾ ]
Penalty default (points each)  [ 128 ]

  番     base points        Winner receives
  ────────────────────────────────────────────────────────────
   0     [   1 ]            出銃 4  /  自摸 6
   1     [   2 ]            出銃 8  /  自摸 12
   ...
   3     [   8 ]            出銃 32 /  自摸 48        ← minimum
   ...
  13     [  64 ]            出銃 256 / 自摸 384       ← maximum

  [ Fill by doubling ]  [ Fill linear: __ per 番 ]  [ Duplicate ruleset ]
```

The live "winner receives" column is what makes this table comprehensible — show it.
Changing max faan adds or removes rows immediately. On save, note that existing games are
unaffected because they hold snapshots.

## Error handling

`api.ts` throws `ApiError` carrying `code`, `message`, `fields`. Field errors bind to the
matching control; anything else raises a toast. A `401` clears the session signal and
routes to `#/login`.

## Accessibility & robustness

- Keyboard-navigable throughout; visible focus rings.
- Colour is never the only signal — totals also carry `+`/`−`, the current dealer carries
  the 莊 glyph as well as a brighter fill, and the opening-dealer dot is paired with 開莊.
- Ship an error boundary. A crash on the scoreboard mid-game is the worst possible
  outcome; it must offer "reload" and never blank the screen.
