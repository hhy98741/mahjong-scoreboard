// #/profile - account info, language/theme preference, change-my-password,
// and (D29) editing your own linked player's avatar/color/name, if this
// login is linked to one. Reached from AppNav's Profile item.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { AvatarEditor } from '../components/AvatarEditor.tsx';
import { PasswordRequirements } from '../components/PasswordRequirements.tsx';
import { usePlayerEditor } from '../hooks/usePlayerEditor.ts';
import { isPasswordValid } from '../passwordPolicy.ts';
import { navigate } from '../router.ts';
import { lang, session, theme } from '../store.ts';
import type { Player } from '../types.ts';

function cycleLang(): void {
  lang.value = lang.value === 'both' ? 'en' : lang.value === 'en' ? 'zh' : 'both';
}

export function Profile() {
  const user = session.value;
  const [myPlayer, setMyPlayer] = useState<Player | null | undefined>(undefined); // undefined = loading
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    if (!user) return;
    let cancelled = false;
    api
      .players(true)
      .then((all) => {
        if (cancelled) return;
        setMyPlayer(all.find((p) => p.user_id === user.id) ?? null);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoadError(err instanceof ApiError ? err.message : 'Failed to load.');
        setMyPlayer(null);
      });
    return () => {
      cancelled = true;
    };
  }, [user?.id]);

  async function logout(): Promise<void> {
    await api.logout().catch(() => {});
    session.value = null;
    navigate('#/login');
  }

  if (!user) return null; // main.tsx only renders this route once logged in

  return (
    <div class="profile-page">
      <header class="top-toolbar">
        <h1>Profile</h1>
      </header>

      <div class="profile-body">
        {loadError && <div class="form-error">{loadError}</div>}

        <div class={`profile-columns${myPlayer == null ? ' profile-columns-single' : ''}`}>
          {myPlayer != null && (
            <div class="profile-col-left">
              <MyPlayerSection player={myPlayer} onSaved={setMyPlayer} />
            </div>
          )}

          <div class="profile-col-right">
            <section class="card">
              <h2>Account</h2>
              <p class="profile-field">
                <span class="profile-field-label">Display name</span> {user.display_name}
              </p>
              <p class="profile-field">
                <span class="profile-field-label">Username</span> {user.username}
              </p>
              {user.is_admin && (
                <p class="profile-field">
                  <span class="profile-field-label">Role</span> Admin
                </p>
              )}
              <button type="button" onClick={() => void logout()}>
                Log out
              </button>
            </section>

            <section class="card">
              <h2>Preferences</h2>
              <div class="profile-prefs">
                <button type="button" onClick={cycleLang} title="Language">
                  Language: {lang.value === 'both' ? '中/EN' : lang.value === 'en' ? 'EN' : '中'}
                </button>
                <button type="button" onClick={() => (theme.value = theme.value === 'dark' ? 'light' : 'dark')}>
                  Theme: {theme.value === 'dark' ? 'Dark' : 'Light'}
                </button>
              </div>
            </section>

            <ChangePasswordSection />
          </div>
        </div>
      </div>
    </div>
  );
}

function ChangePasswordSection() {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const valid = currentPassword !== '' && isPasswordValid(newPassword) && newPassword === confirmPassword;

  async function submit(e: Event): Promise<void> {
    e.preventDefault();
    if (!valid) {
      if (newPassword !== confirmPassword) setError('New password and confirmation do not match.');
      else if (!isPasswordValid(newPassword)) setError('New password does not meet the requirements below.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api.changeMyPassword(currentPassword, newPassword);
      // D29: no server-side way to end this specific browser session other
      // than logging it out ourselves.
      await api.logout().catch(() => {});
      session.value = null;
      navigate('#/login');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to change password.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section class="card">
      <h2>Change password</h2>
      {error && <div class="form-error">{error}</div>}
      <form onSubmit={(e) => void submit(e)}>
        <div class="field">
          <label for="cp-current">Current password</label>
          <input
            id="cp-current"
            type="password"
            value={currentPassword}
            onInput={(e) => setCurrentPassword((e.target as HTMLInputElement).value)}
            disabled={busy}
          />
        </div>
        <div class="field">
          <label for="cp-new">New password</label>
          <input id="cp-new" type="password" value={newPassword} onInput={(e) => setNewPassword((e.target as HTMLInputElement).value)} disabled={busy} />
        </div>
        <PasswordRequirements password={newPassword} />
        <div class="field">
          <label for="cp-confirm">Confirm new password</label>
          <input
            id="cp-confirm"
            type="password"
            value={confirmPassword}
            onInput={(e) => setConfirmPassword((e.target as HTMLInputElement).value)}
            disabled={busy}
          />
        </div>
        <button type="submit" class="primary-btn" disabled={busy || !valid}>
          Change password
        </button>
      </form>
    </section>
  );
}

interface MyPlayerSectionProps {
  player: Player;
  onSaved: (updated: Player) => void;
}

function MyPlayerSection({ player, onSaved }: MyPlayerSectionProps) {
  const { name, setName, commitName, color, setColor, commitColor, preview, busy, error, uploadAvatar, removeAvatar } = usePlayerEditor(
    player,
    onSaved
  );

  return (
    <section class="card">
      <h2>My player</h2>
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
    </section>
  );
}
