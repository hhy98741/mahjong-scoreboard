// #/game/:id — the main screen. docs/04-frontend.md § The Scoreboard.

import { useEffect, useState } from 'preact/hooks';
import { Confirm } from '../components/Confirm.tsx';
import { EntryBar } from '../components/EntryBar.tsx';
import { HandHistory } from '../components/HandHistory.tsx';
import { SeatingDiamond } from '../components/SeatingDiamond.tsx';
import { Standings } from '../components/Standings.tsx';
import { api, ApiError } from '../api.ts';
import { navigate } from '../router.ts';
import { currentGame, lang, theme } from '../store.ts';

interface ScoreboardProps {
  id: number;
}

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

export function Scoreboard({ id }: ScoreboardProps) {
  const [error, setError] = useState<string | null>(null);
  const [endConfirmOpen, setEndConfirmOpen] = useState(false);
  const [ending, setEnding] = useState(false);

  useEffect(() => {
    let cancelled = false;
    currentGame.value = null;
    setError(null);

    // Every read replaces the store wholesale — never patched locally
    // (docs/04-frontend.md § State discipline).
    api.game(id).then(
      (payload) => {
        if (!cancelled) currentGame.value = payload;
      },
      (err: unknown) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) {
          navigate('#/');
        } else {
          setError(err instanceof ApiError ? err.message : 'Failed to load game.');
        }
      }
    );

    return () => {
      cancelled = true;
    };
  }, [id]);

  const payload = currentGame.value;

  if (error) {
    return <div class="centered-page form-error">{error}</div>;
  }
  if (!payload || payload.game.id !== id) {
    return <div class="centered-page">Loading…</div>;
  }

  // Manual early finish (menu bar -> End game). "abandoned", not
  // "completed" — bin/verify.php asserts completed => replay says complete,
  // and a manual stop is by definition before that (.claude/memory).
  async function endGame(): Promise<void> {
    setEndConfirmOpen(false);
    setEnding(true);
    try {
      currentGame.value = await api.endGame(id, 'abandoned');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to end game.');
    } finally {
      setEnding(false);
    }
  }

  return (
    <div class="scoreboard-page">
      <header class="scoreboard-menu">
        <span class="game-name">{payload.game.name ?? `Game #${payload.game.id}`}</span>
        <nav>
          <a href="#/history">History</a>
          <a href="#/setup">Setup</a>
          <button onClick={cycleLang} title="Language">
            {lang.value === 'both' ? '中/EN' : lang.value === 'en' ? 'EN' : '中'}
          </button>
          <button onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}>
            {theme.value === 'dark' ? 'Light' : 'Dark'}
          </button>
          <button disabled={payload.game.status !== 'in_progress' || ending} onClick={() => setEndConfirmOpen(true)}>
            End game
          </button>
        </nav>
      </header>

      <div class="diamond-region">
        <SeatingDiamond
          seats={payload.seats}
          playerCount={payload.game.player_count}
          dealerWindIndex={payload.state.dealer_wind_index}
          roundWind={payload.state.round_wind}
          dealInRound={payload.state.deal_in_round}
          lang={lang.value}
        />
      </div>

      <div class="right-region">
        <Standings seats={payload.seats} />
        <HandHistory hands={payload.hands} seats={payload.seats} lang={lang.value} />
      </div>

      <div class="entry-region">
        <EntryBar payload={payload} lang={lang.value} />
      </div>

      {endConfirmOpen && (
        <Confirm
          message="End this game now? The game and its scores stay recorded, but it will be marked abandoned rather than completed."
          confirmLabel="End game"
          danger
          onConfirm={() => void endGame()}
          onCancel={() => setEndConfirmOpen(false)}
        />
      )}
    </div>
  );
}
