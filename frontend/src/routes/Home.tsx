// docs-initial-build/04-frontend.md § Home. On mount, redirect straight to the live game
// if one exists — a table night reopens this screen constantly and every
// one of those should land back on the scoreboard with no click.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { navigate } from '../router.ts';
import type { GameSummary } from '../types.ts';

function summarizeRecent(game: GameSummary): string {
  const sorted = [...game.seats].sort((a, b) => b.total - a.total);
  const winner = sorted[0];
  if (!winner) return '';
  const runnerUp = sorted[1];
  const margin = runnerUp ? winner.total - runnerUp.total : winner.total;
  return `${winner.player.name} won by ${margin}`;
}

export function Home() {
  const [checking, setChecking] = useState(true);
  const [recentGames, setRecentGames] = useState<GameSummary[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    api.currentGame().then(
      (game) => {
        if (!cancelled) navigate(`#/game/${game.game.id}`);
      },
      (err: unknown) => {
        if (cancelled) return;
        setChecking(false);
        if (err instanceof ApiError && err.status === 404) {
          api
            .games({ status: 'completed', limit: 5 })
            .then((rows) => {
              if (!cancelled) setRecentGames(rows);
            })
            .catch(() => {});
        } else {
          setError(err instanceof ApiError ? err.message : 'Failed to load.');
        }
      }
    );

    return () => {
      cancelled = true;
    };
  }, []);

  if (checking) {
    return <div class="centered-page">Loading…</div>;
  }

  return (
    <div class="home-page">
      {error && <p class="form-error">{error}</p>}
      <a class="home-new-game" href="#/new">
        + New game
      </a>
      {recentGames.length > 0 && (
        <div class="home-recent">
          <div class="home-recent-header">
            <h2 class="home-recent-heading">Recent games</h2>
            <a href="#/history">View all history →</a>
          </div>
          <ul class="home-recent-list">
            {recentGames.map((game) => (
              <li key={game.id}>
                <a href={`#/history/game/${game.id}`}>
                  {new Date(game.started_at).toLocaleDateString()} — {summarizeRecent(game)}
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
