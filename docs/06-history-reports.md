# 06 — History & Reports

The owner asked what would be fun to look at months later. This is the menu. Everything
here is derivable from `hands` + `hand_scores` + `game_seats` — no extra tables, no
denormalisation beyond what already exists.

Build **Tier 1 first**; it is most of the value. Tier 2 is the fun stuff. Tier 3 is
optional polish.

All charts are hand-rolled inline SVG. No charting library. A sparkline is a `<polyline>`;
a bar chart is a stack of `<rect>`s. Every chart needs a table fallback beneath it — the
numbers are the point.

Every report accepts a date range (`from`/`to`) and defaults to **all time**, with quick
presets: All time · This year · Last 90 days · Last session.

---

## Tier 1 — build with the history page

### 1. Game list
The landing view of `#/history`. One card per game, newest first: date, optional name,
the four avatars, final scores in rank order, winner highlighted, hand count, duration.
Filter by player and by date range. Click through to game detail.

### 2. Game detail
The full hand-by-hand log for one game (the same `HandRow` component the scoreboard uses),
plus a **score curve**: four lines of cumulative points across hands, x-axis = hand number,
with round boundaries marked as vertical rules. Reading a comeback on that chart is the
single most satisfying thing in this app.

### 3. All-time leaderboard

The default report. One row per player over the selected range:

| Column | Definition |
|---|---|
| Net points | `SUM(points_delta)` |
| Games | games seated in |
| Games won | games where they finished rank 1 |
| **Game win %** | games won / games played |
| Hands | hands seated for |
| Hands won | `outcome='win' AND winner_player_id = p` |
| Hand win % | hands won / hands played |
| Points per hand | net points / hands played |
| Avg 番 | mean `faan` over their wins |
| Best hand | max `faan`, with a link to that hand |

Sort by any column.

#### Which ranking is "the" ranking

Two different questions, two different answers — do not conflate them:

- **Within a single game** (the live scoreboard, and the game detail page) the ranking is
  **net points**, highest first. Nothing else makes sense: everyone has played exactly the
  same hands, so the raw total *is* the result.
- **Across many games** (this leaderboard) net points is misleading, because whoever
  turns up most often accumulates the biggest number regardless of skill. Use a rate.

Two rate statistics are worth showing, and they answer different questions:

| Statistic | Answers |
|---|---|
| **Game win %** | "Who wins nights?" Simple, intuitive, the one to lead with. Coarse — one game is one data point, so it takes many nights to mean much. |
| **Points per hand** | "Who is actually ahead per unit of play?" Far more data behind it, so it stabilises much sooner, but it is a less natural number to say out loud. |

Default the sort to **Game win %**, with Points per hand adjacent, and always show
`Games` beside them so a 100% built on two games is visibly thin. Grey out any player
below a threshold (say 5 games) in the rate columns rather than hiding them.

### 4. Player detail
Everything from the leaderboard row for one person, plus:
- **Cumulative points over time** — a career line chart across all games.
- **Faan histogram** — how they win. A player whose wins cluster at the minimum faan is
  playing a completely different game from one with a fat tail.
- **Recent form** — last 10 games as +/− chips.

---

## Tier 2 — the fun ones

### 5. Money-flow matrix
A 4×4 (or N×N) grid: net points transferred from the row player to the column player,
across all hands. Colour the cells diverging red/green.

This answers the question everyone at the table actually asks — *"who keeps paying me?"* —
and it reveals real patterns: who feeds whom, who never deals into whose hands.

Computation: for each `win` hand, attribute each loser's negative delta to the winner.
For a `penalty`, attribute the offender's loss to each recipient. Draws contribute nothing.

### 6. Seat luck
Net points and win rate grouped by the **wind the player held when the hand was played**
(`(seat_index - dealer_seat_index + 4) % 4`), aggregated across every game. Settles the
perennial argument about whether East is actually worth anything. Also break out **dealer
win rate** — the dealer keeps the deal on a win, so a hot dealer compounds.

### 7. Streaks and records
A small board of superlatives, each linking to the hand or game:
- Biggest single hand (points, and separately faan).
- Longest consecutive hand-win streak.
- Biggest comeback: largest deficit at any point in a game that still ended in a win.
- Longest drought: most consecutive hands without a win.
- Most dealer defences in a row.
- Worst night, best night.

### 8. Feeder stats
Per player, as the **discarder**: how many hands they dealt into, total points paid as
discarder, and their discard rate versus the table average. The complement of the win
stats, and nobody tracks this on paper.

### 9. Win-type split
Self-pick vs discard, as a share of each player's wins, plus a table-wide draw rate. Also
count 包 (bao) incidents — who causes them, who benefits.

---

## Tier 3 — optional

### 10. Session summary
Group games by calendar date into a "night". Net points per player for the night, games
played, who came out ahead. Useful when they play three or four games in an evening.

### 11. Head-to-head
Pick two players: hands played together, net flow between them, who wins more, best hands
against each other.

### 12. Activity heatmap
A calendar grid of days played, GitHub-style. Purely decorative, genuinely nice to see a
year of Sundays.

### 13. Export
`GET /api/stats/export.csv` — one row per `hand_scores` entry with game, date, hand number,
player, outcome, faan, delta. Lets the owner do anything this app never thought of, in a
spreadsheet. Cheap to build, worth doing.

---

## Implementation notes

- Everything lives in `Repo/StatsRepo.php` as parameterised SQL. Index-wise, the schema
  in `01-data-model.md` already carries `hand_scores(player_id)` and
  `games(status, started_at)`, which covers these queries at this data volume — a decade
  of weekly play is on the order of 20k `hand_scores` rows.
- Reports read **only** completed and in-progress games; exclude `abandoned` from
  leaderboards by default, with a toggle to include them.
- Every aggregate that involves points must reconcile: the sum of all players' net points
  over any range is exactly `0`. Add that as a test with seeded data — it catches
  attribution bugs in the flow matrix immediately.
