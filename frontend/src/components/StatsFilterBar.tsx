// Shared date-range / player-count / abandoned-games filter row for every
// stats-backed screen (docs-initial-build/06-history-reports.md: "Every report accepts a
// date range... with quick presets: All time · This year · Last 90 days ·
// Last session"). player_count is always visible with its current value
// shown (D25) so nobody mistakes a filtered view for the whole record.

import type { StatsFilters } from '../types.ts';

interface StatsFilterBarProps {
  value: StatsFilters;
  onChange: (next: StatsFilters) => void;
  lastSessionDate: string | null; // most recent game's date (YYYY-MM-DD), for the "Last session" preset
}

type Preset = 'all' | 'year' | '90d' | 'session';

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

function presetRange(preset: Preset, lastSessionDate: string | null): { from?: string; to?: string } {
  const now = new Date();
  if (preset === 'all') return {};
  if (preset === 'year') return { from: `${now.getFullYear()}-01-01` };
  if (preset === '90d') {
    const from = new Date(now);
    from.setDate(from.getDate() - 90);
    return { from: isoDate(from) };
  }
  // 'session' — the calendar day of the most recently played game.
  if (lastSessionDate) return { from: lastSessionDate, to: lastSessionDate };
  return {};
}

export function StatsFilterBar({ value, onChange, lastSessionDate }: StatsFilterBarProps) {
  function setPreset(preset: Preset): void {
    onChange({ ...value, ...presetRange(preset, lastSessionDate) });
  }

  const playerCount = value.player_count ?? 4;

  return (
    <div class="stats-filter-bar">
      <div class="stats-filter-group">
        <button type="button" onClick={() => setPreset('all')}>
          All time
        </button>
        <button type="button" onClick={() => setPreset('year')}>
          This year
        </button>
        <button type="button" onClick={() => setPreset('90d')}>
          Last 90 days
        </button>
        <button type="button" disabled={!lastSessionDate} onClick={() => setPreset('session')}>
          Last session
        </button>
      </div>

      <label class="stats-filter-select">
        Player count
        <select
          value={String(playerCount)}
          onChange={(e) => {
            const raw = (e.target as HTMLSelectElement).value;
            onChange({ ...value, player_count: raw === 'all' ? 'all' : (Number(raw) as 2 | 3 | 4) });
          }}
        >
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4">4</option>
          <option value="all">All (blended)</option>
        </select>
      </label>

      <label class="stats-filter-checkbox">
        <input
          type="checkbox"
          checked={value.include_abandoned ?? false}
          onChange={(e) => onChange({ ...value, include_abandoned: (e.target as HTMLInputElement).checked })}
        />
        Include games ended early
      </label>
    </div>
  );
}
