// Shared name/color/avatar editing state for a Player, used by both Setup's
// PlayerCard and Profile's MyPlayerSection - the two places a player's own
// name, color and avatar get edited. Admin-only bits (linked login, retire)
// stay local to Setup, since Profile never touches them.
//
// Name and color autosave (no Save button): commitName() fires on blur and
// only hits the API if the trimmed value actually changed; commitColor()
// fires on the color input's `change` (the picker's final commit, not every
// drag tick that `input` events produce).

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import type { Player } from '../types.ts';

export function usePlayerEditor(player: Player, onSaved: (updated: Player) => void, resetKey?: unknown) {
  const [name, setName] = useState(player.name);
  const [color, setColor] = useState(player.color);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setName(player.name);
    setColor(player.color);
    setPreview(null);
  }, [player.id, resetKey]);

  useEffect(
    () => () => {
      if (preview) URL.revokeObjectURL(preview);
    },
    [preview]
  );

  // Shared busy/error bookkeeping around a single request. Exposed so a
  // caller can run an action this hook doesn't know about (e.g. Setup's
  // retire/reactivate, which isn't a plain updatePlayer) without duplicating
  // the busy/error dance.
  async function runAction<T>(action: () => Promise<T>, onSuccess: (result: T) => void, failureMessage: string): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      onSuccess(await action());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : failureMessage);
    } finally {
      setBusy(false);
    }
  }

  // Both commits take the value directly from the event rather than reading
  // the name/color state closures: the preceding `input` (live typing/drag
  // preview) and the committing blur/change can fire back-to-back with no
  // render in between, which would otherwise risk saving a stale value.
  async function commitName(raw: string): Promise<void> {
    const trimmed = raw.trim();
    if (trimmed === '') {
      setError('Name is required.');
      return;
    }
    if (trimmed !== raw) setName(trimmed);
    if (trimmed === player.name) return;
    await runAction(() => api.updatePlayer(player.id, { name: trimmed }), onSaved, 'Failed to save name.');
  }

  async function commitColor(next: string): Promise<void> {
    setColor(next);
    await runAction(() => api.updatePlayer(player.id, { color: next }), onSaved, 'Failed to save color.');
  }

  async function uploadAvatar(file: File): Promise<void> {
    setPreview(URL.createObjectURL(file));
    await runAction(() => api.uploadAvatar(player.id, file), onSaved, 'Failed to upload avatar.');
  }

  async function removeAvatar(): Promise<void> {
    await runAction(() => api.removeAvatar(player.id), onSaved, 'Failed to remove avatar.');
  }

  return { name, setName, commitName, color, setColor, commitColor, preview, busy, error, uploadAvatar, removeAvatar, runAction };
}
