// Persistent top bar, rendered once in main.tsx - identical on every
// authenticated page. Brand on the left always goes home, so individual
// pages no longer need their own "Home" link; primary nav + Profile live
// here instead of being rebuilt per page.

import { route } from '../router.ts';
import { ProfileMenu } from './ProfileMenu.tsx';

const LINKS: { match: (name: string) => boolean; href: string; label: string }[] = [
  { match: (n) => n === 'new', href: '#/new', label: 'New game' },
  { match: (n) => n === 'history' || n === 'historyGame' || n === 'historyPlayer', href: '#/history', label: 'History' },
  { match: (n) => n === 'setup', href: '#/setup', label: 'Setup' },
];

export function AppNav() {
  const currentName = route.value.name;

  return (
    <header class="app-nav">
      <a href="#/" class="app-nav-brand">
        <img src="/logo.svg" alt="Mahjong Scoreboard" class="app-nav-logo" />
      </a>
      <nav class="app-nav-links">
        {LINKS.map((l) => (
          <a key={l.href} href={l.href} class={l.match(currentName) ? 'app-nav-link active' : 'app-nav-link'}>
            {l.label}
          </a>
        ))}
      </nav>
      <ProfileMenu />
    </header>
  );
}
