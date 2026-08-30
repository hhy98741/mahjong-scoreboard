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
    faan = clamp(faan, 0, ruleset.max_faan)
    return ruleset.points[faan]      # every faan 0..max_faan has a row; missing = error
```

Clamping is defensive; the UI already restricts the dropdown to `min_faan .. max_faan`.

### The four win shapes

Let `B = basePoints(...)`, `W` = winner, `D` = discarder, `L` = liable player (bao),
and "others" = the seated players who are neither the winner nor an explicitly named payer.

| Case | Condition | Payments |
|---|---|---|
| **A. Discard** | `win_type=discard`, `liable=null` | `D` pays `2B`; the two others pay `B` each; `W` receives `4B` |
| **B. Self-pick** | `win_type=self_pick`, `liable=null` | each of the three losers pays `2B`; `W` receives `6B` |
| **C. Discard + bao** | `win_type=discard`, `liable≠null` | `L` pays `4B`; everyone else pays `0`; `W` receives `4B` |
| **D. Self-pick + bao** | `win_type=self_pick`, `liable≠null` | `L` pays `6B`; everyone else pays `0`; `W` receives `6B` |

**Bao (包)** is the "one player pays for everybody" rule — used when a player's discard is
judged responsible for the entire hand (the classic case: feeding the winning tile to
someone showing nine tiles of one suit, so the discarder alone covers the flush). The
liable player is usually the discarder, but the UI must allow naming a different player,
because bao can be incurred on a hand that is later won by self-pick.

`liable_player_id` may equal `winner_player_id`? **No — reject that**; it is always
another player.

### Draw

All four deltas are `0`. Rows are still written to `hand_scores` so the invariant "four
rows per hand" holds.

### Penalty

`offender` pays `penalty_per_player` to each of the other three:

```
offender          -= 3 * penalty_per_player
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
derivable, no manual round buttons needed.

### State

```
round_wind         0..3   # 0=East round, 1=South, 2=West, 3=North
dealer_seat_index  0..3   # index into game_seats
hand_number        1..n
```

Initial state: `round_wind = 0`, `dealer_seat_index = 0`, `hand_number = 1`.

### Transition, applied after each hand

```
dealerStays =
       outcome == 'draw'
    or outcome == 'penalty'
    or (outcome == 'win' and winner is the player seated at dealer_seat_index)

if dealerStays:
    # state carries over unchanged
else:
    dealer_seat_index += 1
    if dealer_seat_index == 4:
        dealer_seat_index = 0
        round_wind += 1
        if round_wind == 4:
            game is COMPLETE       # no further hands may be recorded
hand_number += 1
```

Notes:
- A **penalty** is treated as a dead hand: the deal is void and replayed, so the dealer
  keeps the deal. (If the owner later wants penalties to rotate, it is this one line.)
- Game completion is detected here, not by counting hands. A game with many dealer wins
  can run well past 16 hands.
- When the state machine reports COMPLETE, the API sets `games.status = 'completed'` and
  `ended_at = NOW()` in the same transaction as the final hand.

### Seat winds for display

Each seated player's wind **for the current hand**:

```
windIndex(seat_index) = (seat_index - dealer_seat_index + 4) % 4
                        # 0=East(dealer), 1=South, 2=West, 3=North
```

### Derived display labels

- Round: `['East','South','West','North'][round_wind] + ' Round'`
- Position in round: `'Deal ' + (dealer_seat_index + 1) + ' of 4'` — valid because every
  round begins at `dealer_seat_index = 0` by construction.
- Dealer: the player at `dealer_seat_index`.

---

## Part 3 — Replay

`GameState::replay(Ruleset $rs, array $seats, array $hands): GameState`

Iterate `hands` ordered by `hand_number`, accumulating `totals[player_id]` from
`hand_scores` and applying the transition above. Returns current totals, current
`round_wind`, `dealer_seat_index`, `next hand_number`, and `is_complete`.

Because every hand row already stores the `round_wind` and `dealer_seat_index` *before*
that hand, replay is only needed to compute the state *after* the last hand. Keep the
full replay anyway and use it in a `bin/verify.php` consistency check — it is the
authority, and the stored columns are the cache.

### Undo

Delete the row with the highest `hand_number` for the game (cascade removes its
`hand_scores`). If the game was `completed`, set it back to `in_progress` and clear
`ended_at`. Then re-derive state. No other hand may ever be deleted.

---

## Part 4 — Test vectors

All using the seeded **Hong Kong Standard** ruleset. Seats: `0=Ann, 1=Ben, 2=Cal, 3=Dee`.

### Payments — faan 3, so `B = 8`

| # | Input | Ann | Ben | Cal | Dee |
|---|---|---|---|---|---|
| P1 | Ann wins by discard from Ben | **+32** | −16 | −8 | −8 |
| P2 | Ann wins by self-pick | **+48** | −16 | −16 | −16 |
| P3 | Ann wins by discard from Ben, bao = Ben | **+32** | −32 | 0 | 0 |
| P4 | Ann wins by discard from Ben, bao = Cal | **+32** | 0 | −32 | 0 |
| P5 | Ann wins by self-pick, bao = Dee | **+48** | 0 | 0 | −48 |
| P6 | Draw | 0 | 0 | 0 | 0 |
| P7 | Penalty, offender = Cal, 128 each | +128 | +128 | **−384** | +128 |

### Payments — band edges

| # | Input | Winner receives |
|---|---|---|
| P8 | faan 4 (`B=16`), discard | +64 |
| P9 | faan 6 (`B=16`), discard | +64 — same as P8; the 4–6 band is flat |
| P10 | faan 7 (`B=32`), self-pick | +192 |
| P11 | faan 13 (`B=64`), self-pick | +384 |
| P12 | faan 20 submitted directly to the engine | clamps to 13 → +384 self-pick |

### State machine

Starting at `round=East, dealer_seat=0, hand=1`:

| # | Sequence | Resulting state |
|---|---|---|
| S1 | Ann (seat 0, the dealer) wins | East, dealer 0, hand 2 |
| S2 | then a draw | East, dealer 0, hand 3 |
| S3 | then a penalty on Ben | East, dealer 0, hand 4 |
| S4 | then Ben (seat 1) wins | East, dealer 1, hand 5 |
| S5 | then Cal wins, then Dee wins, then Ann wins | East, dealer... → see S6 |
| S6 | four consecutive non-dealer wins from `dealer 0` | **South** round, dealer 0 |
| S7 | 16 consecutive non-dealer wins from a fresh game | game **COMPLETE** after the 16th |
| S8 | game with 8 dealer wins interleaved | still completes only after the 16th rotation |
| S9 | undo the hand that completed the game | status back to `in_progress`, North round, dealer 3 |

### Seat winds

With `dealer_seat_index = 2`: seat 2 → East, seat 3 → South, seat 0 → West, seat 1 → North.

### Validation rejections (must throw)

| # | Input | Reason |
|---|---|---|
| V1 | faan below `min_faan` | D8 |
| V2 | `discarder == winner` | nonsense |
| V3 | `liable == winner` | nonsense |
| V4 | `win_type='discard'` with no discarder | incomplete |
| V5 | `win_type='self_pick'` with a discarder set | contradictory |
| V6 | any player id not seated in the game | referential |
| V7 | recording a hand on a `completed` game | D11 / rule 9 |
| V8 | deleting a hand that is not the last | D11 / rule 10 |
