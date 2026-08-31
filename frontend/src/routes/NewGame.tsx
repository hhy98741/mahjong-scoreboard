// #/new — docs-initial-build/04-frontend.md § New game. Every per-game decision lives
// here, in order: player count, ruleset, faan range, seats. All four wind
// rows are always shown and always fully selectable, whatever the player
// count — East+North must be exactly as easy to pick as East+West, so no
// row is ever disabled and the "usual" tag below is a hint only, never a
// restriction.

import { useEffect, useMemo, useState } from 'preact/hooks';
import { SeatingDiamond } from '../components/SeatingDiamond.tsx';
import { api, ApiError } from '../api.ts';
import { navigate } from '../router.ts';
import { t } from '../i18n/terms.ts';
import type { TermKey } from '../i18n/terms.ts';
import { currentGame, lang, players, rulesets } from '../store.ts';
import type { Seat, WindName } from '../types.ts';

type Chair = 0 | 1 | 2 | 3;
const CHAIRS: readonly Chair[] = [0, 1, 2, 3];
const WIND_NAMES: Record<Chair, WindName> = { 0: 'East', 1: 'South', 2: 'West', 3: 'North' };
const WIND_TERM_KEYS: Record<Chair, TermKey> = { 0: 'east', 1: 'south', 2: 'west', 3: 'north' };

// 2 -> East+West, 3 -> East+South+West, 4 -> all four (docs-initial-build/04-frontend.md
// § New game). Pre-filled as a hint only — every row stays fully selectable
// regardless of player_count.
const DEFAULT_WINDS: Record<number, readonly Chair[]> = {
  2: [0, 2],
  3: [0, 1, 2],
  4: [0, 1, 2, 3],
};

type Winds = Record<Chair, number | null>;

export function NewGame() {
  const [checking, setChecking] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [playerCount, setPlayerCount] = useState(4);
  const [rulesetId, setRulesetId] = useState<number | null>(null);
  const [minFaan, setMinFaan] = useState(2);
  const [maxFaan, setMaxFaan] = useState(8);
  const [winds, setWinds] = useState<Winds>({ 0: null, 1: null, 2: null, 3: null });
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    // This route only exists when no game is live — a stale tab landing
    // here anyway gets redirected straight to it, same as Home
    // (docs-initial-build/04-frontend.md § Home).
    api.currentGame().then(
      (game) => {
        if (!cancelled) navigate(`#/game/${game.game.id}`);
      },
      (err: unknown) => {
        if (cancelled) return;
        if (!(err instanceof ApiError && err.status === 404)) {
          setLoadError(err instanceof ApiError ? err.message : 'Failed to load.');
          setChecking(false);
          return;
        }
        Promise.all([api.players(), api.rulesets()])
          .then(([playerList, rulesetList]) => {
            if (cancelled) return;
            players.value = playerList;
            rulesets.value = rulesetList;
            const defaultRuleset = rulesetList.find((r) => r.is_default) ?? rulesetList[0] ?? null;
            if (defaultRuleset) {
              setRulesetId(defaultRuleset.id);
              setMaxFaan(Math.min(8, defaultRuleset.table_max_faan));
            }
            setChecking(false);
          })
          .catch((e: unknown) => {
            if (cancelled) return;
            setLoadError(e instanceof ApiError ? e.message : 'Failed to load.');
            setChecking(false);
          });
      }
    );

    return () => {
      cancelled = true;
    };
  }, []);

  const selectedRuleset = rulesets.value.find((r) => r.id === rulesetId) ?? null;
  const tableMaxFaan = selectedRuleset?.table_max_faan ?? 13;

  function selectRuleset(id: number): void {
    setRulesetId(id);
    const r = rulesets.value.find((rs) => rs.id === id);
    if (!r) return;
    setMinFaan((prev) => Math.min(prev, r.table_max_faan));
    setMaxFaan((prev) => Math.min(prev, r.table_max_faan));
  }

  function selectMinFaan(value: number): void {
    setMinFaan(value);
    setMaxFaan((prev) => Math.max(prev, value));
  }

  function selectMaxFaan(value: number): void {
    setMaxFaan(value);
    setMinFaan((prev) => Math.min(prev, value));
  }

  function usedElsewhere(wind: Chair): Set<number> {
    const used = new Set<number>();
    for (const chair of CHAIRS) {
      const playerId = winds[chair];
      if (chair !== wind && playerId !== null) used.add(playerId);
    }
    return used;
  }

  function setWind(wind: Chair, playerId: number | null): void {
    setWinds((prev) => ({ ...prev, [wind]: playerId }));
  }

  const filledWinds = CHAIRS.filter((w) => winds[w] !== null);
  const eastFilled = winds[0] !== null;
  const surplus = filledWinds.length - playerCount;
  const canStart = eastFilled && filledWinds.length === playerCount && rulesetId !== null && minFaan <= maxFaan && !submitting;

  const previewSeats: Seat[] = useMemo(
    () =>
      filledWinds.flatMap((wind): Seat[] => {
        const playerId = winds[wind];
        const player = playerId === null ? undefined : players.value.find((p) => p.id === playerId);
        if (!player) return [];
        return [
          {
            chair: wind,
            player: { id: player.id, name: player.name, color: player.color, avatar_url: player.avatar_url },
            current_wind_index: wind, // dealer_wind_index is always 0 before the first hand
            current_wind: WIND_NAMES[wind],
            total: 0,
            rank: 1,
          },
        ];
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [winds, players.value]
  );

  async function onSubmit(e: Event): Promise<void> {
    e.preventDefault();
    if (!canStart || rulesetId === null) return;
    setSubmitting(true);
    setSubmitError(null);
    try {
      const payload = await api.createGame({
        ruleset_id: rulesetId,
        name: null,
        player_count: playerCount,
        min_faan: minFaan,
        max_faan: maxFaan,
        seats: filledWinds.map((wind) => ({ wind, player_id: winds[wind]! })),
      });
      currentGame.value = payload;
      navigate(`#/game/${payload.game.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // Another tab started a game first — treat it as a redirect rather
        // than an error (docs-initial-build/04-frontend.md § Home).
        api
          .currentGame()
          .then((g) => navigate(`#/game/${g.game.id}`))
          .catch(() => {});
        return;
      }
      setSubmitError(err instanceof ApiError ? err.message : 'Failed to start game.');
    } finally {
      setSubmitting(false);
    }
  }

  if (checking) {
    return <div class="centered-page">Loading…</div>;
  }
  if (loadError) {
    return <div class="centered-page form-error">{loadError}</div>;
  }

  return (
    <div class="setup-page">
      <header class="top-toolbar">
        <h1>New game</h1>
        <div class="toolbar-controls">
          <a href="#/">Home</a>
          <a href="#/setup">Setup</a>
        </div>
      </header>

      <form class="new-game-layout" onSubmit={(e) => void onSubmit(e)}>
        <div class="new-game-form">
          <div class="field">
            <label>Players</label>
            <div class="radio-group">
              {[2, 3, 4].map((n) => (
                <label class="radio-label" key={n}>
                  <input type="radio" name="player-count" checked={playerCount === n} onChange={() => setPlayerCount(n)} />
                  {n}
                </label>
              ))}
            </div>
          </div>

          <div class="field">
            <label for="ruleset-select">Ruleset</label>
            <select
              id="ruleset-select"
              value={rulesetId ?? ''}
              onChange={(e) => selectRuleset(Number((e.target as HTMLSelectElement).value))}
            >
              {rulesets.value.length === 0 && <option value="">No rulesets available</option>}
              {rulesets.value.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                  {r.is_default ? ' (default)' : ''}
                </option>
              ))}
            </select>
          </div>

          <div class="field">
            <label>番 range</label>
            <div class="faan-range-row">
              <select value={minFaan} onChange={(e) => selectMinFaan(Number((e.target as HTMLSelectElement).value))}>
                {Array.from({ length: tableMaxFaan + 1 }, (_, i) => i).map((f) => (
                  <option key={f} value={f}>
                    {f}
                  </option>
                ))}
              </select>
              <span>to</span>
              <select value={maxFaan} onChange={(e) => selectMaxFaan(Number((e.target as HTMLSelectElement).value))}>
                {Array.from({ length: tableMaxFaan + 1 }, (_, i) => i).map((f) => (
                  <option key={f} value={f}>
                    {f}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div class="field">
            <label>Seats — pick who sits where. East is required.</label>
            <div class="seat-rows">
              {CHAIRS.map((wind) => {
                const isUsual = DEFAULT_WINDS[playerCount]?.includes(wind) ?? false;
                const options = players.value.filter((p) => !usedElsewhere(wind).has(p.id));
                return (
                  <div class="seat-row" key={wind}>
                    <span class="seat-row-label">
                      {t(WIND_TERM_KEYS[wind], lang.value)}
                      {wind === 0 && <span class="seat-row-required"> *</span>}
                      {isUsual && <span class="seat-row-usual"> (usual)</span>}
                    </span>
                    <select
                      value={winds[wind] ?? ''}
                      onChange={(e) => {
                        const v = (e.target as HTMLSelectElement).value;
                        setWind(wind, v === '' ? null : Number(v));
                      }}
                    >
                      <option value="">-- empty --</option>
                      {options.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name}
                        </option>
                      ))}
                    </select>
                  </div>
                );
              })}
            </div>
            {!eastFilled && <p class="form-hint">East must be filled — the opening dealer sits there.</p>}
            {eastFilled && surplus > 0 && (
              <p class="form-hint">
                {filledWinds.length} seats filled, this game needs exactly {playerCount}. Clear {surplus} to continue.
              </p>
            )}
            {eastFilled && surplus < 0 && (
              <p class="form-hint">
                {filledWinds.length} of {playerCount} seats filled.
              </p>
            )}
          </div>

          {submitError && <div class="form-error">{submitError}</div>}

          <div class="submit-row">
            <button type="submit" disabled={!canStart}>
              {submitting ? 'Starting…' : 'Start game'}
            </button>
          </div>
        </div>

        <div class="new-game-preview">
          <SeatingDiamond seats={previewSeats} playerCount={playerCount} dealerWindIndex={0} roundWind={0} dealInRound={1} lang={lang.value} />
        </div>
      </form>
    </div>
  );
}
