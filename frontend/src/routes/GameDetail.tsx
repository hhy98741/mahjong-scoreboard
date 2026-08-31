// #/history/game/:id — docs/04-frontend.md § Routes, docs/06-history-reports.md
// Tier 1 #2: the full hand-by-hand log (reusing the scoreboard's
// HandHistory component) plus a score curve — one line per seated player of
// cumulative points across hands, round boundaries marked.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { HandHistory } from '../components/HandHistory.tsx';
import { ScoreChart } from '../components/ScoreChart.tsx';
import { lang, theme } from '../store.ts';
import type { GameCurve, GameStatePayload } from '../types.ts';

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

interface GameDetailProps {
  id: number;
}

export function GameDetail({ id }: GameDetailProps) {
  const [game, setGame] = useState<GameStatePayload | null>(null);
  const [curve, setCurve] = useState<GameCurve | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setGame(null);
    setCurve(null);
    setError(null);

    Promise.all([api.game(id), api.gameCurve(id)])
      .then(([g, c]) => {
        if (cancelled) return;
        setGame(g);
        setCurve(c);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load game.');
      });

    return () => {
      cancelled = true;
    };
  }, [id]);

  return (
    <div class="history-page">
      <header class="top-toolbar">
        <h1>{game?.game.name ?? `Game #${id}`}</h1>
        <div class="toolbar-controls">
          <a href="#/history">History</a>
          <button onClick={cycleLang} title="Language">
            {lang.value === 'both' ? '中/EN' : lang.value === 'en' ? 'EN' : '中'}
          </button>
          <button onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}>
            {theme.value === 'dark' ? 'Light' : 'Dark'}
          </button>
        </div>
      </header>

      <div class="history-body">
        {error && <p class="form-error">{error}</p>}
        {!error && !game && <p class="text-dim">Loading…</p>}

        {game && (
          <>
            <p class="text-dim">
              {new Date(game.game.started_at).toLocaleString()} · {game.game.player_count} players · {game.game.status.replace('_', ' ')}
            </p>

            {curve && curve.points.length > 0 && (
              <section class="chart-section">
                <h2>Score curve</h2>
                <ScoreChart
                  series={curve.players.map((p) => ({
                    id: p.id,
                    name: p.name,
                    color: p.color,
                    points: curve.points.map((pt) => pt.totals[String(p.id)] ?? 0),
                  }))}
                  verticalLines={curve.round_boundaries.map((hn) => curve.points.findIndex((pt) => pt.hand_number === hn))}
                />
                <div class="chart-legend">
                  {curve.players.map((p) => (
                    <span key={p.id} style={{ color: p.color }}>
                      ● {p.name}
                    </span>
                  ))}
                </div>

                <div class="table-scroll">
                  <table class="curve-table">
                    <thead>
                      <tr>
                        <th>Hand</th>
                        {curve.players.map((p) => (
                          <th key={p.id} style={{ color: p.color }}>
                            {p.name}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {curve.points.map((pt) => (
                        <tr key={pt.hand_number}>
                          <td>#{pt.hand_number}</td>
                          {curve.players.map((p) => (
                            <td key={p.id}>{pt.totals[String(p.id)] ?? 0}</td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            )}

            <HandHistory hands={game.hands} seats={game.seats} lang={lang.value} />
          </>
        )}
      </div>
    </div>
  );
}
