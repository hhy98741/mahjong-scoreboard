// docs/04-frontend.md § Home. On mount, redirect straight to the live game
// if one exists — a table night reopens this screen constantly and every
// one of those should land back on the scoreboard with no click.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { navigate } from '../router.ts';
import { lang, session, theme } from '../store.ts';
import type { GameSummary } from '../types.ts';

function summarizeRecent(game: GameSummary): string {
  const sorted = [...game.seats].sort((a, b) => b.total - a.total);
  const winner = sorted[0];
  if (!winner) return '';
  const runnerUp = sorted[1];
  const margin = runnerUp ? winner.total - runnerUp.total : winner.total;
  return `${winner.player.name} won by ${margin}`;
}

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

export function Home() {
  const [checking, setChecking] = useState(true);
  const [recent, setRecent] = useState<GameSummary | null>(null);
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
            .games({ status: 'completed', limit: 1 })
            .then((rows) => {
              if (!cancelled) setRecent(rows[0] ?? null);
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

  async function logout(): Promise<void> {
    await api.logout().catch(() => {});
    session.value = null;
    navigate('#/login');
  }

  if (checking) {
    return <div class="centered-page">Loading…</div>;
  }

  return (
    <div>
      <header class="top-toolbar">
        <h1>Mahjong Scoreboard</h1>
        <div class="toolbar-controls">
          <button onClick={cycleLang} title="Language">
            {lang.value === 'both' ? '中/EN' : lang.value === 'en' ? 'EN' : '中'}
          </button>
          <button onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}>
            {theme.value === 'dark' ? 'Light' : 'Dark'}
          </button>
          <button onClick={logout}>Log out</button>
        </div>
      </header>

      <div class="home-page">
        {error && <p class="form-error">{error}</p>}
        <div class="home-targets">
          <a class="home-target" href="#/new">
            New game
          </a>
          <a class="home-target" href="#/history">
            History
          </a>
          <a class="home-target" href="#/setup">
            Setup
          </a>
        </div>
        {recent && (
          <p class="home-recent">
            Last game:{' '}
            <a href={`#/history/game/${recent.id}`}>
              {new Date(recent.started_at).toLocaleDateString()} — {summarizeRecent(recent)}
            </a>
          </p>
        )}
      </div>
    </div>
  );
}
