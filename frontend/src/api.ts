// Typed fetch wrapper. Unwraps {ok,data}; throws ApiError on failure. Every
// write returns the whole resource — callers replace their store wholesale,
// they never patch it locally (docs-initial-build/04-frontend.md § State discipline).

import type {
  FeederStats,
  FlowMatrix,
  GameCreateRequest,
  GameCurve,
  GameStatePayload,
  GameStatus,
  GameSummary,
  HandRequest,
  LeaderboardRow,
  Player,
  PlayerCreateRequest,
  PlayerStats,
  PlayerUpdateRequest,
  RecordsBoard,
  Ruleset,
  RulesetRequest,
  SeatLuckRow,
  StatsFilters,
  User,
  UserCreateRequest,
  UserUpdateRequest,
  WinTypeStats,
} from './types.ts';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields?: Record<string, string>
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Set by store.ts so a 401 anywhere can clear the session and redirect. */
let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler;
}

interface Envelope<T> {
  ok: boolean;
  data?: T;
  error?: { code: string; message: string; fields?: Record<string, string> };
}

const STATE_CHANGING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

async function unwrap<T>(res: Response): Promise<T> {
  let payload: Envelope<T> | null = null;
  try {
    payload = (await res.json()) as Envelope<T>;
  } catch {
    // no/invalid JSON body — payload stays null, handled below
  }

  if (payload === null || typeof payload.ok !== 'boolean') {
    throw new ApiError(res.status, 'server_error', 'Malformed response from server.');
  }

  if (!payload.ok) {
    if (res.status === 401 && onUnauthorized) onUnauthorized();
    const error = payload.error;
    throw new ApiError(res.status, error?.code ?? 'server_error', error?.message ?? 'Unknown error.', error?.fields);
  }

  return payload.data as T;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const init: RequestInit = { method, credentials: 'same-origin' };
  // The API requires application/json on every state-changing request, even
  // a bodyless DELETE like undo — it's part of the CSRF defense in
  // app/Http/Middleware/Auth.php::checkContentType, not tied to there being
  // a JSON body to parse.
  if (body !== undefined || STATE_CHANGING.has(method)) {
    init.headers = { 'Content-Type': 'application/json' };
  }
  if (body !== undefined) {
    init.body = JSON.stringify(body);
  }

  return unwrap<T>(await fetch(path, init));
}

// Avatar upload is the one multipart/form-data route (docs-initial-build/03-api.md §
// Auth) — the browser sets its own Content-Type with the multipart
// boundary, so this bypasses request()'s JSON header instead of reusing it.
async function requestForm<T>(method: string, path: string, form: FormData): Promise<T> {
  return unwrap<T>(await fetch(path, { method, credentials: 'same-origin', body: form }));
}

function query(params?: Record<string, string | number | undefined>): string {
  if (!params) return '';
  const parts: string[] = [];
  for (const key in params) {
    const value = params[key];
    if (value !== undefined) parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  return parts.length > 0 ? `?${parts.join('&')}` : '';
}

// docs-initial-build/03-api.md § Stats: every stats endpoint takes the same four filters.
// player_count is left unset here when the caller omits it - the API itself
// defaults that to 4 (D25), so this only needs to forward an explicit choice.
function statsQuery(filters?: StatsFilters): string {
  if (!filters) return '';
  return query({
    from: filters.from,
    to: filters.to,
    player_ids: filters.player_ids && filters.player_ids.length > 0 ? filters.player_ids.join(',') : undefined,
    player_count: filters.player_count,
    include_abandoned: filters.include_abandoned ? 1 : undefined,
  });
}

export const api = {
  health: (): Promise<{ status: string; php: string }> => request('GET', '/api/health'),

  login: (username: string, password: string): Promise<User> =>
    request('POST', '/api/auth/login', { username, password }),
  logout: (): Promise<null> => request('POST', '/api/auth/logout'),
  me: (): Promise<User> => request('GET', '/api/auth/me'),
  changeMyPassword: (currentPassword: string, newPassword: string): Promise<null> =>
    request('PATCH', '/api/auth/password', { current_password: currentPassword, new_password: newPassword }),

  listUsers: (): Promise<User[]> => request('GET', '/api/users'),
  createUser: (body: UserCreateRequest): Promise<User> => request('POST', '/api/users', body),
  updateUser: (id: number, body: UserUpdateRequest): Promise<User> => request('PATCH', `/api/users/${id}`, body),
  resetUserPassword: (id: number, password: string): Promise<null> => request('POST', `/api/users/${id}/password`, { password }),

  players: (includeInactive = false): Promise<Player[]> =>
    request('GET', `/api/players${includeInactive ? '?include_inactive=1' : ''}`),
  createPlayer: (body: PlayerCreateRequest): Promise<Player> => request('POST', '/api/players', body),
  updatePlayer: (id: number, body: PlayerUpdateRequest): Promise<Player> => request('PATCH', `/api/players/${id}`, body),
  retirePlayer: (id: number): Promise<null> => request('DELETE', `/api/players/${id}`),
  uploadAvatar: (id: number, file: File): Promise<Player> => {
    const form = new FormData();
    form.append('avatar', file);
    return requestForm('POST', `/api/players/${id}/avatar`, form);
  },
  removeAvatar: (id: number): Promise<Player> => request('DELETE', `/api/players/${id}/avatar`),

  rulesets: (): Promise<Ruleset[]> => request('GET', '/api/rulesets'),
  createRuleset: (body: RulesetRequest): Promise<Ruleset> => request('POST', '/api/rulesets', body),
  updateRuleset: (id: number, body: RulesetRequest): Promise<Ruleset> => request('PUT', `/api/rulesets/${id}`, body),
  setDefaultRuleset: (id: number): Promise<Ruleset> => request('PATCH', `/api/rulesets/${id}/default`),
  deleteRuleset: (id: number): Promise<null> => request('DELETE', `/api/rulesets/${id}`),

  createGame: (body: GameCreateRequest): Promise<GameStatePayload> => request('POST', '/api/games', body),
  currentGame: (): Promise<GameStatePayload> => request('GET', '/api/games/current'),
  game: (id: number): Promise<GameStatePayload> => request('GET', `/api/games/${id}`),
  games: (filters?: {
    status?: string;
    from?: string;
    to?: string;
    player_id?: number;
    player_count?: number;
    limit?: number;
    offset?: number;
  }): Promise<GameSummary[]> => request('GET', `/api/games${query(filters)}`),

  recordHand: (gameId: number, hand: HandRequest): Promise<GameStatePayload> =>
    request('POST', `/api/games/${gameId}/hands`, hand),
  undoLastHand: (gameId: number): Promise<GameStatePayload> => request('DELETE', `/api/games/${gameId}/hands/last`),
  endGame: (gameId: number, status: Extract<GameStatus, 'completed' | 'abandoned'>): Promise<GameStatePayload> =>
    request('POST', `/api/games/${gameId}/end`, { status }),

  leaderboard: (filters?: StatsFilters): Promise<LeaderboardRow[]> => request('GET', `/api/stats/leaderboard${statsQuery(filters)}`),
  playerStats: (id: number, filters?: StatsFilters): Promise<PlayerStats> => request('GET', `/api/stats/players/${id}${statsQuery(filters)}`),
  flow: (filters?: StatsFilters): Promise<FlowMatrix> => request('GET', `/api/stats/flow${statsQuery(filters)}`),
  seats: (filters?: StatsFilters): Promise<SeatLuckRow[]> => request('GET', `/api/stats/seats${statsQuery(filters)}`),
  records: (filters?: StatsFilters): Promise<RecordsBoard> => request('GET', `/api/stats/records${statsQuery(filters)}`),
  feeders: (filters?: StatsFilters): Promise<FeederStats> => request('GET', `/api/stats/feeders${statsQuery(filters)}`),
  winTypes: (filters?: StatsFilters): Promise<WinTypeStats> => request('GET', `/api/stats/win-types${statsQuery(filters)}`),
  gameCurve: (gameId: number): Promise<GameCurve> => request('GET', `/api/stats/games/${gameId}/curve`),
};
