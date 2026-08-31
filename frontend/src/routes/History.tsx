// #/history — docs/04-frontend.md § Routes ("Game list + reports"),
// docs/06-history-reports.md Tier 1 #1 and #3. Two tabs: the game list, and
// the all-time leaderboard (default sort: game win %, per D13/D25).

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { lang, theme } from '../store.ts';
import { StatsFilterBar } from '../components/StatsFilterBar.tsx';
import type { GameSummary, LeaderboardRow, StatsFilters } from '../types.ts';

type Tab = 'games' | 'leaderboard';
type SortKey = 'game_win_rate' | 'points_per_hand' | 'net_points' | 'games' | 'hands' | 'hand_win_rate' | 'avg_faan';

const MIN_GAMES_FOR_RATES = 5;

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${Math.round(rate * 100)}%`;
}

function formatSigned(n: number | null, decimals = 0): string {
  if (n === null) return '—';
  const rounded = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
  return n > 0 ? `+${rounded}` : rounded;
}

export function History() {
  const [tab, setTab] = useState<Tab>('games');
  const [filters, setFilters] = useState<StatsFilters>({ player_count: 4 });
  const [lastSessionDate, setLastSessionDate] = useState<string | null>(null);

  useEffect(() => {
    api
      .games({ limit: 1 })
      .then((rows) => setLastSessionDate(rows[0] ? rows[0].started_at.slice(0, 10) : null))
      .catch(() => {});
  }, []);

  return (
    <div class="history-page">
      <header class="top-toolbar">
        <h1>History</h1>
        <div class="toolbar-controls">
          <a href="#/">Home</a>
          <button onClick={cycleLang} title="Language">
            {lang.value === 'both' ? '中/EN' : lang.value === 'en' ? 'EN' : '中'}
          </button>
          <button onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}>
            {theme.value === 'dark' ? 'Light' : 'Dark'}
          </button>
        </div>
      </header>

      <div class="setup-tabs">
        <button type="button" class={tab === 'games' ? 'tab-active' : ''} aria-pressed={tab === 'games'} onClick={() => setTab('games')}>
          Games
        </button>
        <button
          type="button"
          class={tab === 'leaderboard' ? 'tab-active' : ''}
          aria-pressed={tab === 'leaderboard'}
          onClick={() => setTab('leaderboard')}
        >
          Leaderboard
        </button>
      </div>

      <div class="history-body">
        <StatsFilterBar value={filters} onChange={setFilters} lastSessionDate={lastSessionDate} />
        {tab === 'games' ? <GamesTab filters={filters} /> : <LeaderboardTab filters={filters} />}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Games tab

function summarizeMargin(game: GameSummary): { winnerName: string; margin: number } | null {
  const sorted = [...game.seats].sort((a, b) => b.total - a.total);
  const winner = sorted[0];
  if (!winner) return null;
  const runnerUp = sorted[1];
  return { winnerName: winner.player.name, margin: runnerUp ? winner.total - runnerUp.total : winner.total };
}

function duration(startedAt: string, endedAt: string | null): string {
  if (endedAt === null) return 'In progress';
  const ms = new Date(endedAt).getTime() - new Date(startedAt).getTime();
  const minutes = Math.max(0, Math.round(ms / 60000));
  if (minutes < 60) return `${minutes} min`;
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function GamesTab({ filters }: { filters: StatsFilters }) {
  const [games, setGames] = useState<GameSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setGames(null);
    api
      .games({
        from: filters.from,
        to: filters.to,
        player_count: filters.player_count === 'all' ? undefined : (filters.player_count ?? 4),
        limit: 100,
      })
      .then((rows) => {
        if (!cancelled) setGames(rows);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load games.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count]);

  if (error) return <p class="form-error">{error}</p>;
  if (games === null) return <p class="text-dim">Loading…</p>;
  if (games.length === 0) return <p class="text-dim">No games in this range.</p>;

  return (
    <ul class="game-list">
      {games.map((game) => {
        const summary = summarizeMargin(game);
        const ordered = [...game.seats].sort((a, b) => b.total - a.total);
        return (
          <li class="game-card" key={game.id}>
            <a href={`#/history/game/${game.id}`} class="game-card-link">
              <div class="game-card-header">
                <span class="game-card-date">{new Date(game.started_at).toLocaleDateString()}</span>
                {game.name && <span class="game-card-name">{game.name}</span>}
                <span class={`game-status-badge game-status-${game.status}`}>{game.status.replace('_', ' ')}</span>
              </div>
              <div class="game-card-seats">
                {ordered.map((seat, i) => (
                  <span key={seat.player.id} class={`game-card-seat ${i === 0 ? 'winner' : ''}`} style={{ color: seat.player.color }}>
                    {seat.player.name} {formatSigned(seat.total)}
                  </span>
                ))}
              </div>
              <div class="game-card-footer">
                <span>{game.player_count} players</span>
                <span>{duration(game.started_at, game.ended_at)}</span>
                {summary && <span>{summary.winnerName} won by {summary.margin}</span>}
              </div>
            </a>
          </li>
        );
      })}
    </ul>
  );
}

// ---------------------------------------------------------------- Leaderboard tab

function LeaderboardTab({ filters }: { filters: StatsFilters }) {
  const [rows, setRows] = useState<LeaderboardRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sortKey, setSortKey] = useState<SortKey>('game_win_rate');
  const [sortDesc, setSortDesc] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setRows(null);
    api
      .leaderboard(filters)
      .then((data) => {
        if (!cancelled) setRows(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load leaderboard.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (rows === null) return <p class="text-dim">Loading…</p>;
  if (rows.length === 0) return <p class="text-dim">No games in this range.</p>;

  function sortBy(key: SortKey): void {
    if (key === sortKey) {
      setSortDesc((d) => !d);
    } else {
      setSortKey(key);
      setSortDesc(true);
    }
  }

  const sorted = [...rows].sort((a, b) => {
    const av = a[sortKey] ?? -Infinity;
    const bv = b[sortKey] ?? -Infinity;
    return sortDesc ? (bv as number) - (av as number) : (av as number) - (bv as number);
  });

  const header = (key: SortKey, label: string) => (
    <th onClick={() => sortBy(key)} class={sortKey === key ? 'sorted' : ''}>
      {label}
      {sortKey === key ? (sortDesc ? ' ▼' : ' ▲') : ''}
    </th>
  );

  return (
    <div class="table-scroll">
      <table class="leaderboard-table">
        <thead>
          <tr>
            <th>Player</th>
            {header('games', 'Games')}
            {header('game_win_rate', 'Game win %')}
            {header('points_per_hand', 'Points/hand')}
            {header('hands', 'Hands')}
            {header('hand_win_rate', 'Hand win %')}
            {header('net_points', 'Net points')}
            {header('avg_faan', 'Avg 番')}
            <th>Best hand</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((row) => {
            const thin = row.games < MIN_GAMES_FOR_RATES;
            return (
              <tr key={row.player.id}>
                <td>
                  <a href={`#/history/player/${row.player.id}`} style={{ color: row.player.color }}>
                    {row.player.name}
                  </a>
                </td>
                <td>{row.games}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatRate(row.game_win_rate)}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatSigned(row.points_per_hand, 1)}</td>
                <td>{row.hands}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatRate(row.hand_win_rate)}</td>
                <td class={row.net_points < 0 ? 'negative' : 'positive'}>{formatSigned(row.net_points)}</td>
                <td>{row.avg_faan ?? '—'}</td>
                <td>
                  {row.best_hand ? (
                    <a href={`#/history/game/${row.best_hand.game_id}`}>{row.best_hand.faan}番</a>
                  ) : (
                    '—'
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
