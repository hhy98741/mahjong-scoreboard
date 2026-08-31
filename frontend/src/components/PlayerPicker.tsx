// A row of seated-player buttons with avatars, used for the winner,
// discarder and 包 liable-player pickers in EntryBar.tsx — one click each,
// not a <select> (docs/04-frontend.md § Entry area).

import type { Seat } from '../types.ts';

interface PlayerPickerProps {
  seats: Seat[];
  exclude?: number | null;
  value: number | null;
  onSelect: (playerId: number) => void;
  label?: string;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  const second = parts[1];
  if (parts.length <= 1) return first.slice(0, 2).toUpperCase() || '?';
  return `${first.charAt(0)}${(second ?? '').charAt(0)}`.toUpperCase();
}

export function PlayerPicker({ seats, exclude, value, onSelect, label }: PlayerPickerProps) {
  const ordered = [...seats].sort((a, b) => a.chair - b.chair);
  return (
    <div class="player-picker" role="group" aria-label={label}>
      {ordered
        .filter((seat) => seat.player.id !== exclude)
        .map((seat) => {
          const isDefault = seat.player.avatar_url === '/default.svg';
          return (
            <button
              type="button"
              key={seat.player.id}
              class={`player-btn${value === seat.player.id ? ' selected' : ''}`}
              aria-pressed={value === seat.player.id}
              style={{ '--player-color': seat.player.color } as Record<string, string>}
              onClick={() => onSelect(seat.player.id)}
            >
              <span class="player-btn-avatar">
                <img src={seat.player.avatar_url} alt="" />
                {isDefault && <span class="player-btn-initials">{initials(seat.player.name)}</span>}
              </span>
              <span class="player-btn-name">{seat.player.name}</span>
            </button>
          );
        })}
    </div>
  );
}
