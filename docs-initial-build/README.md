# Initial build archive

This folder is the design-and-build spec set written *before* any code existed, used to
build the app phase by phase. It is **frozen** — moved here once the build was substantially
done (Phases 0–10: scaffold through Tier 2 reports) — and is **not maintained going forward**.
The code and its tests are the authoritative description of current behavior; treat anything
here as "what we intended when we wrote it," not "what is true today."

Only [`docs/DECISIONS.md`](../docs/DECISIONS.md) was carried forward as a still-living
document — it was split out of `00-overview.md` because code comments across the codebase
cite decision numbers (`D13`, `D25`, `D27`, ...) directly. Add new decisions there, not here.

| File | Was |
|---|---|
| `PLAN.md` | The eleven-phase build plan |
| `PHASE-PROMPT.md` | Paste-in prompts that started each phase's session |
| `00-overview.md` | Product scope, glossary, decisions log (now `docs/DECISIONS.md`) |
| `01-data-model.md` | MariaDB schema spec |
| `02-scoring-engine.md` | Payment math + round/dealer state machine + test vectors |
| `03-api.md` | JSON API contract as designed |
| `04-frontend.md` | SPA structure and screen designs |
| `05-deployment.md` | Build + rsync deploy design |
| `06-history-reports.md` | Tier 1–3 report specs (Tier 1–2 are built; Tier 3 is optional, see `docs/README.md`) |
| `07-terminology.md` | Bilingual (English / 中文) term list and language modes |
| `reference/` | Source material the specs above were written from (HK rules PDF, the owner's dashboard sketch) |

Some code comments reference these files by name for a section-level "why" (e.g.
`04-frontend.md § Color scheme`) — those references now point here.
