// Ranked by net points, highest first — the only ranking that makes sense
// within a single game (D13). FLIP transition on rank change.
// docs-initial-build/04-frontend.md § Standings.

import { useLayoutEffect, useRef } from 'preact/hooks';
import type { Seat } from '../types.ts';

interface StandingsProps {
  seats: Seat[];
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  const second = parts[1];
  if (parts.length <= 1) return first.slice(0, 2).toUpperCase() || '?';
  return `${first.charAt(0)}${(second ?? '').charAt(0)}`.toUpperCase();
}

export function Standings({ seats }: StandingsProps) {
  // Stable sort keeps chair order for ties (seats already arrives chair-ordered).
  const ordered = [...seats].sort((a, b) => b.total - a.total);

  const rowEls = useRef(new Map<number, HTMLLIElement>());
  const prevTops = useRef(new Map<number, number>());

  useLayoutEffect(() => {
    const nextTops = new Map<number, number>();
    for (const [id, el] of rowEls.current) {
      nextTops.set(id, el.getBoundingClientRect().top);
    }

    for (const [id, el] of rowEls.current) {
      const prevTop = prevTops.current.get(id);
      const nextTop = nextTops.get(id);
      if (prevTop === undefined || nextTop === undefined) continue;
      const delta = prevTop - nextTop;
      if (delta === 0) continue;

      el.style.transition = 'none';
      el.style.transform = `translateY(${delta}px)`;
      requestAnimationFrame(() => {
        el.style.transition = 'transform 0.4s ease';
        el.style.transform = '';
      });
    }

    prevTops.current = nextTops;
  });

  return (
    <div class="standings-panel">
      <h2>Standings</h2>
      <ul class="standings-list">
        {ordered.map((seat) => {
          const isDefault = seat.player.avatar_url === '/default.svg';
          const sign = seat.total < 0 ? '−' : '+';
          return (
            <li
              key={seat.player.id}
              class="standings-row"
              ref={(el) => {
                if (el) rowEls.current.set(seat.player.id, el);
                else rowEls.current.delete(seat.player.id);
              }}
            >
              <span class="standings-rank">{seat.rank}</span>
              <span class="standings-avatar" style={{ '--player-color': seat.player.color } as Record<string, string>}>
                <img src={seat.player.avatar_url} alt="" />
                {isDefault && (
                  <span class="avatar-initials-overlay" style={{ color: seat.player.color }}>
                    {initials(seat.player.name)}
                  </span>
                )}
              </span>
              <span class="standings-name" style={{ color: seat.player.color }}>
                {seat.player.name}
              </span>
              <span class={`standings-total ${seat.total < 0 ? 'negative' : 'positive'}`}>
                {sign}
                {Math.abs(seat.total)}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
