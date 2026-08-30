# 02 — Scoring Engine & Round State Machine

This is the heart of the app and the only part with real logic. Build it as **pure PHP
with no database and no HTTP**, in `app/Domain/`, and cover it with PHPUnit before wiring
it to anything. Every test vector below must pass.

Files:
- `app/Domain/Ruleset.php` — value object over the snapshot JSON.
- `app/Domain/Scoring.php` — payment math. Pure functions.
- `app/Domain/GameState.php` — replays a list of hands into current scores + table state.

---

## Part 1 — Payment math

### Resolving base points

```
basePoints(ruleset, faan):
    faan = clamp(faan, 0, ruleset.table_max_faan)
    return ruleset.points[faan]   # every faan 0..table_max_faan has a row; missing = error
```

Clamping is against `table_max_faan` — the extent of the points table — and is purely
defensive. The *selectable* range is the narrower `min_faan .. max_faan` band, enforced by
validation (V1, V1b) and by the entry picker, which only offers those values.

### The four win shapes

Let `B = basePoints(...)`, `N` = the game's player count (2, 3, or 4), `W` = winner,
`D` = discarder, `L` = liable player (bao), and "others" = seated players who are neither
the winner nor an explicitly named payer.

**The per-player shares never change with `N` — only the number of payers does.**

| Case | Condition | Payments |
|---|---|---|
| **A. Discard** | `win_type=discard`, `liable=null` | `D` pays `2B`; each of the `N-2` others pays `B`; `W` receives `N·B` |
| **B. Self-pick** | `win_type=self_pick`, `liable=null` | each of the `N-1` losers pays `2B`; `W` receives `2(N-1)·B` |
| **C. Discard + bao** | `win_type=discard`, `liable≠null` — and `L` **is** `D`, always | `D` pays `N·B`; everyone else pays `0`; `W` receives `N·B` |
| **D. Self-pick + bao** | `win_type=self_pick`, `liable≠null` | `L` pays `2(N-1)·B`; everyone else pays `0`; `W` receives `2(N-1)·B` |

At `N=4` this reduces to the familiar `+4B` / `+6B`. Winner receipts by player count:

| | Discard | Self-pick |
|---|---|---|
| 4 players | `+4B` | `+6B` |
| 3 players | `+3B` | `+4B` |
| 2 players | `+2B` | `+2B` |

**The 2-player identity is intentional** (D23). With one opponent, "discarder pays double"
and "the lone loser pays the self-draw share" are the same 2B, so both outcomes pay the
same. `win_type` is still recorded — it is real history and feeds the win-type split
report — it just does not change the money.

**Bao (包)** is the "one player pays for everybody" rule — used when one player is judged
responsible for the entire hand (the classic case: feeding a tile to someone showing nine
tiles of one suit, so that player alone covers the flush). Who the liable player is depends
entirely on the win type, and there are exactly two shapes:

| Win type | Who is liable | How it is named |
|---|---|---|
| **出銃 Discard** | **Always the discarder.** No other player can be liable on a discard win. | Derived — the discarder is already named on the hand. Nothing extra to pick. |
| **自摸 Self-pick** | The player who incurred the bao earlier in the hand. There is no discarder to derive it from. | **Must be named explicitly.** Any seated player except the winner. |

The discard case being fixed is the owner's call: bao on a discard where somebody *other*
than the discarder is held responsible does not happen at this table. It is not merely
unsupported — it is **rejected** (V11), so it cannot be recorded by mistake or by a
malformed client. That collapses the entry UI to a single 包 toggle on discard wins.

The self-pick case is real and happens periodically, which is why `liable_player_id` exists
as a column rather than being folded into `discarder_player_id`. On a discard win it is
still written (equal to the discarder), so "was this a bao hand?" is one non-null check
regardless of win type, and the bao reports in `06-history-reports.md` read one column.

`liable_player_id` may equal `winner_player_id`? **No — reject that** (V3); it is always
another player.

### Draw

All deltas are `0`. Rows are still written to `hand_scores` so the invariant "one row per
seated player per hand" holds.

### Penalty

`offender` pays `penalty_per_player` to each of the other `N-1` players:

```
offender          -= (N - 1) * penalty_per_player
each other player += penalty_per_player
```

Default `penalty_per_player` = `ruleset.penalty_default` (seeded at 128, i.e. a self-drawn
max-faan share under the seeded table). The entry form pre-fills it and allows editing.

### Invariant

`array_sum($deltas) === 0` for every hand, always. Assert it in `Scoring` before returning
and let it throw — a silent imbalance would corrupt every downstream report.

---

## Part 2 — Round / dealer state machine

The owner's intuition is correct and the reference PDF (p.7) confirms it: this is fully
derivable, no manual round buttons needed. It generalises to 2, 3, or 4 players without
changing the wind arithmetic at all — only which chair deals next.

### State

```
round_wind         0..3   # 0=East round, 1=South, 2=West, 3=North - ALWAYS four
dealer_wind_index  0..3   # the CHAIR currently dealing; always an occupied chair
hand_number        1..n
```

Initial state: `round_wind = 0`, `dealer_wind_index = 0`, `hand_number = 1`.

**Rounds are always four, whatever `N` is** (D24). Deals per round is `N`, so a complete
game is `4N` hands minimum: 16 at four players, 12 at three, 8 at two.

### Seat selection

Which chairs are occupied is **picked on the New Game screen**, not derived from `N`
(D23b). East is always occupied — the opening dealer sits there — and the other `N-1`
players may take any of South, West, or North. Defaults are pre-filled (2 → E+W,
3 → E+S+W) but never enforced.

A player's `wind_index` is their chair. It is fixed for the whole game and decides where
they are drawn on the scoreboard diamond.

### All four winds always exist — the deal skips empty chairs, the winds do not

This is the heart of the model (D23c). The table always has four wind positions. Players
occupy some; the rest sit empty. **The winds are a rigid rotation of the compass**,
computed against the physical chair — they are never redistributed among the players.

```
OCCUPIED = sorted list of occupied chair indices    # East (0) is always among them

currentWind(chair) = (chair - dealer_wind_index + 4) % 4     # ALWAYS mod 4, never mod N

nextDealer(w):                    # deal passes counterclockwise, skipping empty chairs
    for step in 1..4:
        c = (w + step) % 4
        if c in OCCUPIED: return c
```

The deal skips empty chairs; the **wind labels do not**. An empty chair still absorbs a
wind each hand, which is exactly why a player's wind can jump — East to North rather than
East to South — when the chairs between them are empty.

#### Worked example — two players at East and West

With the two chairs opposite each other, the two empty chairs are split one to each side,
so both players alternate between two winds that are also opposite.

| Hand | Round | Dealer chair | P1 (East chair) | P2 (West chair) |
|---|---|---|---|---|
| 1 | East | East | **East** | West |
| 2 | East | West | **West** | **East** |
| 3 | South | East | **East** | West |
| 4 | South | West | **West** | **East** |

#### Worked example — two players at East and South

| Hand | Round | Dealer chair | P1 (East chair) | P2 (South chair) |
|---|---|---|---|---|
| 1 | East | East | **East** | South |
| 2 | East | South | **North** | **East** |
| 3 | South | East | **East** | South |
| 4 | South | South | **North** | **East** |
| 5 | West | East | **East** | South |

P1 alternates between East and **North**, never South or West, because both empty chairs
sit between P2 and P1 going counterclockwise. Compare the East+West case above: same two
players, same rule, different winds — which is exactly why occupancy is chosen per game
and never derived from `N`. Two deals complete a round; four
rounds complete the game at 8 hands minimum.

#### Worked example — three players at East, South, West

| Hand | Round | Dealer chair | P1 (E chair) | P2 (S chair) | P3 (W chair) |
|---|---|---|---|---|---|
| 1 | East | East | **East** | South | West |
| 2 | East | South | **North** | **East** | South |
| 3 | East | West | **West** | **North** | **East** |
| 4 | South | East | **East** | South | West |

All four winds appear across a single round even though only three chairs are occupied.
Three deals complete a round; four rounds complete the game at 12 hands.

### Transition, applied after each hand

```
dealerStays =
       outcome == 'draw'
    or outcome == 'penalty'
    or (outcome == 'win' and the winner sits at dealer_wind_index)

if dealerStays:
    # state carries over unchanged
else:
    dealer_wind_index = nextDealer(dealer_wind_index)
    if dealer_wind_index == 0:       # wrapped back to East, which is always occupied
        round_wind += 1
        if round_wind == 4:          # always 4, independent of N
            game is COMPLETE
hand_number += 1
```

The round-boundary test is simply "the deal came back to East". That holds because East is
guaranteed occupied, so it is always the lowest chair in `OCCUPIED` and always where the
wrap lands.

Notes:
- A **penalty** is treated as a dead hand: the deal is void and replayed, so the dealer
  keeps it. (If penalties should rotate, it is this one line.)
- Game completion is detected here, not by counting hands. A game with many dealer wins
  runs well past `4N`.
- When the state machine reports COMPLETE, the API sets `games.status = 'completed'` and
  `ended_at = NOW()` in the same transaction as the final hand.

### Derived display labels

- Round: `['East','South','West','North'][round_wind] + ' Round'`
- Position in round: `'Deal ' + (1-based rank of dealer_wind_index in OCCUPIED) + ' of ' + N`
  — valid because every round begins with the deal at East by construction.
- Dealer: the player sitting at `dealer_wind_index`.

---

## Part 3 — Replay

`GameState::replay(Ruleset $rs, array $seats, array $hands): GameState`

Iterate `hands` ordered by `hand_number`, accumulating `totals[player_id]` from
`hand_scores` and applying the transition above. Returns current totals, current
`round_wind`, `dealer_wind_index`, `next hand_number`, and `is_complete`.

Because every hand row already stores the `round_wind` and `dealer_wind_index` *before*
that hand, replay is only needed to compute the state *after* the last hand. Keep the
full replay anyway and use it in a `bin/verify.php` consistency check — it is the
authority, and the stored columns are the cache.

### Undo

Delete the row with the highest `hand_number` for the game (cascade removes its
`hand_scores`). If the game was `completed`, set it back to `in_progress` and clear
`ended_at`. Then re-derive state. No other hand may ever be deleted.

---

## Part 4 — Test vectors

Points table from the seeded **Hong Kong Standard** ruleset. Faan 3 ⇒ `B = 8`.

### Four players — seats `0=Ann, 1=Ben, 2=Cal, 3=Dee`

| # | Input | Ann | Ben | Cal | Dee |
|---|---|---|---|---|---|
| P1 | Ann wins by discard from Ben | **+32** | −16 | −8 | −8 |
| P2 | Ann wins by self-pick | **+48** | −16 | −16 | −16 |
| P3 | Ann wins by discard from Ben, bao (⇒ liable = Ben) | **+32** | −32 | 0 | 0 |
| P5 | Ann wins by self-pick, bao = Dee | **+48** | 0 | 0 | −48 |
| P6 | Draw | 0 | 0 | 0 | 0 |
| P7 | Penalty, offender = Cal, 128 each | +128 | +128 | **−384** | +128 |

**P4 is deliberately retired**, not renumbered. It read "Ann wins by discard from Ben,
bao = Cal" — a discard win with a third party liable. That case no longer exists: it is now
a rejection, covered by **V11**. The gap in the numbering is there so the vector does not
get reintroduced by someone noticing a missing case, and so every other vector's name stays
stable in the test suite.

### Three players — seats `0=Ann, 1=Ben, 2=Cal`

| # | Input | Ann | Ben | Cal |
|---|---|---|---|---|
| P13 | Ann wins by discard from Ben | **+24** | −16 | −8 |
| P14 | Ann wins by self-pick | **+32** | −16 | −16 |
| P15 | Ann wins by discard from Ben, bao (⇒ liable = Ben) | **+24** | −24 | 0 |
| P16 | Ann wins by self-pick, bao = Ben | **+32** | −32 | 0 |
| P17 | Penalty, offender = Cal, 128 each | +128 | +128 | **−256** |

### Two players — seats `0=Ann (East), 1=Ben (West)`

| # | Input | Ann | Ben |
|---|---|---|---|
| P18 | Ann wins by discard from Ben | **+16** | −16 |
| P19 | Ann wins by self-pick | **+16** | −16 |
| P20 | Assert P18 ≡ P19 — at `N=2` discard and self-pick pay the same (D23, not a bug) | **+16** | −16 |
| P21 | Ann wins by self-pick, bao = Ben | **+16** | −16 |
| P22 | Penalty, offender = Ben, 128 each | +128 | **−128** |

### Band edges (any player count)

| # | Input | Winner receives |
|---|---|---|
| P8 | faan 4 (`B=16`), discard, N=4 | +64 |
| P9 | faan 6 (`B=16`), discard, N=4 | +64 — the 4–6 band is flat |
| P10 | faan 7 (`B=32`), self-pick, N=4 | +192 |
| P11 | faan 13 (`B=64`), self-pick, N=4 | +384 |
| P12 | faan 20 passed straight to `basePoints` | clamps to `table_max_faan` (13) → +384 self-pick at N=4 |

### Winds in use

Winds are **always** `(chair - dealer_wind_index + 4) % 4`. Occupancy affects only which
chair deals next, never the arithmetic.

| # | Occupied chairs | Dealer chair | Assertion |
|---|---|---|---|
| W1 | E,S,W,N | West | E chair→West, S→North, W→East, N→South |
| W2 | E,S | East | E chair→East, S chair→South |
| W3 | E,S | South | E chair→**North**, S chair→East — the owner's worked example |
| W4 | E,W | West | E chair→**West**, W chair→East — with the chairs opposite, these two alternate East ↔ West |
| W4b | E,W | East | E chair→East, W chair→West — the other half of that alternation |
| W5 | E,N | North | E chair→**South**, N chair→East |
| W6 | E,S,W | South | E→North, S→East, W→South |
| W7 | E,S,W | West | E→West, S→North, W→East — all four winds seen across one round |
| W8 | E,S | — | `nextDealer(South)` skips West and North, returns East |
| W9 | E,N | — | `nextDealer(East)` skips South and West, returns North |
| W10 | E,S,W,N | — | `nextDealer(North)` returns East |
| W11 | any | — | Unsorted seat input is normalised; duplicates or length ≠ `N` rejected |
| W12 | no East chair | — | rejected — East must always be occupied |

### State machine

Starting at `round=East, dealer chair=East, hand=1`:

| # | Sequence | Resulting state |
|---|---|---|
| S1 | Ann (seat 0, the dealer) wins | East, dealer 0, hand 2 |
| S2 | then a draw | East, dealer 0, hand 3 |
| S3 | then a penalty on Ben | East, dealer 0, hand 4 |
| S4 | then Ben (seat 1) wins | East, dealer 1, hand 5 |
| S6 | `N=4`: four consecutive non-dealer wins from `dealer 0` | **South** round, dealer 0 |
| S7 | `N=4`: 16 consecutive non-dealer wins from a fresh game | **COMPLETE** after the 16th |
| S8 | `N=4`: 8 dealer wins interleaved | still completes only after the 16th rotation |
| S9 | undo the hand that completed the game | back to `in_progress`, North round, dealer 3 |
| S10 | `N=3`: three consecutive non-dealer wins | **South** round, dealer 0 — 3 deals per round |
| S11 | `N=3`: 12 consecutive non-dealer wins | **COMPLETE**. Four rounds × 3 deals |
| S12 | `N=3`: the round wind still reaches **North** although nobody sits North | per D24 |
| S13 | `N=2`: two consecutive non-dealer wins | **South** round, dealer 0 |
| S14 | `N=2`: 8 consecutive non-dealer wins | **COMPLETE**. Four rounds × 2 deals |
| S15 | any `N`: "Deal *k* of *N*" matches the rank of `dealer_wind_index` in `OCCUPIED` | |

### Invariants (property tests, all `N`)

| # | Assertion |
|---|---|
| I1 | Every hand's deltas sum to exactly `0` |
| I2 | Every hand writes exactly `N` `hand_scores` rows |
| I3 | A completed game has at least `4N` hands |
| I4 | Replaying a game's hands reproduces the stored per-hand `round_wind` / `dealer_wind_index` |

### Validation rejections (must throw)

| # | Input | Reason |
|---|---|---|
| V1 | faan below the game's `min_faan` | D8 |
| V1b | faan above the game's `max_faan`, even with a points row for it | D8b |
| V2 | `discarder == winner` | nonsense |
| V3 | `liable == winner` | nonsense |
| V4 | `win_type='discard'` with no discarder | incomplete |
| V5 | `win_type='self_pick'` with a discarder set | contradictory |
| V6 | any player id not seated in the game | referential |
| V7 | recording a hand on a `completed` game | rule 9 |
| V8 | deleting a hand that is not the last | rule 10 |
| V9 | `player_count` outside 2–4, or `seats` length ≠ `player_count` | rule 1 |
| V10 | `win_type='discard'` at `N=2` where the discarder is not the only opponent | referential |
| V11 | `win_type='discard'` with `liable_player_id` set to anyone but `discarder_player_id` | On a discard win the discarder is the only player who can be liable. Retired vector P4. |
| V12 | `win_type='self_pick'` with bao intended but no `liable_player_id` | There is no discarder to derive it from, so it must be named |
