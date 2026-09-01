// Hash-based router (docs-initial-build/04-frontend.md § Setup) — everything after the #
// stays in the browser, so deep links work with no server rewrite rule.
// Deliberately hand-rolled rather than a dependency; only the three routes
// this session builds are recognised, everything else falls through to
// 'unknown' so a not-yet-built screen fails soft instead of blank.

import { signal } from '@preact/signals';

export type Route =
  | { name: 'login' }
  | { name: 'home' }
  | { name: 'new' }
  | { name: 'setup' }
  | { name: 'profile' }
  | { name: 'game'; id: number }
  | { name: 'history' }
  | { name: 'historyGame'; id: number }
  | { name: 'historyPlayer'; id: number }
  | { name: 'unknown'; path: string };

function parse(hash: string): Route {
  const path = hash.replace(/^#/, '') || '/';
  if (path === '/login') return { name: 'login' };
  if (path === '/') return { name: 'home' };
  if (path === '/new') return { name: 'new' };
  if (path === '/setup') return { name: 'setup' };
  if (path === '/profile') return { name: 'profile' };
  if (path === '/history') return { name: 'history' };
  const gameMatch = /^\/game\/(\d+)$/.exec(path);
  if (gameMatch) return { name: 'game', id: Number(gameMatch[1]) };
  const historyGameMatch = /^\/history\/game\/(\d+)$/.exec(path);
  if (historyGameMatch) return { name: 'historyGame', id: Number(historyGameMatch[1]) };
  const historyPlayerMatch = /^\/history\/player\/(\d+)$/.exec(path);
  if (historyPlayerMatch) return { name: 'historyPlayer', id: Number(historyPlayerMatch[1]) };
  return { name: 'unknown', path };
}

export const route = signal<Route>(parse(location.hash));

window.addEventListener('hashchange', () => {
  route.value = parse(location.hash);
});

export function navigate(hash: string): void {
  if (location.hash === hash) {
    route.value = parse(hash); // same hash requested again — force a reparse
  } else {
    location.hash = hash;
  }
}
