# Docs

The app is built: all ten core phases plus the Tier 1 and Tier 2 history reports are done,
tested, and (per `deploy/`) deployable. The project is now in **maintenance mode** — bug
fixes, small features, and design tweaks as the owner uses the live board and finds things
to change, not a phased build.

- [`DECISIONS.md`](DECISIONS.md) — the living decisions log. Code comments across the
  codebase cite these by number (`D13`, `D25`, ...); check it before reversing a past call,
  and add to it (don't renumber) when the owner makes a new one.
- [`docs-initial-build/`](../docs-initial-build/) — the frozen pre-build spec set (data
  model, API contract, scoring engine, frontend design, deployment, terminology, the
  phase-by-phase plan). Not maintained — treat it as design history, not current truth. The
  code and `composer test` / `tests/e2e/regression.mjs` are the authority on current
  behavior.

## What's left

Nothing is required. `docs-initial-build/06-history-reports.md` § Tier 3 has four optional
reports that were never built, listed here so the list isn't lost in the archive:

- **Session summary** — games grouped into a "night" by calendar date.
- **Head-to-head** — record between exactly two players.
- **Activity heatmap** — calendar grid of days played.
- **Export** — `GET /api/stats/export.csv`, one row per `hand_scores` entry.

Pick any of these up the same way as any other new feature (see below) — there's no
endpoint reserved for them yet.

## Workflow for new work

1. Find the bug or the desired change by using the app (or ask Claude Code to drive it with
   Playwright — see `CLAUDE.md` § Browser verification).
2. For anything more than a trivial fix, write a short spec — what changes, why, any
   endpoint or schema shape — as its own file in this folder (e.g. `docs/feeder-export.md`)
   before implementing. Delete or fold it into `DECISIONS.md` once shipped; this folder
   should stay small, not accumulate a permanent stack of one-off feature notes.

Non-negotiable conventions (spelling, `N`-player generality, append-only hands, ruleset
snapshotting, etc.) live in the repo root `CLAUDE.md`, not here — that file loads
automatically in every session.
