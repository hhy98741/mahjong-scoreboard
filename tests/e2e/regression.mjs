#!/usr/bin/env node
// UI-driven regression smoke test — docs-initial-build/PLAN.md's ten phases are all built;
// this exercises the real frontend (clicks + keyboard shortcuts, not the API
// directly) end to end: Setup player creation, New game at N=4/3/2, hand
// entry (draw, penalty, self-pick, discard, both 包 flavours, undo, a natural
// round-wrap and a natural game completion), then the Tier 1 history reports.
//
// Self-asserting: after every UI action this independently re-fetches
// GET /api/games/{id} (the same payload the scoreboard renders from) and
// diffs the DOM against it. The scoring/dealer math itself is already
// covered by tests/ScoringTest.php, GameStateTest.php and
// GamesIntegrationTest.php — this script's job is to catch UI/API wiring
// regressions, not to re-verify the math.
//
// Run (see CLAUDE.md § End-to-end regression tests):
//   bun run test:e2e
//
// Requires: bun run serve:api (8080), bun run dev (5173), the local DB
// reachable, and one login account (php bin/create-user.php --admin). Set
// MJSB_E2E_USER / MJSB_E2E_PASS if the default 'e2e' account doesn't exist,
// or MJSB_E2E_BASE for a non-default Vite URL.
//
// MJSB_E2E_HEADED=1 runs with a visible browser window (plus slowMo, default
// 150ms/step — override with MJSB_E2E_SLOWMO) instead of headless, to watch
// the run happen.

import { chromium } from 'playwright';

const BASE = process.env.MJSB_E2E_BASE ?? 'http://localhost:5173';
const USERNAME = process.env.MJSB_E2E_USER ?? 'e2e';
const PASSWORD = process.env.MJSB_E2E_PASS ?? 'E2eTest#2026';
const RUN_ID = Date.now().toString(36);

const WIND_LETTERS = ['E', 'S', 'W', 'N'];
const WIND_NAMES = ['East', 'South', 'West', 'North'];
const WINNER_KEYS = ['q', 'w', 'e', 'r'];
const DISCARD_KEYS = ['a', 's', 'd', 'f'];
const LIABLE_KEYS = ['z', 'x', 'c', 'v'];

let passCount = 0;
const failures = [];

function check(name, cond, detail) {
  if (cond) {
    passCount++;
  } else {
    failures.push({ name, detail });
    console.error(`FAIL: ${name}${detail !== undefined ? ` — ${detail}` : ''}`);
  }
}

function must(cond, msg) {
  if (!cond) throw new Error(`ABORT: ${msg}`);
}

// ------------------------------------------------------------- API helpers

async function apiFetch(page, method, path, body) {
  return page.evaluate(
    async ({ method, path, body }) => {
      const init = { method, credentials: 'same-origin' };
      if (body !== undefined || method !== 'GET') init.headers = { 'Content-Type': 'application/json' };
      if (body !== undefined) init.body = JSON.stringify(body);
      const res = await fetch(path, init);
      const json = await res.json().catch(() => null);
      return { status: res.status, ok: res.ok, json };
    },
    { method, path, body }
  );
}

async function apiGet(page, path) {
  const res = await apiFetch(page, 'GET', path);
  must(res.ok, `GET ${path} failed: ${res.status} ${JSON.stringify(res.json)}`);
  return res.json.data;
}

async function getGameState(page, id) {
  return apiGet(page, `/api/games/${id}`);
}

// ------------------------------------------------------------ DOM asserters

async function assertDiamond(page, api, label) {
  const chairs = await page.$$eval('.diamond-svg text.chair-wind', (nodes) =>
    nodes.map((n) => ({ cls: n.getAttribute('class') || '', text: n.textContent.trim() }))
  );
  check(`${label}: diamond renders 4 chairs`, chairs.length === 4, `got ${chairs.length}`);

  for (let chair = 0; chair < 4; chair++) {
    const seat = api.seats.find((s) => s.chair === chair);
    const cls = chairs[chair]?.cls ?? '';
    check(`${label}: chair ${chair} empty=${!seat}`, cls.includes('empty') === !seat, `class="${cls}"`);

    const windIndex = seat ? seat.current_wind_index : (chair - api.state.dealer_wind_index + 4) % 4;
    check(`${label}: chair ${chair} dealer=${windIndex === 0}`, cls.includes('dealer') === (windIndex === 0), `class="${cls}"`);
    check(
      `${label}: chair ${chair} glyph`,
      chairs[chair]?.text === WIND_LETTERS[windIndex],
      `got "${chairs[chair]?.text}" want "${WIND_LETTERS[windIndex]}"`
    );
  }

  const names = await page.$$eval('.diamond-svg text.chair-name', (nodes) => nodes.map((n) => n.textContent.trim()));
  const occupied = [...api.seats].sort((a, b) => a.chair - b.chair);
  check(`${label}: occupied name count`, names.length === occupied.length, `${names.length} vs ${occupied.length}`);
  occupied.forEach((seat, i) => {
    check(`${label}: chair ${seat.chair} name label`, names[i] === seat.player.name, `got "${names[i]}"`);
  });

  const dealPos = (await page.locator('.deal-position').innerText()).trim();
  const wantDealPos = `Deal ${api.state.deal_in_round} of ${api.game.player_count}`;
  check(`${label}: deal position`, dealPos === wantDealPos, `got "${dealPos}" want "${wantDealPos}"`);

  const roundText = await page
    .locator('.round-label')
    .first()
    .evaluate((el) => el.childNodes[0]?.textContent?.trim() ?? '');
  const wantRound = `${WIND_NAMES[api.state.round_wind]} Round`;
  check(`${label}: round label`, roundText === wantRound, `got "${roundText}" want "${wantRound}"`);
}

async function assertStandings(page, api, label) {
  const rows = await page.$$eval('.standings-row', (nodes) =>
    nodes.map((n) => ({
      name: n.querySelector('.standings-name')?.textContent.trim(),
      total: n.querySelector('.standings-total')?.textContent.trim(),
      rank: n.querySelector('.standings-rank')?.textContent.trim(),
    }))
  );
  const expected = [...api.seats].sort((a, b) => b.total - a.total);
  check(`${label}: standings row count`, rows.length === expected.length, `${rows.length} vs ${expected.length}`);
  expected.forEach((seat, i) => {
    const row = rows[i];
    if (!row) return;
    check(`${label}: standings[${i}] name`, row.name === seat.player.name, row.name);
    const sign = seat.total < 0 ? '−' : '+';
    const wantTotal = `${sign}${Math.abs(seat.total)}`;
    check(`${label}: standings[${i}] total`, row.total === wantTotal, `got "${row.total}" want "${wantTotal}"`);
    check(`${label}: standings[${i}] rank`, row.rank === String(seat.rank), row.rank);
  });
}

async function assertLastHandRow(page, api, label) {
  if (api.hands.length === 0) return;
  const hand = api.hands[0];
  const firstRow = page.locator('.hand-row').first();
  const numText = (await firstRow.locator('.hand-number').innerText()).trim();
  check(`${label}: newest hand row number`, numText === `#${hand.hand_number}`, `got "${numText}"`);

  const deltasText = await firstRow.locator('.hand-deltas').innerText();
  for (const seat of api.seats) {
    const delta = hand.scores[String(seat.player.id)] ?? 0;
    const sign = delta < 0 ? '−' : '+';
    const token = `${seat.player.name} ${sign}${Math.abs(delta)}`;
    check(`${label}: hand delta shown for ${seat.player.name}`, deltasText.includes(token), `"${token}" not in "${deltasText}"`);
  }
}

async function assertGameState(page, gameId, label) {
  const api = await getGameState(page, gameId);
  // The scoreboard nulls its store and re-fetches on every mount/update, so
  // there's a brief window where the DOM still reflects a previous render.
  // Poll until it catches up to the shape our independent fetch already
  // has, rather than racing a single synchronous read against it.
  await page
    .waitForFunction(
      (n) =>
        document.querySelectorAll('.diamond-svg text.chair-wind').length === 4 &&
        document.querySelectorAll('.standings-row').length > 0 &&
        (n === 0 || document.querySelectorAll('.hand-row').length === n),
      api.hands.length,
      { timeout: 5000 }
    )
    .catch(() => {});
  await assertDiamond(page, api, label);
  await assertStandings(page, api, label);
  await assertLastHandRow(page, api, label);
  // The DOM shape (row/element counts) can already match while EntryBar's
  // own `submitting` flag and form reset are still one tick behind — give
  // it a beat before the caller's next action.
  await page.waitForTimeout(150);
  return api;
}

// ------------------------------------------------------------------- flows

async function login(page) {
  await page.goto(BASE + '/');
  await page.locator('#username').fill(USERNAME);
  await page.locator('#password').fill(PASSWORD);
  await Promise.all([page.waitForResponse((r) => r.url().includes('/api/auth/login')), page.click('button:has-text("Sign in")')]);
  await page.waitForURL(/#\/($|new|history|setup|game)/, { timeout: 5000 }).catch(() => {});
  must(page.url() !== BASE + '/#/login', `still on login page after submit (url=${page.url()})`);
}

async function setEnglish(page) {
  // Default lang mode is 'both' (zh+en glyphs mixed) — switch to 'en' once
  // so every downstream text assertion is unambiguous. The toggle now lives
  // on the #/profile page (AppNav's Profile item), not a top-bar button.
  await page.click('.profile-menu-trigger');
  await page.waitForSelector('.profile-page');
  const btn = page.locator('button[title="Language"]').first();
  for (let i = 0; i < 3; i++) {
    const label = (await btn.innerText()).trim();
    if (label === 'Language: EN') return;
    await btn.click();
    await page.waitForTimeout(50);
  }
  must(false, 'could not switch language to EN');
}

async function goHome(page) {
  // AppNav's brand logo (top-left, every authenticated page) is the only way
  // home now — the nav's own link list is New game / History / Setup only.
  await page.click('.app-nav-brand');
  await page.waitForTimeout(100);
}

async function goToSetup(page) {
  if ((await page.locator('.setup-tabs').count()) > 0 && page.url().includes('#/setup')) return;
  await page.click('a:has-text("Setup")');
  await page.waitForSelector('.setup-tabs');
}

async function createPlayer(page, name) {
  await goToSetup(page);
  await page.locator('button:has-text("Players")').click();
  await page.click('button:has-text("+ Add player")');
  await page.locator('input[placeholder="Player name"]').fill(name);
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/players') && r.request().method() === 'POST'),
    page.click('.inline-create-form button:has-text("Add")'),
  ]);
  must(resp.ok(), `create player "${name}" failed: ${resp.status()}`);
  const json = await resp.json();
  const cardLocator = page.locator('.player-card-name', { hasText: name });
  await cardLocator.first().waitFor({ timeout: 3000 }).catch(() => {});
  check(`setup: player card shows "${name}"`, (await cardLocator.count()) > 0, name);
  return json.data.id;
}

async function startGame(page, { playerCount, minFaan, maxFaan, seats }) {
  await page.click('a:has-text("New game")');
  await page.waitForSelector('.new-game-layout');

  const countIndex = [2, 3, 4].indexOf(playerCount);
  must(countIndex >= 0, `bad playerCount ${playerCount}`);
  await page.locator('.radio-group .radio-label').nth(countIndex).click();

  if (minFaan !== undefined) await page.locator('.faan-range-row select').nth(0).selectOption(String(minFaan));
  if (maxFaan !== undefined) await page.locator('.faan-range-row select').nth(1).selectOption(String(maxFaan));

  for (const { chair, name, expectUsual } of seats) {
    const row = page.locator('.seat-rows .seat-row').nth(chair);
    if (expectUsual !== undefined) {
      const hasUsual = (await row.locator('.seat-row-usual').count()) > 0;
      check(`new game: chair ${chair} usual hint = ${expectUsual}`, hasUsual === expectUsual, `got ${hasUsual}`);
    }
    await row.locator('select').selectOption({ label: name });
  }

  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/games') && r.request().method() === 'POST'),
    page.click('button:has-text("Start game")'),
  ]);
  must(resp.ok(), `create game failed: ${resp.status()} ${await resp.text()}`);
  await page.waitForURL(/#\/game\/\d+/, { timeout: 5000 });
  const json = await resp.json();
  // Scoreboard nulls its store on mount and re-fetches independently of the
  // navigation — wait past that gap so seat/entry data is actually present
  // before the caller starts asserting or pressing entry-bar shortcuts.
  await page.waitForFunction(
    (n) => document.querySelectorAll('.standings-row').length === n,
    json.data.game.player_count,
    { timeout: 5000 }
  );
  return json.data.game.id;
}

// EntryBar's keydown handler is re-subscribed by a `useEffect` whose
// dependency array includes every piece of form state — pressing keys
// faster than Preact's commit-and-resubscribe cycle lets a later key (e.g.
// Enter) land on a stale closure that still sees the pre-keypress state and
// silently no-ops (canRecord false, no request). A human typing these keys
// would never hit this; a real settle delay between presses avoids it.
async function settledPress(page, key) {
  await page.keyboard.press(key);
  await page.waitForTimeout(120);
}

async function recordWinKeyboard(page, gameId, { winnerChair, winType, discarderChair, liableChair, bao, faan }) {
  await settledPress(page, WINNER_KEYS[winnerChair]);
  if (winType === 'discard') {
    await settledPress(page, DISCARD_KEYS[discarderChair]);
    if (bao) await settledPress(page, 'b');
  } else {
    await settledPress(page, 'g');
    if (bao) await settledPress(page, LIABLE_KEYS[liableChair]);
  }
  for (const d of String(faan)) await settledPress(page, d);

  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands`) && r.request().method() === 'POST', { timeout: 10000 }),
    page.keyboard.press('Enter'),
  ]);
  must(resp.ok(), `record hand (keyboard) failed: ${resp.status()} ${await resp.text()}`);
}

async function recordWinClicks(page, gameId, { winnerName, winType, faan }) {
  await page.locator('.entry-row-winner .player-btn', { hasText: winnerName }).click();
  if (winType === 'self_pick') {
    await page.locator('.entry-row-wintype .radio-label', { hasText: 'Self' }).click();
  }
  await page.locator('.faan-picker .faan-btn', { hasText: String(faan) }).click();
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands`) && r.request().method() === 'POST'),
    page.click('.record-btn'),
  ]);
  must(resp.ok(), `record hand (click) failed: ${resp.status()} ${await resp.text()}`);
}

async function recordDrawClick(page, gameId) {
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands`) && r.request().method() === 'POST'),
    page.click('.entry-row-actions button:has-text("Draw")'),
  ]);
  must(resp.ok(), `record draw failed: ${resp.status()}`);
}

async function recordPenalty(page, gameId, { offenderName, note }) {
  await page.click('.entry-row-actions button:has-text("Penalty")');
  await page.locator('.penalty-card .player-btn', { hasText: offenderName }).click();
  if (note) await page.locator('#penalty-note').fill(note);
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands`) && r.request().method() === 'POST'),
    page.click('.penalty-card button:has-text("Record penalty")'),
  ]);
  must(resp.ok(), `record penalty failed: ${resp.status()}`);
}

async function undoHand(page, gameId) {
  await page.click('.undo-btn');
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands/last`) && r.request().method() === 'DELETE'),
    page.click('.confirm-card button:has-text("Undo")'),
  ]);
  must(resp.ok(), `undo failed: ${resp.status()}`);
}

async function endGame(page, gameId) {
  await page.click('.scoreboard-menu button:has-text("End game")');
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/end`) && r.request().method() === 'POST'),
    page.click('.confirm-card button:has-text("End game")'),
  ]);
  must(resp.ok(), `end game failed: ${resp.status()}`);
}

// ---------------------------------------------------------------- reports

// History/PlayerDetail tables use plain formatSigned() (JS's native "-"),
// unlike Standings/HandHistory which build the sign with U+2212 explicitly —
// match whichever the component under test actually renders.
function formatSignedAscii(n) {
  const rounded = Math.round(n);
  return rounded > 0 ? `+${rounded}` : String(rounded);
}

async function setAllTimeAllPlayersFilter(page) {
  await page.click('.stats-filter-group button:has-text("All time")');
  await page.waitForTimeout(150);
  await page.locator('.stats-filter-select select').selectOption('all');
  await page.waitForTimeout(150);
  await page.locator('.stats-filter-checkbox input[type="checkbox"]').check();
  await page.waitForTimeout(150);
}

async function checkHistoryReports(page, players, gameIds) {
  await page.click('a:has-text("History")');
  await page.waitForSelector('.history-page');
  await setAllTimeAllPlayersFilter(page);

  // Games tab: our three games should be listed.
  await page.locator('.setup-tabs button', { hasText: 'Games' }).click();
  await page.waitForTimeout(150);
  for (const id of Object.values(gameIds)) {
    const count = await page.locator(`a.game-card-link[href="#/history/game/${id}"]`).count();
    check(`history games tab: game ${id} listed`, count === 1, `count=${count}`);
  }

  // Game detail for game 1.
  await page.click(`a.game-card-link[href="#/history/game/${gameIds.n4}"]`);
  await page.waitForSelector('.history-page');
  const detailApi = await getGameState(page, gameIds.n4);
  await assertLastHandRow(page, detailApi, 'game detail n4');
  const rowCount = await page.locator('.hand-row').count();
  check('game detail: hand row count matches API', rowCount === detailApi.hands.length, `${rowCount} vs ${detailApi.hands.length}`);
  await page.click('a:has-text("History")');
  await page.waitForSelector('.history-page');
  await setAllTimeAllPlayersFilter(page);

  // Leaderboard tab, filtered to 'all' + abandoned included, checked against
  // the stats API for the same filter.
  await page.locator('.setup-tabs button', { hasText: 'Leaderboard' }).click();
  await page.waitForTimeout(150);
  const board = await apiGet(page, '/api/stats/leaderboard?player_count=all&include_abandoned=1');
  for (const p of players) {
    const apiRow = board.find((r) => r.player.id === p.id);
    check(`leaderboard: API has row for ${p.name}`, !!apiRow, p.name);
    if (!apiRow) continue;
    const domRow = page.locator('.leaderboard-table tbody tr', { hasText: p.name });
    check(`leaderboard: DOM has row for ${p.name}`, (await domRow.count()) === 1, p.name);
    const rowText = await domRow.innerText();
    const wantNet = formatSignedAscii(apiRow.net_points);
    check(`leaderboard: ${p.name} net points`, rowText.includes(wantNet), `want "${wantNet}" in "${rowText}"`);
    check(`leaderboard: ${p.name} games count`, rowText.includes(String(apiRow.games)), String(apiRow.games));
  }

  // Player detail for the first player.
  const target = players[0];
  await page.locator('.leaderboard-table a', { hasText: target.name }).click();
  await page.waitForSelector('.history-page');
  await setAllTimeAllPlayersFilter(page);
  const playerApi = await apiGet(page, `/api/stats/players/${target.id}?player_count=all&include_abandoned=1`);
  const gamesText = (await page.locator('.stat-grid dt', { hasText: 'Games' }).locator('xpath=following-sibling::dd[1]').innerText()).trim();
  check('player detail: games count', gamesText === String(playerApi.games), `got "${gamesText}" want "${playerApi.games}"`);
  const netText = (await page.locator('.stat-grid dt', { hasText: 'Net points' }).locator('xpath=following-sibling::dd[1]').innerText()).trim();
  const wantNet = playerApi.net_points === 0 ? '0' : formatSignedAscii(playerApi.net_points);
  check('player detail: net points', netText === wantNet, `got "${netText}" want "${wantNet}"`);

  // Bonus smoke: the remaining report tabs render without an error banner.
  await page.click('a:has-text("History")');
  await page.waitForSelector('.history-page');
  for (const tabName of ['Points flow', 'Seat luck', 'Streaks & records', 'Feeder stats', 'Win types']) {
    await page.locator('.setup-tabs button', { hasText: tabName }).click();
    await page.waitForTimeout(200);
    const errCount = await page.locator('.history-body > .form-error').count();
    check(`history tab "${tabName}" has no error`, errCount === 0, `errCount=${errCount}`);
  }
}

// ---------------------------------------------------------------------- main

async function main() {
  const headed = process.env.MJSB_E2E_HEADED === '1';
  const browser = await chromium.launch({
    headless: !headed,
    slowMo: headed ? Number(process.env.MJSB_E2E_SLOWMO ?? 150) : 0,
  });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
  const pageErrors = [];
  page.on('pageerror', (e) => {
    pageErrors.push(e.message);
    console.error('PAGE ERROR:', e.message);
  });

  try {
    await login(page);
    await setEnglish(page);

    const names = {
      ann: `E2E Ann ${RUN_ID}`,
      ben: `E2E Ben ${RUN_ID}`,
      cat: `E2E Cat ${RUN_ID}`,
      dan: `E2E Dan ${RUN_ID}`,
    };
    const ids = {};
    for (const [key, name] of Object.entries(names)) {
      ids[key] = await createPlayer(page, name);
    }
    const allPlayers = [
      { id: ids.ann, name: names.ann },
      { id: ids.ben, name: names.ben },
      { id: ids.cat, name: names.cat },
      { id: ids.dan, name: names.dan },
    ];

    const gameIds = {};

    // ---------------------------------------------------------- Game 1: N=4
    console.log('\n=== Game 1: N=4, full seating, round wrap, undo ===');
    await goHome(page);
    const g1 = await startGame(page, {
      playerCount: 4,
      minFaan: 1,
      maxFaan: 13,
      seats: [
        { chair: 0, name: names.ann, expectUsual: true },
        { chair: 1, name: names.ben, expectUsual: true },
        { chair: 2, name: names.cat, expectUsual: true },
        { chair: 3, name: names.dan, expectUsual: true },
      ],
    });
    gameIds.n4 = g1;
    let api = await getGameState(page, g1);
    check('game1: starts at East/dealer0', api.state.round_wind === 0 && api.state.dealer_wind_index === 0, JSON.stringify(api.state));
    await assertGameState(page, g1, 'game1 initial');

    await recordDrawClick(page, g1); // draw — dealer stays
    await assertGameState(page, g1, 'game1 after draw');

    await recordPenalty(page, g1, { offenderName: names.ben, note: 'e2e test penalty' }); // dead hand — dealer stays
    await assertGameState(page, g1, 'game1 after penalty');

    // Dealer (Ann, chair0) wins by mouse clicks, full faan-picker range test.
    await recordWinClicks(page, g1, { winnerName: names.ann, winType: 'self_pick', faan: 13 });
    api = await assertGameState(page, g1, 'game1 after dealer win');
    check('game1: dealer stays after dealer win', api.state.dealer_wind_index === 0, JSON.stringify(api.state));

    // Ben (chair1) wins by discard from Cat (chair2), discard-bao (Cat pays all).
    await recordWinKeyboard(page, g1, { winnerChair: 1, winType: 'discard', discarderChair: 2, bao: true, faan: 5 });
    api = await assertGameState(page, g1, 'game1 after Ben discard-bao win');
    check('game1: dealer moved to chair1', api.state.dealer_wind_index === 1, JSON.stringify(api.state));
    const h1 = api.hands[0];
    check('game1: discard-bao liable is discarder', h1.liable_player_id === ids.cat, JSON.stringify(h1));

    // Cat (chair2) wins self-pick, self-pick-bao naming Dan.
    await recordWinKeyboard(page, g1, { winnerChair: 2, winType: 'self_pick', liableChair: 3, bao: true, faan: 3 });
    api = await assertGameState(page, g1, 'game1 after Cat self-pick-bao win');
    check('game1: dealer moved to chair2', api.state.dealer_wind_index === 2, JSON.stringify(api.state));
    const h2 = api.hands[0];
    check('game1: self-pick-bao liable is named player, not discarder', h2.liable_player_id === ids.dan, JSON.stringify(h2));

    // Dan (chair3) wins discard from Ann, no bao.
    await recordWinKeyboard(page, g1, { winnerChair: 3, winType: 'discard', discarderChair: 0, bao: false, faan: 2 });
    api = await assertGameState(page, g1, 'game1 after Dan win');
    check('game1: dealer moved to chair3', api.state.dealer_wind_index === 3, JSON.stringify(api.state));

    // Ann (chair0) wins discard from Ben — 4th consecutive non-dealer win from
    // dealer0 (docs-initial-build/02-scoring-engine.md S6): wraps East -> South, dealer -> 0.
    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 12 });
    api = await assertGameState(page, g1, 'game1 after round wrap');
    check('game1: round wrapped to South', api.state.round_wind === 1 && api.state.dealer_wind_index === 0, JSON.stringify(api.state));

    // Undo the wrap, confirm it reverts, then redo it.
    await undoHand(page, g1);
    api = await assertGameState(page, g1, 'game1 after undo');
    check('game1: undo reverted round wrap', api.state.round_wind === 0 && api.state.dealer_wind_index === 3, JSON.stringify(api.state));

    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 12 });
    api = await assertGameState(page, g1, 'game1 after redo');
    check('game1: redo reached South round again', api.state.round_wind === 1 && api.state.dealer_wind_index === 0, JSON.stringify(api.state));

    await endGame(page, g1);
    api = await getGameState(page, g1);
    check('game1: status abandoned', api.game.status === 'abandoned', api.game.status);
    check('game1: "Game ended" banner shown', (await page.locator('.complete-message').innerText()).includes('Game ended'), '');

    // ---------------------------------------------------------- Game 2: N=3
    console.log('\n=== Game 2: N=3, one empty chair, round wrap ===');
    await goHome(page);
    const g2 = await startGame(page, {
      playerCount: 3,
      minFaan: 1,
      maxFaan: 8,
      seats: [
        { chair: 0, name: names.ann, expectUsual: true },
        { chair: 1, name: names.ben, expectUsual: true },
        { chair: 2, name: names.cat, expectUsual: true },
      ],
    });
    gameIds.n3 = g2;
    await assertGameState(page, g2, 'game2 initial');

    await recordWinKeyboard(page, g2, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 2 });
    await assertGameState(page, g2, 'game2 after dealer win');

    await recordWinKeyboard(page, g2, { winnerChair: 1, winType: 'discard', discarderChair: 2, bao: true, faan: 6 });
    api = await assertGameState(page, g2, 'game2 after Ben discard-bao win');
    check('game2: dealer moved to chair1', api.state.dealer_wind_index === 1, JSON.stringify(api.state));

    await recordWinKeyboard(page, g2, { winnerChair: 2, winType: 'self_pick', liableChair: 0, bao: true, faan: 8 });
    api = await assertGameState(page, g2, 'game2 after Cat self-pick-bao win');
    check('game2: dealer moved to chair2', api.state.dealer_wind_index === 2, JSON.stringify(api.state));

    // 3rd consecutive non-dealer win from dealer0 at N=3 (S10): wraps to South.
    await recordWinKeyboard(page, g2, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 1 });
    api = await assertGameState(page, g2, 'game2 after round wrap');
    check('game2: round wrapped to South (3 deals/round)', api.state.round_wind === 1 && api.state.dealer_wind_index === 0, JSON.stringify(api.state));

    await endGame(page, g2);
    api = await getGameState(page, g2);
    check('game2: status abandoned', api.game.status === 'abandoned', api.game.status);

    // ---------------------------------------------------------- Game 3: N=2
    console.log('\n=== Game 3: N=2, non-default East+South pair, natural completion ===');
    await goHome(page);
    const g3 = await startGame(page, {
      playerCount: 2,
      seats: [
        { chair: 0, name: names.ann, expectUsual: true }, // East is always "usual"
        { chair: 1, name: names.ben, expectUsual: false }, // East+South is NOT the usual N=2 pair (East+West is)
      ],
    });
    gameIds.n2 = g3;
    api = await assertGameState(page, g3, 'game3 initial');
    check('game3: chairs 2,3 unoccupied', api.seats.every((s) => s.chair === 0 || s.chair === 1), JSON.stringify(api.seats));

    // 8 consecutive non-dealer wins (S14): completes exactly on the 8th.
    for (let i = 0; i < 8; i++) {
      const dealerChair = i % 2 === 0 ? 0 : 1;
      const winnerChair = dealerChair === 0 ? 1 : 0;
      await recordWinKeyboard(page, g3, { winnerChair, winType: 'self_pick', bao: false, faan: 2 });
      api = await assertGameState(page, g3, `game3 hand ${i + 1}`);
    }
    check('game3: naturally completed after 8 hands', api.state.is_complete === true && api.game.status === 'completed', JSON.stringify(api.state));
    check(
      'game3: "Game complete" banner shown',
      (await page.locator('.complete-message').innerText()).includes('Game complete'),
      ''
    );

    // Undo the completing hand (S9): reopens to North round, dealer at chair1.
    await undoHand(page, g3);
    api = await assertGameState(page, g3, 'game3 after undo of completing hand');
    check(
      'game3: undo reopened the game',
      api.game.status === 'in_progress' && api.state.is_complete === false && api.state.round_wind === 3 && api.state.dealer_wind_index === 1,
      JSON.stringify({ status: api.game.status, state: api.state })
    );

    await recordWinKeyboard(page, g3, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 2 });
    api = await assertGameState(page, g3, 'game3 after redo of completing hand');
    check('game3: re-completed', api.state.is_complete === true && api.game.status === 'completed', JSON.stringify(api.state));

    // -------------------------------------------------------------- Reports
    console.log('\n=== History / Tier 1 reports ===');
    await checkHistoryReports(page, allPlayers, gameIds);

    // -------------------------------------------------------------- Cleanup
    console.log('\n=== Cleanup ===');
    for (const id of Object.values(gameIds)) {
      const del = await apiFetch(page, 'DELETE', `/api/games/${id}?confirm=1`);
      check(`cleanup: deleted game ${id}`, del.ok, JSON.stringify(del));
    }
    for (const p of allPlayers) {
      const ret = await apiFetch(page, 'DELETE', `/api/players/${p.id}`);
      check(`cleanup: retired player ${p.name}`, ret.ok, JSON.stringify(ret));
    }

    check('no uncaught frontend errors during the run', pageErrors.length === 0, pageErrors.join(' | '));
  } finally {
    await browser.close();
  }

  console.log(`\n${'='.repeat(60)}`);
  console.log(`PASS: ${passCount}   FAIL: ${failures.length}`);
  if (failures.length > 0) {
    console.log('\nFailures:');
    for (const f of failures) console.log(`  - ${f.name}${f.detail ? ` (${f.detail})` : ''}`);
    process.exitCode = 1;
  }
}

main().catch((err) => {
  console.error('\nFATAL:', err);
  process.exitCode = 1;
});
