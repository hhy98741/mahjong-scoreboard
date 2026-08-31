import { useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { navigate } from '../router.ts';
import { session } from '../store.ts';

export function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: Event): Promise<void> {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const user = await api.login(username, password);
      session.value = user;
      navigate('#/');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Login failed.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div class="centered-page">
      <form class="card" onSubmit={onSubmit}>
        <h2>Mahjong Scoreboard</h2>
        {error && <div class="form-error">{error}</div>}
        <div class="field">
          <label for="username">Username</label>
          <input
            id="username"
            value={username}
            onInput={(e) => setUsername((e.target as HTMLInputElement).value)}
            autoFocus
            required
          />
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input
            id="password"
            type="password"
            value={password}
            onInput={(e) => setPassword((e.target as HTMLInputElement).value)}
            required
          />
        </div>
        <div class="submit-row">
          <button type="submit" disabled={submitting}>
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </div>
      </form>
    </div>
  );
}
