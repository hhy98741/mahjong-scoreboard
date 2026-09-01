// #/setup — docs-initial-build/04-frontend.md § Setup. Two tabs: Players (cards, inline
// edit, avatar upload, retire) and Rulesets (the faan table editor with a
// live payout preview, fill helpers, duplicate).

import { useEffect, useState } from 'preact/hooks';
import { AvatarEditor, initials } from '../components/AvatarEditor.tsx';
import { Confirm } from '../components/Confirm.tsx';
import { PasswordRequirements } from '../components/PasswordRequirements.tsx';
import { api, ApiError } from '../api.ts';
import { usePlayerEditor } from '../hooks/usePlayerEditor.ts';
import { isPasswordValid } from '../passwordPolicy.ts';
import { navigate } from '../router.ts';
import { players, rulesets, session } from '../store.ts';
import type { Player, Ruleset, User } from '../types.ts';

type Tab = 'players' | 'rulesets' | 'users';

export function Setup() {
  const [tab, setTab] = useState<Tab>('players');
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [users, setUsers] = useState<User[]>([]);

  const isAdmin = session.value?.is_admin ?? false;

  useEffect(() => {
    let cancelled = false;
    Promise.all([api.players(true), api.rulesets(), isAdmin ? api.listUsers() : Promise.resolve([])])
      .then(([playerList, rulesetList, userList]) => {
        if (cancelled) return;
        players.value = playerList;
        rulesets.value = rulesetList;
        setUsers(userList);
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
  }, [isAdmin]);

  async function refreshPlayers(): Promise<void> {
    players.value = await api.players(true);
  }

  async function refreshRulesets(): Promise<void> {
    rulesets.value = await api.rulesets();
  }

  async function refreshUsers(): Promise<void> {
    setUsers(await api.listUsers());
  }

  return (
    <div class="setup-page">
      <div class="setup-tabs">
        <button type="button" class={tab === 'players' ? 'tab-active' : ''} aria-pressed={tab === 'players'} onClick={() => setTab('players')}>
          Players
        </button>
        <button type="button" class={tab === 'rulesets' ? 'tab-active' : ''} aria-pressed={tab === 'rulesets'} onClick={() => setTab('rulesets')}>
          Rulesets
        </button>
        {isAdmin && (
          <button type="button" class={tab === 'users' ? 'tab-active' : ''} aria-pressed={tab === 'users'} onClick={() => setTab('users')}>
            Users
          </button>
        )}
      </div>

      {loading && <div class="centered-page">Loading…</div>}
      {loadError && <div class="setup-body form-error">{loadError}</div>}
      {!loading && !loadError && tab === 'players' && (
        <PlayersTab onChange={refreshPlayers} users={isAdmin ? users : undefined} />
      )}
      {!loading && !loadError && tab === 'rulesets' && <RulesetsTab onChange={refreshRulesets} />}
      {!loading && !loadError && tab === 'users' && isAdmin && <UsersTab users={users} onChange={refreshUsers} />}
    </div>
  );
}

// ---------------------------------------------------------------- Players

interface PlayersTabProps {
  onChange: () => Promise<void>;
  users?: User[]; // present only for admins; enables the "linked login" picker.
}

function PlayersTab({ onChange, users }: PlayersTabProps) {
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
          <PlayerCard
            key={p.id}
            player={p}
            editing={editingId === p.id}
            onEdit={() => setEditingId(p.id)}
            onDone={closeEditor}
            onSaved={onChange}
            users={users}
          />
        ))}
      </div>

      {retiredPlayers.length > 0 && (
        <>
          <h3 class="setup-subheading">Retired</h3>
          <div class="player-grid">
            {retiredPlayers.map((p) => (
              <PlayerCard
                key={p.id}
                player={p}
                editing={editingId === p.id}
                onEdit={() => setEditingId(p.id)}
                onDone={closeEditor}
                onSaved={onChange}
                users={users}
              />
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
  onSaved: () => Promise<void>; // refreshes the list after an autosaved field, without closing the editor
  users?: User[]; // present only for admins; enables the "linked login" picker.
}

function PlayerCard({ player, editing, onEdit, onDone, onSaved, users }: PlayerCardProps) {
  const { name, setName, commitName, color, setColor, commitColor, preview, busy, error, uploadAvatar, removeAvatar, runAction } = usePlayerEditor(
    player,
    () => void onSaved(),
    editing
  );
  const [linkedUserId, setLinkedUserId] = useState<number | null>(player.user_id);

  useEffect(() => {
    setLinkedUserId(player.user_id);
  }, [player.id, editing]);

  async function commitLinkedUser(next: number | null): Promise<void> {
    setLinkedUserId(next);
    await runAction(() => api.updatePlayer(player.id, { user_id: next }), () => void onSaved(), 'Failed to update linked login.');
  }

  async function toggleActive(): Promise<void> {
    await runAction(
      () => (player.is_active ? api.retirePlayer(player.id) : api.updatePlayer(player.id, { is_active: true })),
      () => onDone(),
      'Failed to update player.'
    );
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
      <AvatarEditor
        id={`avatar-upload-${player.id}`}
        avatarUrl={player.avatar_url}
        preview={preview}
        color={color}
        name={name}
        busy={busy}
        onFileChange={(file) => void uploadAvatar(file)}
        onRemove={() => void removeAvatar()}
      />
      <div class="field">
        <label>Name</label>
        <input
          value={name}
          onInput={(e) => setName((e.target as HTMLInputElement).value)}
          onBlur={(e) => void commitName((e.target as HTMLInputElement).value)}
          disabled={busy}
        />
      </div>
      <div class="field">
        <label>Color</label>
        <input
          type="color"
          value={color}
          onInput={(e) => setColor((e.target as HTMLInputElement).value)}
          onChange={(e) => void commitColor((e.target as HTMLInputElement).value)}
          disabled={busy}
        />
      </div>
      {users && (
        <div class="field">
          <label>Linked login</label>
          <select
            value={linkedUserId ?? ''}
            onChange={(e) => {
              const v = (e.target as HTMLSelectElement).value;
              void commitLinkedUser(v === '' ? null : Number(v));
            }}
            disabled={busy}
          >
            <option value="">— none —</option>
            {users.map((u) => (
              <option key={u.id} value={u.id}>
                {u.username} — {u.display_name}
              </option>
            ))}
          </select>
        </div>
      )}
      <div class="modal-actions player-card-actions">
        <button type="button" class={player.is_active ? 'danger-btn' : ''} disabled={busy} onClick={() => void toggleActive()}>
          {player.is_active ? 'Retire' : 'Reactivate'}
        </button>
        <button type="button" class="primary-btn" disabled={busy} onClick={onDone}>
          Close
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Users (admin only, D29)

interface UsersTabProps {
  users: User[];
  onChange: () => Promise<void>;
}

function UsersTab({ users, onChange }: UsersTabProps) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const [resettingId, setResettingId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [newUsername, setNewUsername] = useState('');
  const [newDisplayName, setNewDisplayName] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newIsAdmin, setNewIsAdmin] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function createUser(e: Event): Promise<void> {
    e.preventDefault();
    if (newUsername.trim() === '' || newDisplayName.trim() === '' || !isPasswordValid(newPassword)) return;
    setBusy(true);
    setError(null);
    try {
      await api.createUser({
        username: newUsername.trim(),
        display_name: newDisplayName.trim(),
        password: newPassword,
        is_admin: newIsAdmin,
      });
      setNewUsername('');
      setNewDisplayName('');
      setNewPassword('');
      setNewIsAdmin(false);
      setCreating(false);
      await onChange();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create user.');
    } finally {
      setBusy(false);
    }
  }

  async function onSelfEdited(): Promise<void> {
    // D29: no server-side way to end this specific browser session other
    // than logging it out ourselves.
    await api.logout().catch(() => {});
    session.value = null;
    navigate('#/login');
  }

  // Only a successful save triggers the self-account force-logout — merely
  // opening then closing the editor with no changes must not log anyone out.
  async function onSaved(editedId: number): Promise<void> {
    setEditingId(null);
    if (editedId === session.value?.id) {
      await onSelfEdited();
      return;
    }
    await onChange();
  }

  async function closeResetPassword(resetId: number): Promise<void> {
    setResettingId(null);
    if (resetId === session.value?.id) {
      await onSelfEdited();
      return;
    }
  }

  return (
    <div class="setup-body">
      {error && <div class="form-error">{error}</div>}

      <div class="setup-toolbar">
        {!creating && (
          <button type="button" class="primary-btn" onClick={() => setCreating(true)}>
            + Add user
          </button>
        )}
        {creating && (
          <form class="inline-create-form user-create-form" onSubmit={(e) => void createUser(e)}>
            <input
              autoFocus
              placeholder="Username"
              value={newUsername}
              onInput={(e) => setNewUsername((e.target as HTMLInputElement).value)}
              disabled={busy}
            />
            <input
              placeholder="Display name"
              value={newDisplayName}
              onInput={(e) => setNewDisplayName((e.target as HTMLInputElement).value)}
              disabled={busy}
            />
            <input
              type="password"
              placeholder="Password"
              value={newPassword}
              onInput={(e) => setNewPassword((e.target as HTMLInputElement).value)}
              disabled={busy}
            />
            <PasswordRequirements password={newPassword} />
            <label class="user-admin-checkbox">
              <input type="checkbox" checked={newIsAdmin} onChange={(e) => setNewIsAdmin((e.target as HTMLInputElement).checked)} disabled={busy} />
              Admin
            </label>
            <button
              type="submit"
              class="primary-btn"
              disabled={busy || newUsername.trim() === '' || newDisplayName.trim() === '' || !isPasswordValid(newPassword)}
            >
              Add
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setCreating(false);
                setNewUsername('');
                setNewDisplayName('');
                setNewPassword('');
                setNewIsAdmin(false);
              }}
            >
              Cancel
            </button>
          </form>
        )}
      </div>

      <div class="user-list">
        {users.map((u) => (
          <UserRow
            key={u.id}
            user={u}
            editing={editingId === u.id}
            onEdit={() => setEditingId(u.id)}
            onSaved={() => void onSaved(u.id)}
            onClose={() => setEditingId(null)}
            onResetPassword={() => setResettingId(u.id)}
          />
        ))}
      </div>

      {resettingId !== null && (
        <ResetPasswordModal onDone={() => void closeResetPassword(resettingId)} onClose={() => setResettingId(null)} userId={resettingId} />
      )}
    </div>
  );
}

interface UserRowProps {
  user: User;
  editing: boolean;
  onEdit: () => void;
  onSaved: () => void;
  onClose: () => void;
  onResetPassword: () => void;
}

function UserRow({ user, editing, onEdit, onSaved, onClose, onResetPassword }: UserRowProps) {
  const [username, setUsername] = useState(user.username);
  const [displayName, setDisplayName] = useState(user.display_name);
  const [isAdmin, setIsAdmin] = useState(user.is_admin);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setUsername(user.username);
    setDisplayName(user.display_name);
    setIsAdmin(user.is_admin);
  }, [user.id, editing]);

  async function save(): Promise<void> {
    if (username.trim() === '' || displayName.trim() === '') {
      setError('Username and display name are required.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api.updateUser(user.id, { username: username.trim(), display_name: displayName.trim(), is_admin: isAdmin });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save.');
    } finally {
      setBusy(false);
    }
  }

  if (!editing) {
    return (
      <button type="button" class="user-row" onClick={onEdit}>
        <span class="user-row-username">{user.username}</span>
        <span class="user-row-display-name">{user.display_name}</span>
        {user.is_admin && <span class="user-row-admin-badge">Admin</span>}
      </button>
    );
  }

  return (
    <div class="user-row user-row-editing">
      {error && <div class="form-error">{error}</div>}
      <div class="field">
        <label>Username</label>
        <input value={username} onInput={(e) => setUsername((e.target as HTMLInputElement).value)} disabled={busy} />
      </div>
      <div class="field">
        <label>Display name</label>
        <input value={displayName} onInput={(e) => setDisplayName((e.target as HTMLInputElement).value)} disabled={busy} />
      </div>
      <label class="user-admin-checkbox">
        <input type="checkbox" checked={isAdmin} onChange={(e) => setIsAdmin((e.target as HTMLInputElement).checked)} disabled={busy} />
        Admin
      </label>
      <div class="modal-actions player-card-actions">
        <button type="button" disabled={busy} onClick={onResetPassword}>
          Reset password
        </button>
        <button type="button" disabled={busy} onClick={onClose}>
          Close
        </button>
        <button type="button" class="primary-btn" disabled={busy} onClick={() => void save()}>
          Save
        </button>
      </div>
    </div>
  );
}

interface ResetPasswordModalProps {
  userId: number;
  onDone: () => void;
  onClose: () => void;
}

function ResetPasswordModal({ userId, onDone, onClose }: ResetPasswordModalProps) {
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const valid = isPasswordValid(password) && password === confirmPassword;

  async function submit(): Promise<void> {
    if (!valid) {
      if (password !== confirmPassword) setError('Password and confirmation do not match.');
      else if (!isPasswordValid(password)) setError('Password does not meet the requirements below.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api.resetUserPassword(userId, password);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to reset password.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div class="modal-backdrop" onClick={onClose}>
      <div class="modal-card" onClick={(e) => e.stopPropagation()}>
        <h2>Reset password</h2>
        {error && <div class="form-error">{error}</div>}
        <div class="field">
          <label for="rp-new">New password</label>
          <input
            id="rp-new"
            autoFocus
            type="password"
            value={password}
            onInput={(e) => setPassword((e.target as HTMLInputElement).value)}
            disabled={busy}
          />
        </div>
        <PasswordRequirements password={password} />
        <div class="field">
          <label for="rp-confirm">Confirm new password</label>
          <input
            id="rp-confirm"
            type="password"
            value={confirmPassword}
            onInput={(e) => setConfirmPassword((e.target as HTMLInputElement).value)}
            disabled={busy}
          />
        </div>
        <div class="modal-actions">
          <button type="button" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" class="primary-btn" disabled={busy || !valid} onClick={() => void submit()}>
            Reset password
          </button>
        </div>
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

  async function makeDefault(): Promise<void> {
    if (!form?.id) return;
    setBusy(true);
    setError(null);
    try {
      const saved = await api.setDefaultRuleset(form.id);
      await onChange();
      setForm(toFormState(saved));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to set default ruleset.');
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
      // banner (docs-initial-build/04-frontend.md § modals use a z-indexed backdrop), so
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
            {form.id !== null && !rulesets.value.find((r) => r.id === form.id)?.is_default && (
              <button type="button" disabled={busy} onClick={() => void makeDefault()}>
                Make default
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
