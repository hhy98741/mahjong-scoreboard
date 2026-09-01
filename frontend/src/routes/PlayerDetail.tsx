// #/history/player/:id — docs-initial-build/06-history-reports.md Tier 1 #4: the
// leaderboard row for one person, plus a career line chart, a faan
// histogram, and recent form as +/- chips over the last 10 games.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { FaanHistogram } from '../components/FaanHistogram.tsx';
import { ScoreChart } from '../components/ScoreChart.tsx';
import { StatsFilterBar } from '../components/StatsFilterBar.tsx';
import { lang } from '../store.ts';
import type { GameSummary, PlayerStats, StatsFilters } from '../types.ts';

function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${Math.round(rate * 100)}%`;
}

function formatSigned(n: number | null, decimals = 0): string {
  if (n === null) return '—';
  const rounded = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
  return n > 0 ? `+${rounded}` : rounded;
}

interface PlayerDetailProps {
  id: number;
}

export function PlayerDetail({ id }: PlayerDetailProps) {
  const [filters, setFilters] = useState<StatsFilters>({ player_count: 4 });
  const [stats, setStats] = useState<PlayerStats | null>(null);
  const [recent, setRecent] = useState<GameSummary[] | null>(null);
  const [lastSessionDate, setLastSessionDate] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .games({ limit: 1 })
      .then((rows) => setLastSessionDate(rows[0] ? rows[0].started_at.slice(0, 10) : null))
      .catch(() => {});
  }, []);

  useEffect(() => {
    let cancelled = false;
    setStats(null);
    setError(null);

    api
      .playerStats(id, filters)
      .then((data) => {
        if (!cancelled) setStats(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? 'Player not found.' : 'Failed to load player.');
      });

    return () => {
      cancelled = true;
    };
  }, [id, filters.from, filters.to, filters.player_count, filters.include_abandoned]);

  useEffect(() => {
    let cancelled = false;
    api
      .games({ player_id: id, limit: 10 })
      .then((rows) => {
        if (!cancelled) setRecent(rows);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [id]);

  const player = stats?.player;

  return (
    <div class="history-page">
      <header class="top-toolbar">
        <h1 style={player ? { color: player.color } : undefined}>{player?.name ?? `Player #${id}`}</h1>
      </header>

      <div class="history-body">
        <StatsFilterBar value={filters} onChange={setFilters} lastSessionDate={lastSessionDate} />

        {error && <p class="form-error">{error}</p>}
        {!error && !stats && <p class="text-dim">Loading…</p>}

        {stats && (
          <>
            <dl class="stat-grid">
              <div>
                <dt>Games</dt>
                <dd>{stats.games}</dd>
              </div>
              <div>
                <dt>Game win %</dt>
                <dd>{formatRate(stats.game_win_rate)}</dd>
              </div>
              <div>
                <dt>Points/hand</dt>
                <dd>{formatSigned(stats.points_per_hand, 1)}</dd>
              </div>
              <div>
                <dt>Hands</dt>
                <dd>{stats.hands}</dd>
              </div>
              <div>
                <dt>Hand win %</dt>
                <dd>{formatRate(stats.hand_win_rate)}</dd>
              </div>
              <div>
                <dt>Net points</dt>
                <dd class={stats.net_points < 0 ? 'negative' : 'positive'}>{formatSigned(stats.net_points)}</dd>
              </div>
              <div>
                <dt>Avg 番</dt>
                <dd>{stats.avg_faan ?? '—'}</dd>
              </div>
              <div>
                <dt>Best hand</dt>
                <dd>
                  {stats.best_hand ? <a href={`#/history/game/${stats.best_hand.game_id}`}>{stats.best_hand.faan}番</a> : '—'}
                </dd>
              </div>
            </dl>

            {stats.points_over_time.length > 0 && (
              <section class="chart-section">
                <h2>Cumulative points over time</h2>
                <ScoreChart
                  series={[
                    {
                      id: stats.player.id,
                      name: stats.player.name,
                      color: stats.player.color,
                      points: stats.points_over_time.map((p) => p.cumulative),
                    },
                  ]}
                />
              </section>
            )}

            <section class="chart-section">
              <h2>Faan histogram</h2>
              <FaanHistogram buckets={stats.faan_histogram} color={stats.player.color} />
            </section>

            <section class="chart-section">
              <h2>Recent form</h2>
              {recent === null && <p class="text-dim">Loading…</p>}
              {recent !== null && recent.length === 0 && <p class="text-dim">No games yet.</p>}
              {recent !== null && recent.length > 0 && (
                <div class="recent-form-chips">
                  {recent.map((game) => {
                    const seat = game.seats.find((s) => s.player.id === id);
                    if (!seat) return null;
                    return (
                      <a
                        key={game.id}
                        href={`#/history/game/${game.id}`}
                        class={`recent-form-chip ${seat.total < 0 ? 'negative' : 'positive'}`}
                        title={new Date(game.started_at).toLocaleDateString()}
                      >
                        {formatSigned(seat.total)}
                      </a>
                    );
                  })}
                </div>
              )}
            </section>
          </>
        )}
      </div>
    </div>
  );
}
