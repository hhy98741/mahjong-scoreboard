// Entry area (bottom) — docs-initial-build/04-frontend.md § Entry area, § Keyboard
// shortcuts. Owns the whole data-entry region: winner/faan/win-type/包
// controls, draw, penalty modal, undo with confirmation, the keyboard
// scheme with its ? overlay, and (per "When state.is_complete...") the
// game-complete replacement of this same region.
//
// 包 is asymmetric by win type (D7b): a bare toggle on a discard win (the
// discarder is liable by definition, V11), a REQUIRED picker on a
// self-pick (there is no discarder to inherit, so Record stays disabled
// until one is named). Switching win type re-evaluates it.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { t } from '../i18n/terms.ts';
import type { Lang } from '../i18n/terms.ts';
import { currentGame } from '../store.ts';
import type { GameStatePayload, HandRequest } from '../types.ts';
import { Confirm } from './Confirm.tsx';
import { FaanPicker } from './FaanPicker.tsx';
import { KeyboardHelp } from './KeyboardHelp.tsx';
import { PenaltyModal } from './PenaltyModal.tsx';
import { PlayerPicker } from './PlayerPicker.tsx';

interface EntryBarProps {
  payload: GameStatePayload;
  lang: Lang;
}

type WinType = 'discard' | 'self_pick';

const WINNER_KEYS: Record<string, number> = { q: 0, w: 1, e: 2, r: 3 };
const DISCARD_KEYS: Record<string, number> = { a: 0, s: 1, d: 2, f: 3 };
const LIABLE_KEYS: Record<string, number> = { z: 0, x: 1, c: 2, v: 3 };
const DIGIT_COMBO_WINDOW_MS = 600;

function isTextInputFocused(): boolean {
  const el = document.activeElement as HTMLElement | null;
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

export function EntryBar({ payload, lang }: EntryBarProps) {
  const { game, ruleset, seats, state, hands } = payload;
  // Naturally finishing all four rounds sets state.is_complete; End game (menu
  // bar) sets game.status to 'abandoned' without necessarily finishing the
  // rounds. Either way there is nothing left to enter, so both swap in the
  // same completion banner.
  const isComplete = state.is_complete || game.status !== 'in_progress';

  const [winnerId, setWinnerId] = useState<number | null>(null);
  const [winType, setWinType] = useState<WinType | null>(null);
  const [discarderId, setDiscarderId] = useState<number | null>(null);
  const [selfPickLiableId, setSelfPickLiableId] = useState<number | null>(null);
  const [baoOn, setBaoOn] = useState(false);
  const [faan, setFaan] = useState<number | null>(null);
  const [pendingDigit, setPendingDigit] = useState<{ value: number; time: number } | null>(null);

  const [penaltyOpen, setPenaltyOpen] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  const [undoConfirmOpen, setUndoConfirmOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  function resetForm(): void {
    setWinnerId(null);
    setWinType(null);
    setDiscarderId(null);
    setSelfPickLiableId(null);
    setBaoOn(false);
    setFaan(null);
    setPendingDigit(null);
  }

  const nameOf = (id: number | null): string => (id === null ? '' : (seats.find((s) => s.player.id === id)?.player.name ?? `#${id}`));

  // At two players the discarder is the only opponent, so preselect them
  // (docs-initial-build/04-frontend.md § Keyboard shortcuts, § Entry area step 3).
  function autoPreselectDiscarder(winner: number): number | null {
    if (seats.length !== 2) return null;
    const other = seats.find((s) => s.player.id !== winner);
    return other ? other.player.id : null;
  }

  function selectWinner(id: number): void {
    setWinnerId(id);
    setDiscarderId(winType === 'discard' ? autoPreselectDiscarder(id) : null);
    setSelfPickLiableId(null);
    setBaoOn(false);
  }

  function selectSelfPick(): void {
    setWinType('self_pick');
    setDiscarderId(null);
    setSelfPickLiableId(null);
  }

  function selectDiscardType(): void {
    setWinType('discard');
    setSelfPickLiableId(null);
    if (discarderId === null && winnerId !== null) {
      const pre = autoPreselectDiscarder(winnerId);
      if (pre !== null) setDiscarderId(pre);
    }
  }

  function selectDiscarder(id: number): void {
    setWinType('discard');
    setDiscarderId(id);
    setSelfPickLiableId(null);
  }

  function selectLiable(id: number): void {
    setSelfPickLiableId(id);
    setBaoOn(true);
  }

  function toggleBao(): void {
    setBaoOn((prev) => {
      const next = !prev;
      if (!next) setSelfPickLiableId(null);
      return next;
    });
  }

  function onDigit(d: number): void {
    const now = Date.now();
    if (pendingDigit && now - pendingDigit.time <= DIGIT_COMBO_WINDOW_MS) {
      const combo = pendingDigit.value * 10 + d;
      if (combo >= game.min_faan && combo <= game.max_faan) setFaan(combo);
      setPendingDigit(null);
      return;
    }
    if (d >= game.min_faan && d <= game.max_faan) setFaan(d);
    setPendingDigit({ value: d, time: now });
  }

  const effectiveLiableId = !baoOn ? null : winType === 'discard' ? discarderId : selfPickLiableId;

  const canRecord =
    winnerId !== null &&
    faan !== null &&
    winType !== null &&
    (winType === 'discard' ? discarderId !== null : true) &&
    (winType === 'self_pick' && baoOn ? selfPickLiableId !== null : true);

  async function recordHand(): Promise<void> {
    if (!canRecord || winnerId === null || faan === null || winType === null || submitting) return;
    setSubmitting(true);
    setFormError(null);
    try {
      const body: HandRequest =
        winType === 'discard'
          ? {
              outcome: 'win',
              winner_player_id: winnerId,
              faan,
              win_type: 'discard',
              discarder_player_id: discarderId,
              liable_player_id: effectiveLiableId,
              note: null,
            }
          : {
              outcome: 'win',
              winner_player_id: winnerId,
              faan,
              win_type: 'self_pick',
              discarder_player_id: null,
              liable_player_id: effectiveLiableId,
              note: null,
            };
      currentGame.value = await api.recordHand(game.id, body);
      resetForm();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Failed to record hand.');
    } finally {
      setSubmitting(false);
    }
  }

  async function recordDraw(): Promise<void> {
    if (submitting) return;
    setSubmitting(true);
    setFormError(null);
    try {
      currentGame.value = await api.recordHand(game.id, { outcome: 'draw', note: null });
      resetForm();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Failed to record draw.');
    } finally {
      setSubmitting(false);
    }
  }

  async function recordPenalty(offenderId: number, pointsEach: number, note: string | null): Promise<void> {
    setSubmitting(true);
    setFormError(null);
    try {
      currentGame.value = await api.recordHand(game.id, {
        outcome: 'penalty',
        offender_player_id: offenderId,
        penalty_per_player: pointsEach,
        note,
      });
      setPenaltyOpen(false);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Failed to record penalty.');
    } finally {
      setSubmitting(false);
    }
  }

  async function confirmUndo(): Promise<void> {
    setUndoConfirmOpen(false);
    setSubmitting(true);
    setFormError(null);
    try {
      currentGame.value = await api.undoLastHand(game.id);
      resetForm();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Failed to undo.');
    } finally {
      setSubmitting(false);
    }
  }

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent): void {
      if (isTextInputFocused()) return;

      const key = e.key;
      const lower = key.length === 1 ? key.toLowerCase() : key;
      const isUndoChord = (e.ctrlKey || e.metaKey) && lower === 'z';

      // Modals capture Esc (and '?' for the help overlay) and swallow
      // everything else — no shortcut means two different things at once.
      if (helpOpen) {
        if (key === 'Escape' || key === '?') {
          e.preventDefault();
          setHelpOpen(false);
        }
        return;
      }
      if (penaltyOpen) {
        if (key === 'Escape') {
          e.preventDefault();
          setPenaltyOpen(false);
        }
        return;
      }
      if (undoConfirmOpen) {
        if (key === 'Escape') {
          e.preventDefault();
          setUndoConfirmOpen(false);
        }
        return;
      }

      if (key === '?' && !isComplete) {
        e.preventDefault();
        setHelpOpen(true);
        return;
      }
      if (isUndoChord) {
        e.preventDefault();
        if (hands.length > 0) setUndoConfirmOpen(true);
        return;
      }

      // Everything past this point records or edits the pending hand, which
      // has no meaning once the game is over — only Undo (Ctrl/Cmd+Z, no
      // visible button once complete) stays live.
      if (isComplete) return;

      if (key === 'Escape') {
        e.preventDefault();
        resetForm();
        setFormError(null);
        return;
      }
      if (key === 'Enter') {
        e.preventDefault();
        void recordHand();
        return;
      }
      if (lower === 'y') {
        e.preventDefault();
        void recordDraw();
        return;
      }
      if (lower === 'p') {
        e.preventDefault();
        setPenaltyOpen(true);
        return;
      }
      if (lower === 'b') {
        e.preventDefault();
        if (winType !== null) toggleBao();
        return;
      }
      if (lower === 'g') {
        e.preventDefault();
        selectSelfPick();
        return;
      }

      if (lower in WINNER_KEYS) {
        const chair = WINNER_KEYS[lower]!;
        const seat = seats.find((s) => s.chair === chair);
        if (seat) {
          e.preventDefault();
          selectWinner(seat.player.id);
        }
        return;
      }
      if (lower in DISCARD_KEYS) {
        const chair = DISCARD_KEYS[lower]!;
        const seat = seats.find((s) => s.chair === chair);
        // A key naming the winner's own chair is inert (V2: discarder != winner).
        if (seat && seat.player.id !== winnerId) {
          e.preventDefault();
          selectDiscarder(seat.player.id);
        }
        return;
      }
      if (lower in LIABLE_KEYS) {
        // Live on a self-pick only — inert on a discard win, where the
        // liable player isn't a question at all (it's the discarder, V11).
        if (winType !== 'self_pick') return;
        const chair = LIABLE_KEYS[lower]!;
        const seat = seats.find((s) => s.chair === chair);
        // A key naming the winner's own chair is inert (V3: liable != winner).
        if (seat && seat.player.id !== winnerId) {
          e.preventDefault();
          selectLiable(seat.player.id);
        }
        return;
      }

      if (/^[0-9]$/.test(key)) {
        e.preventDefault();
        onDigit(Number(key));
      }
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [
    seats,
    game.id,
    game.min_faan,
    game.max_faan,
    isComplete,
    hands,
    winnerId,
    winType,
    discarderId,
    selfPickLiableId,
    baoOn,
    faan,
    pendingDigit,
    penaltyOpen,
    helpOpen,
    undoConfirmOpen,
    submitting,
  ]);

  const lastHand = hands[0] ?? null;

  if (isComplete) {
    return (
      <div class="entry-bar game-complete-banner">
        {formError && <div class="entry-error">{formError}</div>}
        <div class="complete-message">
          {game.status === 'completed' ? 'Game complete' : 'Game ended'} — final standings above.
        </div>

        {undoConfirmOpen && lastHand && (
          <Confirm
            message={`Undo hand #${lastHand.hand_number}? This reopens the game.`}
            confirmLabel="Undo"
            danger
            onConfirm={confirmUndo}
            onCancel={() => setUndoConfirmOpen(false)}
          />
        )}
      </div>
    );
  }

  return (
    <div class="entry-bar">
      {formError && <div class="entry-error">{formError}</div>}

      <div class="entry-row entry-row-winner">
        <span class="entry-label">{t('win', lang)}</span>
        <PlayerPicker seats={seats} value={winnerId} onSelect={selectWinner} label="Winner" />
      </div>

      <div class="entry-row entry-row-faan">
        <span class="entry-label">{t('faan', lang)}</span>
        <FaanPicker minFaan={game.min_faan} maxFaan={game.max_faan} value={faan} onSelect={setFaan} lang={lang} />
      </div>

      <div class="entry-row entry-row-wintype">
        <label class="radio-label">
          <input type="radio" name="win-type" checked={winType === 'self_pick'} onChange={selectSelfPick} />
          {t('selfPick', lang)}
        </label>
        <label class="radio-label">
          <input type="radio" name="win-type" checked={winType === 'discard'} onChange={selectDiscardType} />
          {t('discard', lang)} by
        </label>
        {winType === 'discard' && (
          <PlayerPicker seats={seats} exclude={winnerId} value={discarderId} onSelect={selectDiscarder} label="Discarder" />
        )}
      </div>

      {winType !== null && (
        <div class="entry-row entry-row-bao">
          <label class="checkbox-label">
            <input type="checkbox" checked={baoOn} onChange={toggleBao} />
            {t('bao', lang)}
          </label>
          {winType === 'discard' && baoOn && discarderId !== null && (
            <span class="bao-consequence">
              {t('bao', lang)} — {nameOf(discarderId)} pays all
            </span>
          )}
          {winType === 'self_pick' && baoOn && (
            <>
              <span class="bao-arrow">pays all →</span>
              <PlayerPicker seats={seats} exclude={winnerId} value={selfPickLiableId} onSelect={selectLiable} label="Liable player" />
            </>
          )}
        </div>
      )}

      <div class="entry-row entry-row-actions">
        <button type="button" class="primary-btn record-btn" disabled={!canRecord || submitting} onClick={() => void recordHand()}>
          Record hand
        </button>
        <button type="button" onClick={() => void recordDraw()} disabled={submitting}>
          {t('draw', lang)}
        </button>
        <button type="button" onClick={() => setPenaltyOpen(true)} disabled={submitting}>
          {t('penalty', lang)}…
        </button>
        <button type="button" onClick={() => setHelpOpen(true)} title="Keyboard shortcuts">
          ?
        </button>
        <button type="button" class="undo-btn" disabled={!lastHand || submitting} onClick={() => setUndoConfirmOpen(true)}>
          ↶ Undo hand {lastHand?.hand_number ?? ''}
        </button>
      </div>

      {penaltyOpen && (
        <PenaltyModal
          seats={seats}
          penaltyDefault={ruleset.penalty_default}
          lang={lang}
          onSubmit={(offenderId, pointsEach, note) => void recordPenalty(offenderId, pointsEach, note)}
          onClose={() => setPenaltyOpen(false)}
        />
      )}

      {helpOpen && <KeyboardHelp onClose={() => setHelpOpen(false)} />}

      {undoConfirmOpen && lastHand && (
        <Confirm
          message={`Undo hand #${lastHand.hand_number}? This cannot be redone.`}
          confirmLabel="Undo"
          danger
          onConfirm={confirmUndo}
          onCancel={() => setUndoConfirmOpen(false)}
        />
      )}
    </div>
  );
}
