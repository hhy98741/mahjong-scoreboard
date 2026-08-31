// Single source of truth for bilingual vocabulary. Hardcoded by design —
// see docs/07-terminology.md § Decision. The language toggle switches
// mahjong terms only; UI chrome stays English in every mode.

export type Lang = 'en' | 'zh' | 'both';

export interface Term {
  en: string;
  zh: string;
  jyutping: string;
}

export const TERMS = {
  // scoring
  faan: { en: 'Faan', zh: '番', jyutping: 'faan1' },
  points: { en: 'Points', zh: '分', jyutping: 'fan1' },

  // structure
  game: { en: 'Game', zh: '牌局', jyutping: 'paai4 guk6' },
  round: { en: 'Round', zh: '圈', jyutping: 'hyun1' },
  hand: { en: 'Hand', zh: '局', jyutping: 'guk6' },

  // winds — these are the four that matter most
  east: { en: 'East', zh: '東', jyutping: 'dung1' },
  south: { en: 'South', zh: '南', jyutping: 'naam4' },
  west: { en: 'West', zh: '西', jyutping: 'sai1' },
  north: { en: 'North', zh: '北', jyutping: 'bak1' },

  // roles
  dealer: { en: 'Dealer', zh: '莊家', jyutping: 'zong1 gaa1' },
  openingDealer: { en: 'Opening dealer', zh: '開莊', jyutping: 'hoi1 zong1' },
  player: { en: 'Player', zh: '玩家', jyutping: 'wun6 gaa1' },

  // outcomes
  win: { en: 'Win', zh: '食糊', jyutping: 'sik6 wu2' },
  selfPick: { en: 'Self-pick', zh: '自摸', jyutping: 'zi6 mo1' },
  discard: { en: 'Discard', zh: '出銃', jyutping: 'ceot1 cung3' },
  draw: { en: 'Draw', zh: '黃莊', jyutping: 'wong4 zong1' },
  bao: { en: 'Pays all', zh: '包', jyutping: 'baau1' },
  penalty: { en: 'Penalty', zh: '罰', jyutping: 'fat6' },
  falseWin: { en: 'False win', zh: '詐糊', jyutping: 'zaa3 wu2' },
} as const;

export type TermKey = keyof typeof TERMS;

const WIND_KEYS = ['east', 'south', 'west', 'north'] as const;
type WindKey = (typeof WIND_KEYS)[number];

function windKey(index: number): WindKey {
  const key = WIND_KEYS[((index % 4) + 4) % 4];
  if (key === undefined) throw new Error(`unreachable wind index ${index}`);
  return key;
}

/** Renders one term in the given mode. `'both'` is `"{zh} {en}"`. */
export function t(key: TermKey, lang: Lang): string {
  const term = TERMS[key];
  if (lang === 'en') return term.en;
  if (lang === 'zh') return term.zh;
  return `${term.zh} ${term.en}`;
}

/**
 * The round label above the seating diamond — not a plain t() lookup, since
 * the three modes compose differently ("South Round" / "南圈" / "南圈 South
 * Round"), per the table in docs/07-terminology.md § Rendering.
 */
export function roundLabel(roundWindIndex: number, lang: Lang): string {
  const term = TERMS[windKey(roundWindIndex)];
  if (lang === 'en') return `${term.en} Round`;
  if (lang === 'zh') return `${term.zh}圈`;
  return `${term.zh}圈 ${term.en} Round`;
}

/**
 * The wind glyph shown at a chair. In `'both'` mode the Chinese character is
 * the main glyph and the English letter renders as a small superscript —
 * never "South S" side by side (docs/04-frontend.md § Space rules).
 */
export function windGlyph(windIndex: number, lang: Lang): { main: string; sup: string | null } {
  const term = TERMS[windKey(windIndex)];
  const letter = term.en.charAt(0);
  if (lang === 'en') return { main: letter, sup: null };
  if (lang === 'zh') return { main: term.zh, sup: null };
  return { main: term.zh, sup: letter };
}

/** Faan value with its unit, e.g. "5 Faan" / "5番" / "5 番 Faan". */
export function faanLabel(faan: number, lang: Lang): string {
  return lang === 'zh' ? `${faan}${t('faan', lang)}` : `${faan} ${t('faan', lang)}`;
}
