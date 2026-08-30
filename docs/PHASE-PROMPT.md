# Phase Prompts

One ready-to-paste prompt per build session, in order. Start a fresh session (or `/clear`),
paste the next block, let it run to the end of that phase, review, commit, repeat.

**This file is scaffolding for the build, not a spec.** `docs/PLAN.md` is the authority on
what each phase contains; these are just the prompts that start them.

## Before the first session

```bash
git add -A && git commit -m "spec consistency pass"
git checkout -b build
```

Commit at every phase boundary. Eleven phases is a lot of surface area, and a clean
rollback point per phase is what lets you throw away a bad session cheaply instead of
untangling it.

## Why one phase per session

Context bloat is the most common failure mode on a build this size. By Phase 5 a single
running conversation is carrying four phases of dead detail, and that is when the
conventions start slipping. `CLAUDE.md` loads automatically in every session, so the
non-negotiables come along for free — you lose nothing by starting clean.

Every prompt below ends with a stop instruction and a demand for real command output.
Both are deliberate. Left alone, a model rolls into the next phase on momentum, and
"the tests pass" is not the same claim as pasted terminal output.

---

## Phase 0 — Scaffold

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 0 only. Read the specs it lists first — including
docs/04-frontend.md § Setup, which overrides anything you may expect about
scaffolding a Vite project. There is ONE package.json, at the repo root; do not
run `bun create vite frontend`.

When done, run each check in the "Done when" list and paste the real output,
including confirmation that `bun run build` wrote to this repo's dist/ and not
to its parent directory.

Do not start Phase 1.
```

> The `outDir` trap is the one to watch here: resolved against the wrong Vite root,
> `'../dist'` writes *outside* the repository, the build reports success, and the deploy
> would silently ship nothing. That is why it is a "Done when" check rather than a note.

---

## Phase 1 — Schema

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 1 only. Read docs/01-data-model.md first.

Schema goes in migrations/, seed data goes in bin/seed.php, and never the
reverse.

When done, run each check in the "Done when" list and paste the real output —
including the full rebuild twice in a row, and proof that the second seed run
left a hand-edited base_points value untouched.

Do not start Phase 2.
```

---

## Phase 2 — Auth

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 2 only. Read docs/03-api.md § Auth first.

When done, run each check in the "Done when" list and paste the real output.
The rate-limit check matters: six wrong passwords must return 429 while a
correct login on a DIFFERENT username in the same window still succeeds.

Do not start Phase 3.
```

> If a write through the Vite dev proxy 403s, it is `changeOrigin` on the proxy, not a bug
> in the auth code. The spec calls this out twice; it still catches people.

---

## Phase 3 — Scoring engine

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 3 only. Read all of docs/02-scoring-engine.md first.

This phase is tests-first, and I mean it literally:

1. Write tests/ScoringTest.php and tests/GameStateTest.php FIRST, covering every
   vector in Part 4. Do not write any implementation yet.
2. Run them and paste the output showing them failing.
3. Only then write Domain/Ruleset.php, Domain/Scoring.php, Domain/GameState.php.
4. Run them again and paste the output showing them passing.

P4 and S5 are deliberately retired. Do not reinstate either one.

Parameterise on N from the start. Winds are always % 4; only the deal skips
empty chairs.

Do not start Phase 4.
```

> **The most important session in the build.** Not because it is hard, but because being
> wrong here is invisible — if the payment math or the dealer rotation is subtly off,
> everything downstream is confidently wrong and self-consistent.
>
> The tests-first sequencing is the whole point. Write the implementation first and the
> tests get written to match what was built, which passes green and proves nothing. Making
> the model paste a *failing* run before it may write `Scoring.php` is what forces the
> vectors to be transcribed from the spec rather than derived from the code.
>
> This is also the phase worth running on a stronger model if you have the option.

---

## Phase 4 — Players & rulesets API

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 4 only. Read docs/03-api.md §§ Players, Rulesets and
docs/01-data-model.md first.

The avatar upload is the only untrusted input that lands on disk. The finfo
check and the GD re-encode are not optional, and the multipart CSRF carve-out
in docs/03-api.md § Auth applies to exactly one route.

When done, run each check in the "Done when" list and paste the real output,
including the evil.php.jpg rejection and a /default.svg that actually resolves.

Do not start Phase 5.
```

---

## Phase 5 — Games API

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 5 only. Read docs/03-api.md § Games and docs/02-scoring-engine.md
first.

Never trust client-supplied round or dealer state — replay for it. Follow the
eight-step transaction in docs/03-api.md exactly.

The integration test must run at N=4, N=3, and N=2, and the N=2 run must use a
non-default seat pair (East+South) so a hardcoded 4 cannot pass.

When done, run each check in the "Done when" list and paste the real output,
including bin/verify.php reporting no drift.

Do not start Phase 6.
```

> The longest single phase, and the second of the two that carry the product. The specific
> bug to hunt for in review is a stray `% 4` where `N` belongs, or `N` where `4` belongs.
>
> If the session runs out of room, the natural break point is after the repos and the game
> routes, before `POST /hands`. Use the continuation prompt below rather than pushing on in
> a bloated context.

### Phase 5, continued — only if you had to stop partway

```
Read CLAUDE.md, then docs/PLAN.md.

Phase 5 is partly built. Review what already exists in app/Repo/ and the games
routes, then finish Phase 5 — the remaining routes, the integration test at all
three player counts, and bin/verify.php.

When done, run each check in the "Done when" list and paste the real output.

Do not start Phase 6.
```

---

## Phase 6a — Scoreboard, read-only

> Splitting Phase 6 is a deviation from `docs/PLAN.md`, which treats it as one phase. It is
> by far the largest — router, api client, store, login, tokens, i18n, the SVG diamond,
> standings, hand history, the entire entry bar, and the keyboard scheme. Two sessions.
>
> The split is chosen so 6a ends in something you can actually put on the TV and look at,
> which is a real checkpoint rather than an arbitrary halfway mark.

```
Read CLAUDE.md, then docs/PLAN.md.

Implement the first half of Phase 6 only. Read all of docs/04-frontend.md and
docs/07-terminology.md first.

In scope: router, api.ts, store.ts, types.ts, login screen, session bootstrap,
Home.tsx, styles/tokens.css, i18n/terms.ts and the t() helper with the language
selector, SeatingDiamond.tsx, Standings.tsx, HandHistory.tsx.

NOT in scope this session: EntryBar.tsx, keyboard shortcuts, undo, the
game-complete state. Leave the entry area as a placeholder.

Use the exact SVG coordinates in the spec. Chairs are fixed; wind glyphs rotate.
Empty chairs render dimmed with their current wind and no name. Never patch the
store locally — every write replaces it wholesale.

When done: seed a game with a few hands via curl, then paste a description of
what renders at 1920x1080, confirming the diamond, standings and history all
match what bin/verify.php reports.

Do not build the entry bar.
```

---

## Phase 6b — Entry bar & keyboard

```
Read CLAUDE.md, then docs/PLAN.md.

Finish Phase 6. Re-read docs/04-frontend.md §§ Entry area, Keyboard shortcuts.

Build EntryBar.tsx, FaanPicker.tsx, PlayerPicker.tsx, Confirm.tsx, the penalty
modal, undo with confirmation, the keyboard scheme with its ? overlay, and the
game-complete state.

The 包 control is asymmetric by win type and this is the part to get right: a
bare toggle on a discard win, a REQUIRED picker on a self-pick, with Record
disabled until a liable player is named. Switching win type re-evaluates it.

Only occupied chairs bind to keys. A key naming the winner's own chair is inert.

When done, run the full Phase 6 "Done when" list and paste the real output,
including a complete game scored start to finish, a two-player game at East+South
showing two dimmed empty chairs with rotating winds, all three language modes
rendering without wrapping, and a discard hand entered with the keyboard alone.

Do not start Phase 7.
```

---

## Phase 7 — New game & setup UI

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 7 only. Read docs/04-frontend.md §§ Routes, Setup first.

All four wind rows always show, whatever the player count. East is required and
cannot be emptied. Pre-fill the defaults but never enforce them — East+North must
be as easy to pick as East+West.

When done, paste proof of the full "Done when" list: an empty database to a
running two-player game at East+North, entirely through the UI.

Do not start Phase 8.
```

---

## Phase 8 — History & Tier 1 reports

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 8 only. Read docs/06-history-reports.md Tier 1 and
docs/03-api.md § Stats first.

Rank by net points within a game, by rate across games. The leaderboard sorts by
game win % with points-per-hand adjacent and Games always visible.

player_count defaults to 4 on every stats endpoint, not just the leaderboard.

When done, run each check in the "Done when" list and paste the real output,
including the leaderboard numbers tied out against a manual SUM(points_delta)
query and the reconciliation test showing net points summing to zero.

Do not start Phase 9.
```

---

## Phase 9 — Deploy

> **Drive this one yourself.** Let the agent write the scripts; you run them. It touches a
> real server with real `rsync --delete` commands, and a mistake against `.well-known/`
> breaks certificate renewal silently, up to 90 days later.
>
> Fill in `deploy/deploy.conf` before you start — all four keys are currently blank.

```
Read CLAUDE.md, then docs/PLAN.md.

Implement Phase 9 only. Read all of docs/05-deployment.md first, including the
--delete warning, before writing any sync command.

Write deploy/remote/.htaccess, deploy/deploy.sh, deploy/migrate.sh and
deploy/backup.sh exactly as specified. deploy/remote/api/index.php already exists
from Phase 0 — ship it, do not rewrite it.

Do NOT run any of these scripts against the server. Write them, explain what each
one will do when I run it, and stop. I will run them myself.

Flag anything in deploy.conf that still needs filling in.
```

> After it hands the scripts back, work the post-deploy checklist at the end of
> `05-deployment.md` with the 8G firewall **on**. Test the `.htaccess` rollback deliberately,
> once, on purpose — before you are relying on it in an emergency.

---

## Phase 10 — Tier 2 reports

Pure addition, nothing depends on it, and it is more interesting once there are real games
in the database. One report per session, in any order.

Each of these has **no endpoint specified yet** — that is deliberate (`03-api.md` § Stats).
The prompts ask for the endpoint to be designed and written into the API spec as part of
the work, so the contract does not drift from the code.

### Points flow matrix

```
Read CLAUDE.md, then docs/PLAN.md, then docs/06-history-reports.md report #5.

Build the points flow matrix. GET /api/stats/flow is already named in
docs/03-api.md § Stats but not specified — design its payload, add it to that
table, then implement StatsRepo and the UI.

It takes the same four filters as every stats endpoint, with player_count
defaulting to 4.

Add the reconciliation test: attributed flows must sum to zero.

Stop when this one report works. Do not build the other Tier 2 reports.
```

### Seat luck

```
Read CLAUDE.md, then docs/PLAN.md, then docs/06-history-reports.md report #6.

Build seat luck. GET /api/stats/seats is named in docs/03-api.md § Stats but not
specified — design its payload, add it to that table, then implement.

Group by the wind actually HELD when the hand was played, not by chair. At fewer
than four players some winds never occur; return only the winds that do. Break
out dealer win rate separately.

Stop when this one report works.
```

### Streaks and records

```
Read CLAUDE.md, then docs/PLAN.md, then docs/06-history-reports.md report #7.

Build the streaks and records board. There is no endpoint for this yet — design
one, add it to the docs/03-api.md § Stats table, then implement.

Each superlative links to the hand or game it came from.

Stop when this one report works.
```

### Feeder stats

```
Read CLAUDE.md, then docs/PLAN.md, then docs/06-history-reports.md report #8.

Build feeder stats. There is no endpoint for this yet — design one, add it to the
docs/03-api.md § Stats table, then implement.

Stop when this one report works.
```

### Win-type split

```
Read CLAUDE.md, then docs/PLAN.md, then docs/06-history-reports.md report #9.

Build the win-type split. There is no endpoint for this yet — design one, add it
to the docs/03-api.md § Stats table, then implement.

Break the 包 counts out by win type rather than blending them — a discard bao is
always the discarder's doing, a self-pick bao names someone already on the hook,
and merging the two hides the more interesting number.

Stop when this one report works.
```

---

## Between every session

```bash
composer test                    # must stay green from Phase 3 onward
git add -A && git commit -m "phase N"
```

Then `/clear`, and paste the next block.
