#!/usr/bin/env node
// Live demo seeder — NOT part of the test:e2e regression suite. This drives
// the real frontend (same clicks/keyboard shortcuts as regression.mjs) to
// play through a batch of realistic games, with a visible pause after every
// action so a human watching the browser can follow along. Unlike
// regression.mjs it does not assert anything and does NOT clean up after
// itself — the games/players it creates are meant to stay as real history.
//
// Config comes from a .env file at the repo root (Bun loads it
// automatically for `bun run`) — copy .env.example to .env and fill in:
//   MJSB_DEMO_BASE   the site to drive, e.g. https://your-domain.example
//   MJSB_DEMO_USER   login username
//   MJSB_DEMO_PASS   login password
// Optional:
//   MJSB_DEMO_HEADLESS=1     run with no visible browser window (default: headed)
//   MJSB_DEMO_DELAY_MS=1000  pause after each action, in ms (default: 1000)
//
// Run:
//   bun run demo
//
// Tip: point MJSB_DEMO_BASE at your local `bun run dev` server first
// (http://localhost:5173) to watch a run end to end before pointing it at
// the live site.
//
// Players: creates/reuses four dedicated "Demo …" players, and pulls in up
// to two existing (non-"Demo …") active players from the account so the
// demo history mixes made-up players with real ones.

import { chromium } from 'playwright';

function requireEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing ${name} — copy .env.example to .env and fill it in (see tests/e2e/demo.mjs header).`);
  }
  return value;
}

const BASE = requireEnv('MJSB_DEMO_BASE');
const USERNAME = requireEnv('MJSB_DEMO_USER');
const PASSWORD = requireEnv('MJSB_DEMO_PASS');
const HEADLESS = process.env.MJSB_DEMO_HEADLESS === '1';
const DELAY_MS = Number(process.env.MJSB_DEMO_DELAY_MS ?? 1000);

const WINNER_KEYS = ['q', 'w', 'e', 'r'];
const DISCARD_KEYS = ['a', 's', 'd', 'f'];
const LIABLE_KEYS = ['z', 'x', 'c', 'v'];

function must(cond, msg) {
  if (!cond) throw new Error(`ABORT: ${msg}`);
}

async function note(page, label) {
  console.log(`\n▶ ${label}`);
  await page.waitForTimeout(DELAY_MS);
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

// ------------------------------------------------------------------- flows

async function login(page) {
  await page.goto(BASE + '/');
  await page.locator('#username').fill(USERNAME);
  await page.locator('#password').fill(PASSWORD);
  await Promise.all([page.waitForResponse((r) => r.url().includes('/api/auth/login')), page.click('button:has-text("Sign in")')]);
  await page.waitForURL(/#\/($|new|history|setup|game)/, { timeout: 5000 }).catch(() => {});
  must(page.url() !== BASE + '/#/login', `still on login page after submit (url=${page.url()})`);
}

async function goHome(page) {
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
  return json.data.id;
}

async function ensurePlayer(page, name) {
  const players = await apiGet(page, '/api/players');
  const found = players.find((p) => p.name === name);
  if (found) {
    console.log(`  (reusing existing player "${name}")`);
    return found.id;
  }
  return createPlayer(page, name);
}

async function startGame(page, { playerCount, minFaan, maxFaan, seats }) {
  await goHome(page);
  await page.click('a:has-text("New game")');
  await page.waitForSelector('.new-game-layout');

  const countIndex = [2, 3, 4].indexOf(playerCount);
  must(countIndex >= 0, `bad playerCount ${playerCount}`);
  await page.locator('.radio-group .radio-label').nth(countIndex).click();

  if (minFaan !== undefined) await page.locator('.faan-range-row select').nth(0).selectOption(String(minFaan));
  if (maxFaan !== undefined) await page.locator('.faan-range-row select').nth(1).selectOption(String(maxFaan));

  for (const { chair, name } of seats) {
    await page.locator('.seat-rows .seat-row').nth(chair).locator('select').selectOption({ label: name });
  }

  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/games') && r.request().method() === 'POST'),
    page.click('button:has-text("Start game")'),
  ]);
  must(resp.ok(), `create game failed: ${resp.status()} ${await resp.text()}`);
  await page.waitForURL(/#\/game\/\d+/, { timeout: 5000 });
  const json = await resp.json();
  await page.waitForFunction(
    (n) => document.querySelectorAll('.standings-row').length === n,
    json.data.game.player_count,
    { timeout: 5000 }
  );
  return json.data.game.id;
}

// See regression.mjs for why a real settle delay is needed between key
// presses — EntryBar's keydown handler is re-subscribed on every state
// change, and pressing too fast can land a key on a stale closure.
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

async function recordDrawClick(page, gameId) {
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/games/${gameId}/hands`) && r.request().method() === 'POST'),
    page.click('.entry-row-actions button:has-text("Draw")'),
  ]);
  must(resp.ok(), `record draw failed: ${resp.status()}`);
}

async function recordPenalty(page, gameId, { offenderChair, note: penaltyNote }) {
  await page.click('.entry-row-actions button:has-text("Penalty")');
  await page.locator('.penalty-card .player-btn').nth(offenderChair).click();
  if (penaltyNote) await page.locator('#penalty-note').fill(penaltyNote);
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

// ---------------------------------------------------------------------- main

async function main() {
  const browser = await chromium.launch({ headless: HEADLESS, slowMo: HEADLESS ? 0 : 50 });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
  page.on('pageerror', (e) => console.error('PAGE ERROR:', e.message));

  try {
    console.log(`Logging into ${BASE} as ${USERNAME}...`);
    await login(page);
    await note(page, 'Logged in');

    const demoNames = ['Demo Ann', 'Demo Ben', 'Demo Cat', 'Demo Dan'];
    for (const name of demoNames) {
      await ensurePlayer(page, name);
      await note(page, `Player ready: ${name}`);
    }

    const existing = await apiGet(page, '/api/players');
    const real = existing.filter((p) => p.is_active && !demoNames.includes(p.name)).slice(0, 2).map((p) => p.name);
    console.log(`\nUsing ${real.length} existing player(s) from the account alongside the demo players: ${real.join(', ') || '(none found)'}`);

    // Rotating window over [demo players..., real players...] — each game
    // takes the next N distinct names, so games mix demo and real players
    // and vary who's seated where across the run.
    const pool = [...demoNames, ...real];
    let cursor = 0;
    function nextSeats(n) {
      const names = [];
      for (let i = 0; i < n; i++) names.push(pool[(cursor + i) % pool.length]);
      cursor = (cursor + n) % pool.length;
      return names.map((name, chair) => ({ chair, name }));
    }

    // ------------------------------------------------------- Game 1: N=4
    // Long same-dealer streak (draw, two dealer wins, a penalty — dealer
    // stays through all four), then four consecutive non-dealer wins
    // (discard-bao, self-pick-bao, plain discard x2) that wrap the round
    // East -> South, then an undo/redo of that wrapping hand, then a
    // manual "End game" (cancel) to close it out mid-round.
    console.log('\n=== Game 1: N=4 — dealer streak, both bao flavours, round wrap, undo/redo, manual end ===');
    const g1seats = nextSeats(4);
    const g1 = await startGame(page, { playerCount: 4, minFaan: 1, maxFaan: 13, seats: g1seats });
    await note(page, `Game 1 started (N=4): ${g1seats.map((s) => s.name).join(', ')}`);

    await recordDrawClick(page, g1);
    await note(page, 'Game 1: draw — dealer stays');

    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 13 });
    await note(page, 'Game 1: dealer self-pick win (13 faan) — dealer stays');

    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 10 });
    await note(page, 'Game 1: dealer wins again by discard — dealer stays (3rd hand in a row)');

    await recordPenalty(page, g1, { offenderChair: 2, note: 'demo penalty' });
    await note(page, 'Game 1: penalty (dead hand) — dealer stays (4th hand in a row)');

    await recordWinKeyboard(page, g1, { winnerChair: 1, winType: 'discard', discarderChair: 2, bao: true, faan: 5 });
    await note(page, 'Game 1: discard-bao win — dealer moves on');

    await recordWinKeyboard(page, g1, { winnerChair: 2, winType: 'self_pick', liableChair: 3, bao: true, faan: 3 });
    await note(page, 'Game 1: self-pick-bao win — dealer moves on');

    await recordWinKeyboard(page, g1, { winnerChair: 3, winType: 'discard', discarderChair: 0, bao: false, faan: 2 });
    await note(page, 'Game 1: plain discard win — dealer moves on');

    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 12 });
    await note(page, 'Game 1: 4th consecutive non-dealer win — round wraps East -> South');

    await undoHand(page, g1);
    await note(page, 'Game 1: undo — the round wrap reverts');

    await recordWinKeyboard(page, g1, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 12 });
    await note(page, 'Game 1: redo — round wraps to South again');

    await endGame(page, g1);
    await note(page, 'Game 1: manually ended (cancelled)');

    // ------------------------------------------------------- Game 2: N=3
    // Two draws in a row before the dealer's first win (dealer stays
    // through all three), then three consecutive non-dealer wins wrap the
    // round at N=3, then a manual end.
    console.log('\n=== Game 2: N=3 — repeated draws, round wrap, manual end ===');
    const g2seats = nextSeats(3);
    const g2 = await startGame(page, { playerCount: 3, minFaan: 1, maxFaan: 8, seats: g2seats });
    await note(page, `Game 2 started (N=3): ${g2seats.map((s) => s.name).join(', ')}`);

    await recordDrawClick(page, g2);
    await note(page, 'Game 2: draw — dealer stays');

    await recordDrawClick(page, g2);
    await note(page, 'Game 2: another draw — dealer stays (2nd in a row)');

    await recordWinKeyboard(page, g2, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 2 });
    await note(page, 'Game 2: dealer wins — dealer stays (3rd in a row)');

    await recordWinKeyboard(page, g2, { winnerChair: 1, winType: 'discard', discarderChair: 2, bao: true, faan: 6 });
    await note(page, 'Game 2: discard-bao win — dealer moves on');

    await recordWinKeyboard(page, g2, { winnerChair: 2, winType: 'self_pick', liableChair: 0, bao: true, faan: 8 });
    await note(page, 'Game 2: self-pick-bao win — dealer moves on');

    await recordWinKeyboard(page, g2, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 1 });
    await note(page, 'Game 2: 3rd consecutive non-dealer win — round wraps at N=3');

    await endGame(page, g2);
    await note(page, 'Game 2: manually ended (cancelled)');

    // ------------------------------------------------------- Game 3: N=2
    // Eight straight alternating non-dealer wins completes the game
    // naturally — no manual end here, this one plays all the way out.
    console.log('\n=== Game 3: N=2 — plays out to natural completion ===');
    const g3seats = nextSeats(2);
    const g3 = await startGame(page, { playerCount: 2, seats: g3seats });
    await note(page, `Game 3 started (N=2): ${g3seats.map((s) => s.name).join(', ')}`);

    for (let i = 0; i < 8; i++) {
      const dealerChair = i % 2 === 0 ? 0 : 1;
      const winnerChair = dealerChair === 0 ? 1 : 0;
      await recordWinKeyboard(page, g3, { winnerChair, winType: 'self_pick', bao: false, faan: 2 });
      await note(page, `Game 3: hand ${i + 1} of 8`);
    }
    await note(page, 'Game 3: complete naturally');

    // ------------------------------------------------------- Game 4: N=4
    // Two dealer wins plus a draw (dealer stays through all three), then
    // just two non-dealer wins (bao flavours reversed) — ends by manual
    // cancel before a round wrap happens, for contrast with Game 1.
    console.log('\n=== Game 4: N=4 — dealer streak, cancelled mid-round (no wrap) ===');
    const g4seats = nextSeats(4);
    const g4 = await startGame(page, { playerCount: 4, minFaan: 1, maxFaan: 10, seats: g4seats });
    await note(page, `Game 4 started (N=4): ${g4seats.map((s) => s.name).join(', ')}`);

    await recordWinKeyboard(page, g4, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 7 });
    await note(page, 'Game 4: dealer win — dealer stays');

    await recordWinKeyboard(page, g4, { winnerChair: 0, winType: 'discard', discarderChair: 1, bao: false, faan: 4 });
    await note(page, 'Game 4: dealer wins again — dealer stays (2nd in a row)');

    await recordDrawClick(page, g4);
    await note(page, 'Game 4: draw — dealer stays (3rd in a row)');

    await recordWinKeyboard(page, g4, { winnerChair: 1, winType: 'self_pick', liableChair: 2, bao: true, faan: 9 });
    await note(page, 'Game 4: self-pick-bao win — dealer moves on');

    await recordWinKeyboard(page, g4, { winnerChair: 2, winType: 'discard', discarderChair: 3, bao: true, faan: 6 });
    await note(page, 'Game 4: discard-bao win — dealer moves on');

    await endGame(page, g4);
    await note(page, 'Game 4: manually ended (cancelled) before a round wrap');

    // ------------------------------------------------------- Game 5: N=3
    // A draw, a penalty and another draw before the dealer's first win
    // (dealer stays through all four), then one non-dealer win, then a
    // manual end.
    console.log('\n=== Game 5: N=3 — draws and a penalty back to back, manual end ===');
    const g5seats = nextSeats(3);
    const g5 = await startGame(page, { playerCount: 3, minFaan: 1, maxFaan: 6, seats: g5seats });
    await note(page, `Game 5 started (N=3): ${g5seats.map((s) => s.name).join(', ')}`);

    await recordDrawClick(page, g5);
    await note(page, 'Game 5: draw — dealer stays');

    await recordPenalty(page, g5, { offenderChair: 1, note: 'demo penalty' });
    await note(page, 'Game 5: penalty — dealer stays (2nd in a row)');

    await recordDrawClick(page, g5);
    await note(page, 'Game 5: another draw — dealer stays (3rd in a row)');

    await recordWinKeyboard(page, g5, { winnerChair: 0, winType: 'discard', discarderChair: 2, bao: false, faan: 4 });
    await note(page, 'Game 5: dealer wins — dealer stays (4th in a row)');

    await recordWinKeyboard(page, g5, { winnerChair: 1, winType: 'discard', discarderChair: 2, bao: true, faan: 6 });
    await note(page, 'Game 5: discard-bao win — dealer moves on');

    await endGame(page, g5);
    await note(page, 'Game 5: manually ended (cancelled)');

    // ------------------------------------------------------- Game 6: N=2
    // Default East+West pairing, just two hands then a manual cancel —
    // the short, uneventful game for contrast with Game 3.
    console.log('\n=== Game 6: N=2 — short game, cancelled early ===');
    const g6seats = nextSeats(2);
    const g6 = await startGame(page, { playerCount: 2, seats: g6seats });
    await note(page, `Game 6 started (N=2): ${g6seats.map((s) => s.name).join(', ')}`);

    await recordWinKeyboard(page, g6, { winnerChair: 0, winType: 'self_pick', bao: false, faan: 5 });
    await note(page, 'Game 6: dealer win — dealer stays');

    await recordWinKeyboard(page, g6, { winnerChair: 1, winType: 'self_pick', bao: false, faan: 3 });
    await note(page, 'Game 6: non-dealer win — dealer moves on');

    await endGame(page, g6);
    await note(page, 'Game 6: manually ended (cancelled) after just two hands');

    console.log('\nDone — 6 games are now in the app history for real.');
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error('\nFATAL:', err);
  process.exitCode = 1;
});
