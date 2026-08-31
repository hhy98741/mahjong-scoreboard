import { effect, signal } from '@preact/signals';
import { setUnauthorizedHandler } from './api.ts';
import type { Lang } from './i18n/terms.ts';
import type { GameStatePayload, Player, Ruleset, User } from './types.ts';

// undefined = session not checked yet (still bootstrapping), null = logged out.
export const session = signal<User | null | undefined>(undefined);

// Replaced wholesale on every read/write — never patched in place
// (docs/04-frontend.md § State discipline).
export const currentGame = signal<GameStatePayload | null>(null);

// Populated by NewGame.tsx and Setup.tsx on mount — each route refetches
// into these on its own mount rather than patching them, so include/exclude
// of retired players never leaks from one screen's fetch into the other's.
export const players = signal<Player[]>([]);
export const rulesets = signal<Ruleset[]>([]);

function readStorage(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStorage(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    // storage unavailable (private browsing, etc.) — the preference just
    // won't persist across reloads
  }
}

const storedLang = readStorage('mahjong.lang');
export const lang = signal<Lang>(storedLang === 'en' || storedLang === 'zh' || storedLang === 'both' ? storedLang : 'both');
effect(() => writeStorage('mahjong.lang', lang.value));

const storedTheme = readStorage('mahjong.theme');
export const theme = signal<'dark' | 'light'>(storedTheme === 'light' ? 'light' : 'dark');
effect(() => {
  writeStorage('mahjong.theme', theme.value);
  document.documentElement.setAttribute('data-theme', theme.value);
});

// A 401 from any request clears the session; the router redirects to
// #/login when it sees session.value === null (see main.tsx).
setUnauthorizedHandler(() => {
  session.value = null;
});
