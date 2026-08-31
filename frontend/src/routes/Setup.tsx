// #/setup — docs/04-frontend.md § Setup. Two tabs: Players (cards, inline
// edit, avatar upload, retire) and Rulesets (the faan table editor with a
// live payout preview, fill helpers, duplicate).

import { useEffect, useState } from 'preact/hooks';
import { Confirm } from '../components/Confirm.tsx';
import { api, ApiError } from '../api.ts';
import { lang, players, rulesets, theme } from '../store.ts';
import type { Player, Ruleset } from '../types.ts';

type Tab = 'players' | 'rulesets';

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

export function Setup() {
  const [tab, setTab] = useState<Tab>('players');
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([api.players(true), api.rulesets()])
      .then(([playerList, rulesetList]) => {
        if (cancelled) return;
        players.value = playerList;
        rulesets.value = rulesetList;
        setLoading(false);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoadError(err instanceof ApiError ? err.message : 'Failed to load.');
        setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function refreshPlayers(): Promise<void> {
    players.value = await api.players(true);
  }

  async function refreshRulesets(): Promise<void> {
    rulesets.value = await api.rulesets();
  }

  return (
    <div class="setup-page">
      <header class="top-toolbar">
        <h1>Setup</h1>
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
        <button type="button" class={tab === 'players' ? 'tab-active' : ''} aria-pressed={tab === 'players'} onClick={() => setTab('players')}>
          Players
        </button>
        <button type="button" class={tab === 'rulesets' ? 'tab-active' : ''} aria-pressed={tab === 'rulesets'} onClick={() => setTab('rulesets')}>
          Rulesets
        </button>
      </div>

      {loading && <div class="centered-page">Loading…</div>}
      {loadError && <div class="setup-body form-error">{loadError}</div>}
      {!loading && !loadError && tab === 'players' && <PlayersTab onChange={refreshPlayers} />}
      {!loading && !loadError && tab === 'rulesets' && <RulesetsTab onChange={refreshRulesets} />}
    </div>
  );
}

// ---------------------------------------------------------------- Players

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  const second = parts[1];
  if (parts.length <= 1) return first.slice(0, 2).toUpperCase() || '?';
  return `${first.charAt(0)}${(second ?? '').charAt(0)}`.toUpperCase();
}

interface PlayersTabProps {
  onChange: () => Promise<void>;
}

function PlayersTab({ onChange }: PlayersTabProps) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [newName, setNewName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function createPlayer(e: Event): Promise<void> {
    e.preventDefault();
    if (newName.trim() === '') return;
    setBusy(true);
    setError(null);
    try {
      await api.createPlayer({ name: newName.trim() });
      setNewName('');
      setCreating(false);
      await onChange();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create player.');
    } finally {
      setBusy(false);
    }
  }

  function closeEditor(): void {
    setEditingId(null);
    void onChange();
  }

  const activePlayers = players.value.filter((p) => p.is_active);
  const retiredPlayers = players.value.filter((p) => !p.is_active);

  return (
    <div class="setup-body">
      {error && <div class="form-error">{error}</div>}

      <div class="setup-toolbar">
        {!creating && (
          <button type="button" class="primary-btn" onClick={() => setCreating(true)}>
            + Add player
          </button>
        )}
        {creating && (
          <form class="inline-create-form" onSubmit={(e) => void createPlayer(e)}>
            <input
              autoFocus
              placeholder="Player name"
              value={newName}
              onInput={(e) => setNewName((e.target as HTMLInputElement).value)}
              disabled={busy}
            />
            <button type="submit" class="primary-btn" disabled={busy || newName.trim() === ''}>
              Add
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setCreating(false);
                setNewName('');
              }}
            >
              Cancel
            </button>
          </form>
        )}
      </div>

      {activePlayers.length === 0 && retiredPlayers.length === 0 && (
        <p class="form-hint">No players yet — add the people at your table above.</p>
      )}

      <div class="player-grid">
        {activePlayers.map((p) => (
          <PlayerCard key={p.id} player={p} editing={editingId === p.id} onEdit={() => setEditingId(p.id)} onDone={closeEditor} />
        ))}
      </div>

      {retiredPlayers.length > 0 && (
        <>
          <h3 class="setup-subheading">Retired</h3>
          <div class="player-grid">
            {retiredPlayers.map((p) => (
              <PlayerCard key={p.id} player={p} editing={editingId === p.id} onEdit={() => setEditingId(p.id)} onDone={closeEditor} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}

interface PlayerCardProps {
  player: Player;
  editing: boolean;
  onEdit: () => void;
  onDone: () => void;
}

function PlayerCard({ player, editing, onEdit, onDone }: PlayerCardProps) {
  const [name, setName] = useState(player.name);
  const [color, setColor] = useState(player.color);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setName(player.name);
    setColor(player.color);
    setPreview(null);
  }, [player.id, editing]);

  useEffect(
    () => () => {
      if (preview) URL.revokeObjectURL(preview);
    },
    [preview]
  );

  async function save(): Promise<void> {
    if (name.trim() === '') {
      setError('Name is required.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api.updatePlayer(player.id, { name: name.trim(), color });
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save.');
    } finally {
      setBusy(false);
    }
  }

  async function onFileChange(e: Event): Promise<void> {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    setPreview(URL.createObjectURL(file));
    setBusy(true);
    setError(null);
    try {
      await api.uploadAvatar(player.id, file);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to upload avatar.');
    } finally {
      setBusy(false);
    }
  }

  async function removeAvatar(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      await api.removeAvatar(player.id);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to remove avatar.');
    } finally {
      setBusy(false);
    }
  }

  async function toggleActive(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      if (player.is_active) {
        await api.retirePlayer(player.id);
      } else {
        await api.updatePlayer(player.id, { is_active: true });
      }
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to update player.');
    } finally {
      setBusy(false);
    }
  }

  if (!editing) {
    return (
      <button type="button" class={`player-card${player.is_active ? '' : ' retired'}`} onClick={onEdit}>
        <span class="player-card-avatar" style={{ '--player-color': player.color } as Record<string, string>}>
          <img src={player.avatar_url} alt="" />
          {player.avatar_url === '/default.svg' && <span class="player-card-initials">{initials(player.name)}</span>}
        </span>
        <span class="player-card-name">{player.name}</span>
        <span class="player-card-swatch" style={{ background: player.color }} />
        {!player.is_active && <span class="player-card-retired-badge">Retired</span>}
      </button>
    );
  }

  return (
    <div class="player-card player-card-editing">
      {error && <div class="form-error">{error}</div>}
      <span class="player-card-avatar" style={{ '--player-color': color } as Record<string, string>}>
        <img src={preview ?? player.avatar_url} alt="" />
        {!preview && player.avatar_url === '/default.svg' && <span class="player-card-initials">{initials(name)}</span>}
      </span>
      <div class="field">
        <label>Name</label>
        <input value={name} onInput={(e) => setName((e.target as HTMLInputElement).value)} disabled={busy} />
      </div>
      <div class="field">
        <label>Color</label>
        <input type="color" value={color} onInput={(e) => setColor((e.target as HTMLInputElement).value)} disabled={busy} />
      </div>
      <div class="field">
        <label>Avatar</label>
        <input type="file" accept="image/*" onChange={(e) => void onFileChange(e)} disabled={busy} />
        {player.avatar_url !== '/default.svg' && (
          <button type="button" onClick={() => void removeAvatar()} disabled={busy}>
            Remove avatar
          </button>
        )}
      </div>
      <div class="modal-actions player-card-actions">
        <button type="button" class={player.is_active ? 'danger-btn' : ''} disabled={busy} onClick={() => void toggleActive()}>
          {player.is_active ? 'Retire' : 'Reactivate'}
        </button>
        <button type="button" disabled={busy} onClick={onDone}>
          Close
        </button>
        <button type="button" class="primary-btn" disabled={busy} onClick={() => void save()}>
          Save
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Rulesets

interface RulesetFormState {
  id: number | null; // null = creating new
  name: string;
  tableMaxFaan: number;
  penaltyDefault: number;
  points: Record<number, number>;
}

function pointsFromRuleset(r: Ruleset): Record<number, number> {
  const points: Record<number, number> = {};
  for (let f = 0; f <= r.table_max_faan; f++) points[f] = r.points[String(f)] ?? 0;
  return points;
}

function toFormState(r: Ruleset): RulesetFormState {
  return { id: r.id, name: r.name, tableMaxFaan: r.table_max_faan, penaltyDefault: r.penalty_default, points: pointsFromRuleset(r) };
}

function blankFormState(): RulesetFormState {
  const points: Record<number, number> = {};
  for (let f = 0; f <= 13; f++) points[f] = 0;
  return { id: null, name: '', tableMaxFaan: 13, penaltyDefault: 128, points };
}

interface RulesetsTabProps {
  onChange: () => Promise<void>;
}

function RulesetsTab({ onChange }: RulesetsTabProps) {
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [form, setForm] = useState<RulesetFormState | null>(null);
  const [linearStep, setLinearStep] = useState('2');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);

  useEffect(() => {
    if (selectedId === null) return;
    const r = rulesets.value.find((x) => x.id === selectedId);
    if (r) setForm(toFormState(r));
    // Only re-syncs when the selection itself changes — a save() applies
    // the freshly returned ruleset to `form` directly, so this doesn't need
    // to react to every rulesets.value refresh too.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedId]);

  function selectRuleset(id: number): void {
    setSelectedId(id);
    setError(null);
    setDeleteConfirm(false);
  }

  function startCreate(): void {
    setSelectedId(null);
    setForm(blankFormState());
    setError(null);
    setDeleteConfirm(false);
  }

  function duplicate(): void {
    if (!form) return;
    setSelectedId(null);
    setForm({ ...form, id: null, name: `${form.name} copy` });
    setError(null);
  }

  function setTableMaxFaan(n: number): void {
    setForm((prev) => {
      if (!prev) return prev;
      const points: Record<number, number> = {};
      for (let f = 0; f <= n; f++) points[f] = prev.points[f] ?? 0;
      return { ...prev, tableMaxFaan: n, points };
    });
  }

  function setBasePoints(faan: number, value: number): void {
    setForm((prev) => (prev ? { ...prev, points: { ...prev.points, [faan]: value } } : prev));
  }

  function fillByDoubling(): void {
    setForm((prev) => {
      if (!prev) return prev;
      const seed = Math.max(1, prev.points[0] ?? 1);
      const points: Record<number, number> = {};
      for (let f = 0; f <= prev.tableMaxFaan; f++) points[f] = seed * 2 ** f;
      return { ...prev, points };
    });
  }

  function fillLinear(): void {
    const step = Number(linearStep);
    if (!Number.isFinite(step) || step < 0) return;
    setForm((prev) => {
      if (!prev) return prev;
      const base = prev.points[0] ?? 0;
      const points: Record<number, number> = {};
      for (let f = 0; f <= prev.tableMaxFaan; f++) points[f] = base + step * f;
      return { ...prev, points };
    });
  }

  async function save(): Promise<void> {
    if (!form) return;
    if (form.name.trim() === '') {
      setError('Name is required.');
      return;
    }
    const points: Record<string, number> = {};
    for (let f = 0; f <= form.tableMaxFaan; f++) points[String(f)] = Math.max(0, Math.trunc(form.points[f] ?? 0));

    setBusy(true);
    setError(null);
    try {
      const body = { name: form.name.trim(), table_max_faan: form.tableMaxFaan, penalty_default: form.penaltyDefault, points };
      const saved = form.id === null ? await api.createRuleset(body) : await api.updateRuleset(form.id, body);
      await onChange();
      setSelectedId(saved.id);
      setForm(toFormState(saved));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save ruleset.');
    } finally {
      setBusy(false);
    }
  }

  async function remove(): Promise<void> {
    if (!form?.id) return;
    setBusy(true);
    setError(null);
    try {
      await api.deleteRuleset(form.id);
      setDeleteConfirm(false);
      setSelectedId(null);
      setForm(null);
      await onChange();
    } catch (err) {
      // Close the confirm dialog on failure too — it sits above the error
      // banner (docs/04-frontend.md § modals use a z-indexed backdrop), so
      // leaving it open would hide the very message explaining why nothing
      // happened.
      setDeleteConfirm(false);
      setError(err instanceof ApiError ? err.message : 'Failed to delete ruleset.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div class="setup-body ruleset-layout">
      <div class="ruleset-list">
        {rulesets.value.map((r) => (
          <button
            type="button"
            key={r.id}
            class={`ruleset-list-item${form?.id === r.id ? ' selected' : ''}`}
            onClick={() => selectRuleset(r.id)}
          >
            {r.name}
            {r.is_default && <span class="ruleset-default-badge">Default</span>}
          </button>
        ))}
        <button type="button" class="primary-btn" onClick={startCreate}>
          + New ruleset
        </button>
      </div>

      {form && (
        <div class="ruleset-editor card">
          {error && <div class="form-error">{error}</div>}

          <div class="field">
            <label for="ruleset-name">Name</label>
            <input id="ruleset-name" value={form.name} onInput={(e) => setForm({ ...form, name: (e.target as HTMLInputElement).value })} />
          </div>

          <div class="ruleset-editor-row">
            <div class="field">
              <label for="ruleset-max-faan">Table extends to</label>
              <select
                id="ruleset-max-faan"
                value={form.tableMaxFaan}
                onChange={(e) => setTableMaxFaan(Number((e.target as HTMLSelectElement).value))}
              >
                {Array.from({ length: 14 }, (_, i) => i).map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </div>
            <div class="field">
              <label for="ruleset-penalty">Penalty default (points each)</label>
              <input
                id="ruleset-penalty"
                type="number"
                min={0}
                step={1}
                value={form.penaltyDefault}
                onInput={(e) => setForm({ ...form, penaltyDefault: Number((e.target as HTMLInputElement).value) })}
              />
            </div>
          </div>

          <p class="form-hint">The selectable 番 range is not here — it is chosen per game on New game.</p>

          <table class="points-table">
            <thead>
              <tr>
                <th>番</th>
                <th>Base points</th>
                <th>Winner receives (4 players)</th>
              </tr>
            </thead>
            <tbody>
              {Array.from({ length: form.tableMaxFaan + 1 }, (_, f) => f).map((faan) => {
                const base = form.points[faan] ?? 0;
                return (
                  <tr key={faan}>
                    <td>{faan}</td>
                    <td>
                      <input
                        type="number"
                        min={0}
                        step={1}
                        value={base}
                        onInput={(e) => setBasePoints(faan, Number((e.target as HTMLInputElement).value))}
                      />
                    </td>
                    <td class="points-preview">
                      出銃 {base * 4} / 自摸 {base * 6}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>

          <div class="ruleset-fill-row">
            <button type="button" onClick={fillByDoubling}>
              Fill by doubling
            </button>
            <label class="fill-linear-label">
              Fill linear:
              <input
                class="fill-linear-input"
                type="number"
                min={0}
                step={1}
                value={linearStep}
                onInput={(e) => setLinearStep((e.target as HTMLInputElement).value)}
              />
              per 番
            </label>
            <button type="button" onClick={fillLinear}>
              Apply
            </button>
            <button type="button" onClick={duplicate} disabled={form.id === null}>
              Duplicate ruleset
            </button>
          </div>

          <div class="modal-actions">
            {form.id !== null && (
              <button type="button" class="danger-btn" disabled={busy} onClick={() => setDeleteConfirm(true)}>
                Delete
              </button>
            )}
            <button type="button" class="primary-btn" disabled={busy} onClick={() => void save()}>
              {form.id === null ? 'Create ruleset' : 'Save'}
            </button>
          </div>

          {form.id !== null && <p class="form-hint">Existing games are unaffected by edits here — each holds its own snapshot.</p>}

          {deleteConfirm && form.id !== null && (
            <Confirm
              message={`Delete "${form.name}"? Games already played keep their own snapshot and are unaffected.`}
              confirmLabel="Delete"
              danger
              onConfirm={() => void remove()}
              onCancel={() => setDeleteConfirm(false)}
            />
          )}
        </div>
      )}
    </div>
  );
}
