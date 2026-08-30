# 07 — Bilingual Terminology

Every mahjong term in the UI can render in English, Chinese, or both. This spec is the
single source of truth for the vocabulary.

## Decision: hardcoded, not a database table

The owner asked whether the Chinese characters should live in an editable settings table
or be hardcoded. **Hardcode them** in `frontend/src/i18n/terms.ts`, for three reasons:

1. This is product copy, not user data. It has a closed, fixed set of ~20 entries that
   will not grow as games are played.
2. A database table costs a migration, a repo, API routes, and a settings form — roughly
   150 lines — to edit strings that change approximately never.
3. The stated reason for wanting it editable ("I don't have all the Chinese characters
   right now") is solved by the table below, which supplies them all.

The trade-off is that changing a term means editing one file and redeploying. That is a
two-minute operation for the person who owns the deploy script. If it ever becomes
annoying, promoting `terms.ts` to a database table later is a contained change — the
lookup helper's signature does not have to change.

The **one** thing that is a setting is which language to display. Store it in
`localStorage` under `mahjong.lang`, not in the database: it is a per-screen display
preference on a single-device app, and it must not require a round-trip to apply.

## The terms

`frontend/src/i18n/terms.ts`:

```ts
export type Lang = 'en' | 'zh' | 'both';

export interface Term { en: string; zh: string; jyutping: string; }

export const TERMS = {
  // scoring
  faan:      { en: 'Faan',      zh: '番',   jyutping: 'faan1' },
  points:    { en: 'Points',    zh: '分',   jyutping: 'fan1' },

  // structure
  game:      { en: 'Game',      zh: '牌局', jyutping: 'paai4 guk6' },
  round:     { en: 'Round',     zh: '圈',   jyutping: 'hyun1' },
  hand:      { en: 'Hand',      zh: '局',   jyutping: 'guk6' },

  // winds — these are the four that matter most
  east:      { en: 'East',      zh: '東',   jyutping: 'dung1' },
  south:     { en: 'South',     zh: '南',   jyutping: 'naam4' },
  west:      { en: 'West',      zh: '西',   jyutping: 'sai1' },
  north:     { en: 'North',     zh: '北',   jyutping: 'bak1' },

  // roles
  dealer:       { en: 'Dealer',         zh: '莊家', jyutping: 'zong1 gaa1' },
  dealerMark:   { en: 'D',              zh: '莊',   jyutping: 'zong1' },
  openingDealer:{ en: 'Opening dealer', zh: '開莊', jyutping: 'hoi1 zong1' },
  player:       { en: 'Player',         zh: '玩家', jyutping: 'wun6 gaa1' },

  // outcomes
  win:       { en: 'Win',       zh: '食糊', jyutping: 'sik6 wu2' },
  selfPick:  { en: 'Self-pick', zh: '自摸', jyutping: 'zi6 mo1' },
  discard:   { en: 'Discard',   zh: '出銃', jyutping: 'ceot1 cung3' },
  draw:      { en: 'Draw',      zh: '黃莊', jyutping: 'wong4 zong1' },
  bao:       { en: 'Pays all',  zh: '包',   jyutping: 'baau1' },
  penalty:   { en: 'Penalty',   zh: '罰',   jyutping: 'fat6' },
  falseWin:  { en: 'False win', zh: '詐糊', jyutping: 'zaa3 wu2' },
} as const;
```

## Scope: mahjong vocabulary only

The language toggle switches **mahjong terms only**. UI chrome — button labels, menu
items, form fields, error messages, the history and setup screens — stays in English in
every mode. That is what the owner asked for, and it keeps the app navigable for anyone
at the table regardless of which mode the screen happens to be in.

### Why these forms

The owner asked specifically for Hong Kong Cantonese, so where a Cantonese form and a
Mandarin-influenced form both circulate, the Cantonese one is used:

| Chosen | Rejected | Why |
|---|---|---|
| **出銃** | 點炮 | 點炮 is the Mandarin term. 出銃 (also written 出沖) is what a Hong Kong table says. |
| **黃莊** | 流局 | 流局 is Mandarin/Japanese-influenced. 黃莊 is distinctly Cantonese, and it literally names the rule this app implements — the deal "goes yellow" and the dealer keeps it. |
| **食糊** | 和牌 / 胡牌 | 食糊 is the Cantonese verb for winning a hand. |
| **番** | 翻 | Both read *faan1*; 番 is the usual Hong Kong written form. |
| **開莊** | 首莊 | 開莊 is the natural Cantonese for taking the first deal. |

**牌局** for a full four-round game is a reasonable general term rather than a precise
one — Hong Kong players more often just say 打四圈. If the owner prefers something else,
it is a one-line edit to `terms.ts`.

## Rendering

```ts
export function t(key: keyof typeof TERMS, lang: Lang): string {
  const term = TERMS[key];
  if (lang === 'en') return term.en;
  if (lang === 'zh') return term.zh;
  return `${term.zh} ${term.en}`;   // 'both'
}
```

Three modes, selected from the menu bar:

| Mode | Round label | Wind in the diamond | Win type in history |
|---|---|---|---|
| `en` | `South Round` | `S` | `self-pick` |
| `zh` | `南圈` | `南` | `自摸` |
| `both` | `南圈 South Round` | `南 S` | `自摸 self-pick` |

The opening-dealer marker shows 開莊 in `zh` and `both`, and "Opening dealer" in `en`.

Default is `both`. Numbers stay Arabic everywhere — `5 番`, not `五番`. That is how scores
are written at a real table and it keeps the digits scannable from across the room.

### Space rules

`both` mode is verbose. Two places must stay compact regardless of mode:

- **The diamond's wind labels** — render the Chinese character large with the English
  letter as a small superscript, never on two lines.
- **The faan buttons in the entry bar** — the number alone, with the unit shown once as a
  label above the row.

## Backend

The API returns raw enum values only — `"self_pick"`, `"draw"`, `wind_index: 2`. It never
returns display strings. All translation happens in the frontend. This keeps the API
language-agnostic and means adding a mode never touches PHP.
