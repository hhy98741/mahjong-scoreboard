// Mirrors the API payloads in docs/03-api.md. The API returns raw enum
// values only (docs/07-terminology.md § Backend) — translation happens here
// in the frontend, never on the wire.

export type GameStatus = 'in_progress' | 'completed' | 'abandoned';
export type Outcome = 'win' | 'draw' | 'penalty';
export type WinType = 'discard' | 'self_pick';
export type WindName = 'East' | 'South' | 'West' | 'North';

export interface User {
  id: number;
  username: string;
  display_name: string;
  is_admin: boolean;
}

export interface Player {
  id: number;
  name: string;
  color: string;
  avatar_url: string;
  is_active: boolean;
}

export interface Ruleset {
  id: number;
  name: string;
  table_max_faan: number;
  payment_rule: string;
  penalty_default: number;
  is_default: boolean;
  points: Record<string, number>;
}

// The player fields embedded in a seat or hand-history context — a subset
// of Player without is_active (GameService::playerPayload in app/Service).
export interface SeatPlayer {
  id: number;
  name: string;
  color: string;
  avatar_url: string;
}

export interface GameInfo {
  id: number;
  name: string | null;
  status: GameStatus;
  player_count: number;
  min_faan: number;
  max_faan: number;
  started_at: string;
  ended_at: string | null;
}

export interface RulesetSnapshot {
  name: string;
  table_max_faan: number;
  penalty_default: number;
  points: Record<string, number>;
}

export interface Seat {
  chair: number; // 0=East 1=South 2=West 3=North — fixed for the game
  player: SeatPlayer;
  current_wind_index: number; // (chair - dealer_wind_index + 4) % 4
  current_wind: WindName;
  total: number;
  rank: number; // 1-based, dense, ties share a rank
}

export interface GameStateInfo {
  round_wind: number;
  round_name: WindName;
  dealer_wind_index: number;
  dealer_player_id: number | null;
  deal_in_round: number; // 1-based position of the dealer within the occupied chairs
  next_hand_number: number;
  is_complete: boolean;
}

export interface Hand {
  id: number;
  hand_number: number;
  round_wind: number;
  dealer_wind_index: number;
  outcome: Outcome;
  winner_player_id: number | null;
  faan: number | null;
  win_type: WinType | null;
  discarder_player_id: number | null;
  liable_player_id: number | null;
  base_points: number | null;
  offender_player_id: number | null;
  penalty_per_player: number | null;
  note: string | null;
  scores: Record<string, number>; // player_id (as string key) -> points_delta
  created_at: string;
}

// GET /api/games/{id}, GET /api/games/current, and every write on a game —
// the frontend replaces its whole store with this on every response
// (docs/04-frontend.md § State discipline). Never patch it locally.
export interface GameStatePayload {
  game: GameInfo;
  ruleset: RulesetSnapshot;
  seats: Seat[];
  state: GameStateInfo;
  hands: Hand[];
}

export interface GameSummarySeat {
  chair: number;
  player: SeatPlayer;
  total: number;
}

// GET /api/games — summary rows only, no hands.
export interface GameSummary {
  id: number;
  name: string | null;
  status: GameStatus;
  player_count: number;
  started_at: string;
  ended_at: string | null;
  seats: GameSummarySeat[];
}

// POST /api/games/{id}/hands — three body shapes discriminated by outcome
// (docs/03-api.md § POST /api/games/{id}/hands).
export type HandRequest =
  | {
      outcome: 'win';
      winner_player_id: number;
      faan: number;
      win_type: WinType;
      discarder_player_id: number | null;
      liable_player_id: number | null;
      note: string | null;
    }
  | { outcome: 'draw'; note: string | null }
  | { outcome: 'penalty'; offender_player_id: number; penalty_per_player: number; note: string | null };

// POST /api/games — docs/03-api.md § Games. `seats` names the wind each
// player starts at; wind 0 (East) must be present.
export interface GameCreateRequest {
  ruleset_id: number;
  name: string | null;
  player_count: number;
  min_faan: number;
  max_faan: number;
  seats: { wind: number; player_id: number }[];
}

// POST/PATCH /api/players — docs/03-api.md § Players.
export interface PlayerCreateRequest {
  name: string;
  color?: string;
}

export interface PlayerUpdateRequest {
  name?: string;
  color?: string;
  is_active?: boolean;
}

// POST/PUT /api/rulesets — docs/03-api.md § Rulesets. `points` must carry a
// row for every faan 0..table_max_faan.
export interface RulesetRequest {
  name: string;
  table_max_faan: number;
  penalty_default: number;
  points: Record<string, number>;
}

// ---------------------------------------------------------------- stats
// GET /api/stats/* — docs/03-api.md § Stats, backing docs/06-history-reports.md.

// Shared filter shape every stats endpoint accepts. player_count defaults to
// 4 (D25) — the frontend must always show which count is in effect.
export interface StatsFilters {
  from?: string;
  to?: string;
  player_ids?: number[];
  player_count?: number | 'all';
  include_abandoned?: boolean;
}

export interface BestHand {
  hand_id: number;
  game_id: number;
  faan: number;
}

// GET /api/stats/leaderboard — one row per player over the selected range.
export interface LeaderboardRow {
  player: SeatPlayer;
  net_points: number;
  games: number;
  games_won: number;
  game_win_rate: number | null;
  hands: number;
  hands_won: number;
  hand_win_rate: number | null;
  points_per_hand: number | null;
  avg_faan: number | null;
  best_hand: BestHand | null;
}

export interface PointOverTime {
  game_id: number;
  hand_number: number;
  cumulative: number;
}

export interface FaanHistogramBucket {
  faan: number;
  count: number;
}

// GET /api/stats/players/{id} — the leaderboard row plus career series.
export interface PlayerStats extends LeaderboardRow {
  points_over_time: PointOverTime[];
  faan_histogram: FaanHistogramBucket[];
}

// GET /api/stats/games/{id}/curve — per-hand cumulative totals for the
// game-detail score curve.
export interface GameCurve {
  players: SeatPlayer[];
  points: { hand_number: number; totals: Record<string, number> }[];
  round_boundaries: number[];
}

// GET /api/stats/flow — docs/03-api.md § GET /api/stats/flow, backing
// docs/06-history-reports.md #5. `players` only lists players who appear on
// either side of at least one attributed transfer, ordered by id ascending.
// `matrix[i][j]` is the total net points `players[i]` paid to `players[j]`
// (always >= 0); the diagonal is always 0.
export interface FlowMatrix {
  players: SeatPlayer[];
  matrix: number[][];
}

// GET /api/stats/seats — docs/03-api.md § GET /api/stats/seats, backing
// docs/06-history-reports.md #6. One row per wind actually held (ascending
// by wind_index); at player_count < 4 not all four winds occur, so only
// winds with hands > 0 are present. wind_index 0 is always the dealer, so
// that row's win_rate doubles as the dealer win rate.
export interface SeatLuckRow {
  wind_index: number;
  wind_name: string;
  hands: number;
  net_points: number;
  hands_won: number;
  win_rate: number | null;
}

// GET /api/stats/records — docs/03-api.md § GET /api/stats/records, backing
// docs/06-history-reports.md #7. Eight fixed keys, each null when scope has
// no qualifying data. Every entry links to `game_id` (the frontend routes to
// `#/history/game/{game_id}`, same target as leaderboard.best_hand — there
// is no standalone per-hand route); hand-specific entries also carry
// `hand_id` for reference.
export interface HandRecord {
  player: SeatPlayer;
  game_id: number;
  hand_id: number;
}

export interface NightRecord {
  player: SeatPlayer;
  date: string;
  net_points: number;
  game_ids: number[];
}

export interface RecordsBoard {
  biggest_hand_points: (HandRecord & { points: number }) | null;
  biggest_hand_faan: (HandRecord & { faan: number }) | null;
  longest_win_streak: (HandRecord & { length: number }) | null;
  longest_drought: (HandRecord & { length: number }) | null;
  biggest_comeback: (HandRecord & { deficit: number }) | null;
  most_dealer_defences: (HandRecord & { defences: number }) | null;
  best_night: NightRecord | null;
  worst_night: NightRecord | null;
}

// GET /api/stats/feeders — docs/03-api.md § GET /api/stats/feeders, backing
// docs/06-history-reports.md #8. Per player, as the discarder: hands dealt
// into, points paid, and discard rate vs. the table average. Rows are
// ordered by discard_rate descending (null last).
export interface FeederRow {
  player: SeatPlayer;
  hands: number;
  discards: number;
  points_paid: number;
  discard_rate: number | null;
  vs_table_avg: number | null;
}

export interface FeederStats {
  table_avg_discard_rate: number | null;
  players: FeederRow[];
}

// GET /api/stats/win-types — docs/03-api.md § GET /api/stats/win-types,
// backing docs/06-history-reports.md #9. Bao stays split by win type: a
// discard bao always names the discarder as liable (rule 16), a self-pick
// bao names a different, already-on-the-hook player (rule 5b) — merging the
// two would hide the more interesting number. Rows ordered by
// self_pick_win_share descending (null last).
export interface BaoCounts {
  liable: number;
  won: number;
}

export interface WinTypeRow {
  player: SeatPlayer;
  hands: number;
  wins: number;
  self_pick_wins: number;
  discard_wins: number;
  self_pick_win_share: number | null;
  discard_bao: BaoCounts;
  self_pick_bao: BaoCounts;
}

export interface WinTypeStats {
  table_draw_rate: number | null;
  players: WinTypeRow[];
}
