// #/history — docs-initial-build/04-frontend.md § Routes ("Game list + reports"),
// docs-initial-build/06-history-reports.md Tier 1 #1 and #3, plus Tier 2 #5-#9 (points flow
// matrix, seat luck, streaks & records, feeder stats, win-type split). Seven
// tabs: the game list, the all-time leaderboard (default sort: game win %,
// per D13/D25), and one tab per Tier 2 report above.

import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../api.ts';
import { lang } from '../store.ts';
import { StatsFilterBar } from '../components/StatsFilterBar.tsx';
import { gameStatusLabel } from '../types.ts';
import type { FeederStats, FlowMatrix, GameSummary, HandRecord, LeaderboardRow, RecordsBoard, SeatLuckRow, StatsFilters, WinTypeStats } from '../types.ts';

type Tab = 'games' | 'leaderboard' | 'flow' | 'seats' | 'records' | 'feeders' | 'winTypes';
type SortKey = 'game_win_rate' | 'points_per_hand' | 'net_points' | 'games' | 'hands' | 'hand_win_rate' | 'avg_faan';

const MIN_GAMES_FOR_RATES = 5;

function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${Math.round(rate * 100)}%`;
}

function formatSigned(n: number | null, decimals = 0): string {
  if (n === null) return '—';
  const rounded = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
  return n > 0 ? `+${rounded}` : rounded;
}

export function History() {
  const [tab, setTab] = useState<Tab>('games');
  const [filters, setFilters] = useState<StatsFilters>({ player_count: 'all', include_abandoned: true });
  const [lastSessionDate, setLastSessionDate] = useState<string | null>(null);

  useEffect(() => {
    api
      .games({ limit: 1 })
      .then((rows) => setLastSessionDate(rows[0] ? rows[0].started_at.slice(0, 10) : null))
      .catch(() => {});
  }, []);

  return (
    <div class="history-page">
      <div class="setup-tabs">
        <button type="button" class={tab === 'games' ? 'tab-active' : ''} aria-pressed={tab === 'games'} onClick={() => setTab('games')}>
          Games
        </button>
        <button
          type="button"
          class={tab === 'leaderboard' ? 'tab-active' : ''}
          aria-pressed={tab === 'leaderboard'}
          onClick={() => setTab('leaderboard')}
        >
          Leaderboard
        </button>
        <button type="button" class={tab === 'flow' ? 'tab-active' : ''} aria-pressed={tab === 'flow'} onClick={() => setTab('flow')}>
          Points flow
        </button>
        <button type="button" class={tab === 'seats' ? 'tab-active' : ''} aria-pressed={tab === 'seats'} onClick={() => setTab('seats')}>
          Seat luck
        </button>
        <button type="button" class={tab === 'records' ? 'tab-active' : ''} aria-pressed={tab === 'records'} onClick={() => setTab('records')}>
          Streaks &amp; records
        </button>
        <button type="button" class={tab === 'feeders' ? 'tab-active' : ''} aria-pressed={tab === 'feeders'} onClick={() => setTab('feeders')}>
          Feeder stats
        </button>
        <button type="button" class={tab === 'winTypes' ? 'tab-active' : ''} aria-pressed={tab === 'winTypes'} onClick={() => setTab('winTypes')}>
          Win types
        </button>
      </div>

      <div class="history-body">
        <StatsFilterBar value={filters} onChange={setFilters} lastSessionDate={lastSessionDate} />
        {tab === 'games' && <GamesTab filters={filters} />}
        {tab === 'leaderboard' && <LeaderboardTab filters={filters} />}
        {tab === 'flow' && <FlowTab filters={filters} />}
        {tab === 'seats' && <SeatsTab filters={filters} />}
        {tab === 'records' && <RecordsTab filters={filters} />}
        {tab === 'feeders' && <FeedersTab filters={filters} />}
        {tab === 'winTypes' && <WinTypesTab filters={filters} />}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Games tab

function summarizeMargin(game: GameSummary): { winnerName: string; margin: number } | null {
  const sorted = [...game.seats].sort((a, b) => b.total - a.total);
  const winner = sorted[0];
  if (!winner) return null;
  const runnerUp = sorted[1];
  return { winnerName: winner.player.name, margin: runnerUp ? winner.total - runnerUp.total : winner.total };
}

function duration(startedAt: string, endedAt: string | null): string {
  if (endedAt === null) return 'In progress';
  const ms = new Date(endedAt).getTime() - new Date(startedAt).getTime();
  const minutes = Math.max(0, Math.round(ms / 60000));
  if (minutes < 60) return `${minutes} min`;
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function GamesTab({ filters }: { filters: StatsFilters }) {
  const [games, setGames] = useState<GameSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setGames(null);
    api
      .games({
        from: filters.from,
        to: filters.to,
        player_count: filters.player_count === 'all' ? undefined : (filters.player_count ?? 4),
        limit: 100,
      })
      .then((rows) => {
        if (!cancelled) setGames(rows);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load games.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count]);

  if (error) return <p class="form-error">{error}</p>;
  if (games === null) return <p class="text-dim">Loading…</p>;
  if (games.length === 0) return <p class="text-dim">No games in this range.</p>;

  return (
    <ul class="game-list">
      {games.map((game) => {
        const summary = summarizeMargin(game);
        const ordered = [...game.seats].sort((a, b) => b.total - a.total);
        return (
          <li class="game-card" key={game.id}>
            <div class="game-card-header">
              <span class="game-card-date">
                {new Date(game.started_at).toLocaleDateString()}{' '}
                {new Date(game.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </span>
              {game.name && <span class="game-card-name">{game.name}</span>}
              <span class={`game-status-badge game-status-${game.status}`}>{gameStatusLabel(game.status)}</span>
            </div>
            <div class="game-card-seats">
              {ordered.map((seat, i) => (
                <span key={seat.player.id} class={`game-card-seat ${i === 0 ? 'winner' : ''}`} style={{ color: seat.player.color }}>
                  {seat.player.name} {game.starting_points + seat.total}
                </span>
              ))}
            </div>
            <div class="game-card-footer">
              <span>{game.player_count} players</span>
              <span>{duration(game.started_at, game.ended_at)}</span>
              {summary && <span>{summary.winnerName} won by {summary.margin}</span>}
            </div>
            <div class="game-card-actions">
              <a href={`#/history/game/${game.id}`} class="game-card-action">
                Report
              </a>
              <a href={`#/game/${game.id}`} class="game-card-action">
                Board
              </a>
            </div>
          </li>
        );
      })}
    </ul>
  );
}

// ---------------------------------------------------------------- Leaderboard tab

function LeaderboardTab({ filters }: { filters: StatsFilters }) {
  const [rows, setRows] = useState<LeaderboardRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sortKey, setSortKey] = useState<SortKey>('game_win_rate');
  const [sortDesc, setSortDesc] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setRows(null);
    api
      .leaderboard(filters)
      .then((data) => {
        if (!cancelled) setRows(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load leaderboard.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (rows === null) return <p class="text-dim">Loading…</p>;
  if (rows.length === 0) return <p class="text-dim">No games in this range.</p>;

  function sortBy(key: SortKey): void {
    if (key === sortKey) {
      setSortDesc((d) => !d);
    } else {
      setSortKey(key);
      setSortDesc(true);
    }
  }

  const sorted = [...rows].sort((a, b) => {
    const av = a[sortKey] ?? -Infinity;
    const bv = b[sortKey] ?? -Infinity;
    return sortDesc ? (bv as number) - (av as number) : (av as number) - (bv as number);
  });

  const header = (key: SortKey, label: string) => (
    <th onClick={() => sortBy(key)} class={sortKey === key ? 'sorted' : ''}>
      {label}
      {sortKey === key ? (sortDesc ? ' ▼' : ' ▲') : ''}
    </th>
  );

  return (
    <div class="table-scroll">
      <table class="leaderboard-table">
        <thead>
          <tr>
            <th>Player</th>
            {header('games', 'Games')}
            {header('game_win_rate', 'Game win %')}
            {header('points_per_hand', 'Points/hand')}
            {header('hands', 'Hands')}
            {header('hand_win_rate', 'Hand win %')}
            {header('net_points', 'Net points')}
            {header('avg_faan', 'Avg 番')}
            <th>Best hand</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((row) => {
            const thin = row.games < MIN_GAMES_FOR_RATES;
            return (
              <tr key={row.player.id}>
                <td>
                  <a href={`#/history/player/${row.player.id}`} style={{ color: row.player.color }}>
                    {row.player.name}
                  </a>
                </td>
                <td>{row.games}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatRate(row.game_win_rate)}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatSigned(row.points_per_hand, 1)}</td>
                <td>{row.hands}</td>
                <td class={thin ? 'thin-sample' : ''}>{formatRate(row.hand_win_rate)}</td>
                <td class={row.net_points < 0 ? 'negative' : 'positive'}>{formatSigned(row.net_points)}</td>
                <td>{row.avg_faan ?? '—'}</td>
                <td>
                  {row.best_hand ? (
                    <a href={`#/history/game/${row.best_hand.game_id}`}>{row.best_hand.faan}番</a>
                  ) : (
                    '—'
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

// ---------------------------------------------------------------- Flow tab

// docs-initial-build/06-history-reports.md #5: an N×N grid, net points transferred from
// the row player to the column player, colored diverging red/green. The raw
// matrix from GET /api/stats/flow is a one-directional "who paid whom"
// total (always >= 0); the diverging color instead reflects each pair's
// *net* direction — row[i][j] - row[j][i] — so a cell reads red when that
// player is the net payer in that pairing and green when they are the net
// receiver, which is the pattern the report is actually asking ("who keeps
// paying me?").
function FlowTab({ filters }: { filters: StatsFilters }) {
  const [data, setData] = useState<FlowMatrix | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setData(null);
    api
      .flow(filters)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load the flow matrix.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.player_ids, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (data === null) return <p class="text-dim">Loading…</p>;
  if (data.players.length < 2) return <p class="text-dim">Not enough attributed hands in this range.</p>;

  const { players, matrix } = data;

  function paid(i: number, j: number): number {
    return matrix[i]?.[j] ?? 0;
  }

  let maxAbsNet = 0;
  for (let i = 0; i < players.length; i++) {
    for (let j = 0; j < players.length; j++) {
      if (i === j) continue;
      maxAbsNet = Math.max(maxAbsNet, Math.abs(paid(i, j) - paid(j, i)));
    }
  }

  function cellStyle(i: number, j: number): { backgroundColor?: string } {
    if (i === j || maxAbsNet === 0) return {};
    const net = paid(i, j) - paid(j, i); // > 0: row is the net payer in this pair
    const pct = Math.round((Math.abs(net) / maxAbsNet) * 65);
    const tone = net > 0 ? 'var(--negative)' : net < 0 ? 'var(--positive)' : null;
    if (tone === null || pct === 0) return {};
    return { backgroundColor: `color-mix(in srgb, ${tone} ${pct}%, var(--surface))` };
  }

  return (
    <div class="table-scroll">
      <table class="flow-matrix-table">
        <caption class="text-dim">Rows pay columns. Red = net payer in that pairing, green = net receiver.</caption>
        <thead>
          <tr>
            <th class="flow-matrix-corner" />
            {players.map((p) => (
              <th key={p.id} style={{ color: p.color }} title={p.name}>
                {p.name}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {players.map((rowPlayer, i) => (
            <tr key={rowPlayer.id}>
              <th style={{ color: rowPlayer.color }}>{rowPlayer.name}</th>
              {players.map((colPlayer, j) => (
                <td
                  key={colPlayer.id}
                  class={i === j ? 'flow-matrix-diagonal' : ''}
                  style={cellStyle(i, j)}
                  title={i === j ? undefined : `${rowPlayer.name} paid ${colPlayer.name} ${paid(i, j)} points`}
                >
                  {i === j ? '—' : paid(i, j)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ---------------------------------------------------------------- Seats tab

// docs-initial-build/06-history-reports.md #6: net points and win rate grouped by the wind
// actually held when the hand was played — does East really win more?
// GET /api/stats/seats only returns winds that occurred (at player_count < 4
// at least one never does) and never invents a dealer field: wind_index 0 is
// always the dealer (East rotates with the deal), so its win_rate doubles as
// the dealer win rate, and this tab calls that row out separately rather
// than the API duplicating the number.
function SeatsTab({ filters }: { filters: StatsFilters }) {
  const [rows, setRows] = useState<SeatLuckRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setRows(null);
    api
      .seats(filters)
      .then((data) => {
        if (!cancelled) setRows(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load seat luck.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.player_ids, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (rows === null) return <p class="text-dim">Loading…</p>;
  if (rows.length === 0) return <p class="text-dim">No hands in this range.</p>;

  const dealerRow = rows.find((r) => r.wind_index === 0) ?? null;

  return (
    <div class="seats-report">
      {dealerRow && (
        <p class="seats-dealer-callout">
          Dealer win rate: <strong>{formatRate(dealerRow.win_rate)}</strong> ({dealerRow.hands_won} of {dealerRow.hands} hands
          held as dealer)
        </p>
      )}
      <div class="table-scroll">
        <table class="seats-table">
          <thead>
            <tr>
              <th>Wind</th>
              <th>Hands</th>
              <th>Hands won</th>
              <th>Win %</th>
              <th>Net points</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.wind_index}>
                <td class="seats-wind-cell">
                  {row.wind_name}
                  {row.wind_index === 0 && <span class="seats-dealer-badge">Dealer</span>}
                </td>
                <td>{row.hands}</td>
                <td>{row.hands_won}</td>
                <td>
                  <div class="seats-bar-cell">
                    <span class="seats-bar-track">
                      <span class="seats-bar-fill" style={{ width: `${Math.round((row.win_rate ?? 0) * 100)}%` }} />
                    </span>
                    {formatRate(row.win_rate)}
                  </div>
                </td>
                <td class={row.net_points < 0 ? 'negative' : 'positive'}>{formatSigned(row.net_points)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Records tab

// docs-initial-build/06-history-reports.md #7: a small board of superlatives, each linking
// to the hand or game it came from. GET /api/stats/records returns eight
// fixed keys, each null when scope has nothing to report — every non-null
// entry links to #/history/game/{game_id}, the same target
// leaderboard.best_hand already uses, since hand history renders on the
// game-detail page (there is no standalone per-hand route).
function RecordsTab({ filters }: { filters: StatsFilters }) {
  const [data, setData] = useState<RecordsBoard | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setData(null);
    api
      .records(filters)
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load records.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.player_ids, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (data === null) return <p class="text-dim">Loading…</p>;

  function card<K extends string>(
    title: string,
    description: string,
    record: (HandRecord & { [P in K]: number }) | null,
    valueKey: K,
    suffix = ''
  ) {
    return (
      <a href={record ? `#/history/game/${record.game_id}` : undefined} class={`record-card ${record ? '' : 'record-card-empty'}`}>
        <h3>{title}</h3>
        <p class="record-card-desc">{description}</p>
        {record ? (
          <>
            <div class="record-card-value">
              {record[valueKey]}
              {suffix}
            </div>
            <div class="record-card-player" style={{ color: record.player.color }}>
              {record.player.name}
            </div>
          </>
        ) : (
          <div class="record-card-value text-dim">—</div>
        )}
      </a>
    );
  }

  return (
    <div class="records-board">
      {card('Biggest hand (points)', 'Largest single-hand points gain', data.biggest_hand_points, 'points', ' pts')}
      {card('Biggest hand (番)', 'Highest faan on a single win', data.biggest_hand_faan, 'faan', '番')}
      {card('Longest win streak', 'Most consecutive hands won, one game', data.longest_win_streak, 'length', ' hands')}
      {card('Longest drought', 'Most consecutive hands without a win, one game', data.longest_drought, 'length', ' hands')}
      {card('Biggest comeback', 'Largest deficit overcome to still win the game', data.biggest_comeback, 'deficit', ' pts')}
      {card('Most dealer defences', 'Longest run holding the deal, one game', data.most_dealer_defences, 'defences', '')}

      <a
        href={data.best_night ? `#/history/game/${data.best_night.game_ids[data.best_night.game_ids.length - 1]}` : undefined}
        class={`record-card ${data.best_night ? '' : 'record-card-empty'}`}
      >
        <h3>Best night</h3>
        <p class="record-card-desc">Highest net points on one calendar day</p>
        {data.best_night ? (
          <>
            <div class="record-card-value positive">{formatSigned(data.best_night.net_points)}</div>
            <div class="record-card-player" style={{ color: data.best_night.player.color }}>
              {data.best_night.player.name} · {data.best_night.date}
            </div>
          </>
        ) : (
          <div class="record-card-value text-dim">—</div>
        )}
      </a>

      <a
        href={data.worst_night ? `#/history/game/${data.worst_night.game_ids[data.worst_night.game_ids.length - 1]}` : undefined}
        class={`record-card ${data.worst_night ? '' : 'record-card-empty'}`}
      >
        <h3>Worst night</h3>
        <p class="record-card-desc">Lowest net points on one calendar day</p>
        {data.worst_night ? (
          <>
            <div class="record-card-value negative">{formatSigned(data.worst_night.net_points)}</div>
            <div class="record-card-player" style={{ color: data.worst_night.player.color }}>
              {data.worst_night.player.name} · {data.worst_night.date}
            </div>
          </>
        ) : (
          <div class="record-card-value text-dim">—</div>
        )}
      </a>
    </div>
  );
}

// ---------------------------------------------------------------- Feeders tab

// docs-initial-build/06-history-reports.md #8: the complement of the win stats — per
// player, as the discarder, how often they dealt into someone else's win and
// how many points that cost, versus the table's overall rate. GET
// /api/stats/feeders already sorts by discard_rate descending (biggest
// feeder first) and hands out table_avg_discard_rate for the callout, so
// this tab renders the response as-is rather than re-sorting client-side.
function FeedersTab({ filters }: { filters: StatsFilters }) {
  const [data, setData] = useState<FeederStats | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setData(null);
    api
      .feeders(filters)
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load feeder stats.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.player_ids, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (data === null) return <p class="text-dim">Loading…</p>;
  if (data.players.length === 0) return <p class="text-dim">No hands in this range.</p>;

  return (
    <div class="seats-report">
      <p class="seats-dealer-callout">
        Table average discard rate: <strong>{formatRate(data.table_avg_discard_rate)}</strong>
      </p>
      <div class="table-scroll">
        <table class="seats-table">
          <thead>
            <tr>
              <th>Player</th>
              <th>Hands</th>
              <th>Dealt into</th>
              <th>Discard %</th>
              <th>Vs. table avg</th>
              <th>Points paid</th>
            </tr>
          </thead>
          <tbody>
            {data.players.map((row) => (
              <tr key={row.player.id}>
                <td>
                  <a href={`#/history/player/${row.player.id}`} style={{ color: row.player.color }}>
                    {row.player.name}
                  </a>
                </td>
                <td>{row.hands}</td>
                <td>{row.discards}</td>
                <td>
                  <div class="seats-bar-cell">
                    <span class="seats-bar-track">
                      <span class="seats-bar-fill" style={{ width: `${Math.round((row.discard_rate ?? 0) * 100)}%` }} />
                    </span>
                    {formatRate(row.discard_rate)}
                  </div>
                </td>
                <td class={row.vs_table_avg === null ? '' : row.vs_table_avg > 0 ? 'negative' : row.vs_table_avg < 0 ? 'positive' : ''}>
                  {row.vs_table_avg === null ? '—' : `${formatSigned(row.vs_table_avg * 100, 1)}pp`}
                </td>
                <td>{row.points_paid} pts</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Win types tab

// docs-initial-build/06-history-reports.md #9: self-pick vs discard as a share of each
// player's wins, plus a table-wide draw rate and 包 (bao) incidents. Bao
// stays split by win type rather than blended: a discard bao always names
// the discarder as liable (rule 16), a self-pick bao names a different,
// already-on-the-hook player (rule 5b) — the more interesting number of the
// pair. GET /api/stats/win-types already sorts by self_pick_win_share
// descending, so this tab renders the response as-is.
function BaoCell({ liable, won }: { liable: number; won: number }) {
  if (liable === 0 && won === 0) return <span class="text-dim">—</span>;
  return (
    <span title={`${liable} liable, ${won} won`}>
      {liable} liable · {won} won
    </span>
  );
}

function WinTypesTab({ filters }: { filters: StatsFilters }) {
  const [data, setData] = useState<WinTypeStats | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setData(null);
    api
      .winTypes(filters)
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Failed to load win-type split.');
      });
    return () => {
      cancelled = true;
    };
  }, [filters.from, filters.to, filters.player_count, filters.player_ids, filters.include_abandoned]);

  if (error) return <p class="form-error">{error}</p>;
  if (data === null) return <p class="text-dim">Loading…</p>;
  if (data.players.length === 0) return <p class="text-dim">No hands in this range.</p>;

  return (
    <div class="seats-report">
      <p class="seats-dealer-callout">
        Table draw rate: <strong>{formatRate(data.table_draw_rate)}</strong>
      </p>
      <div class="table-scroll">
        <table class="seats-table">
          <thead>
            <tr>
              <th>Player</th>
              <th>Wins</th>
              <th>Self-pick %</th>
              <th>Discard wins</th>
              <th>Discard 包</th>
              <th>Self-pick 包</th>
            </tr>
          </thead>
          <tbody>
            {data.players.map((row) => (
              <tr key={row.player.id}>
                <td>
                  <a href={`#/history/player/${row.player.id}`} style={{ color: row.player.color }}>
                    {row.player.name}
                  </a>
                </td>
                <td>{row.wins}</td>
                <td>
                  <div class="seats-bar-cell">
                    <span class="seats-bar-track">
                      <span class="seats-bar-fill" style={{ width: `${Math.round((row.self_pick_win_share ?? 0) * 100)}%` }} />
                    </span>
                    {formatRate(row.self_pick_win_share)} ({row.self_pick_wins})
                  </div>
                </td>
                <td>{row.discard_wins}</td>
                <td>
                  <BaoCell liable={row.discard_bao.liable} won={row.discard_bao.won} />
                </td>
                <td>
                  <BaoCell liable={row.self_pick_bao.liable} won={row.self_pick_bao.won} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
