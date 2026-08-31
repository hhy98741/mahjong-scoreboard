// Beneath the standings, scrolling independently. Newest first — the API
// already sorts descending, so no client-side sort. docs-initial-build/04-frontend.md
// § Hand history.

import { faanLabel, t } from '../i18n/terms.ts';
import type { Lang } from '../i18n/terms.ts';
import type { Hand, Seat } from '../types.ts';

interface HandHistoryProps {
  hands: Hand[];
  seats: Seat[];
  lang: Lang;
}

export function HandHistory({ hands, seats, lang }: HandHistoryProps) {
  const playerById = new Map(seats.map((s) => [s.player.id, s.player]));
  const nameOf = (id: number | null): string => (id === null ? '' : (playerById.get(id)?.name ?? `#${id}`));

  return (
    <div class="hand-history-panel">
      <h2>Hand history</h2>
      <ul class="hand-history-list">
        {hands.map((hand) => (
          <li class="hand-row" key={hand.id}>
            <span class="hand-number">#{hand.hand_number}</span>
            {describeHand(hand, nameOf, lang)}
            <div class="hand-deltas">
              {seats.map((seat) => {
                const delta = hand.scores[String(seat.player.id)] ?? 0;
                const sign = delta < 0 ? '−' : '+';
                return (
                  <span key={seat.player.id} class={delta < 0 ? 'negative' : 'positive'} style={{ marginRight: '0.7em' }}>
                    {seat.player.name} {sign}
                    {Math.abs(delta)}
                  </span>
                );
              })}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function describeHand(hand: Hand, nameOf: (id: number | null) => string, lang: Lang) {
  if (hand.outcome === 'draw') {
    return <span>{t('draw', lang)}</span>;
  }

  if (hand.outcome === 'penalty') {
    return (
      <span>
        {t('penalty', lang)} {nameOf(hand.offender_player_id)} pays {hand.penalty_per_player} each
      </span>
    );
  }

  const winner = nameOf(hand.winner_player_id);
  const faan = faanLabel(hand.faan ?? 0, lang);

  if (hand.win_type === 'discard') {
    return (
      <span>
        {winner} {faan} ◂ {t('discard', lang)} {nameOf(hand.discarder_player_id)}
        {hand.liable_player_id !== null && <span class="hand-bao-badge">{t('bao', lang)}</span>}
      </span>
    );
  }

  return (
    <span>
      {winner} {faan} ● {t('selfPick', lang)}
      {hand.liable_player_id !== null && (
        <>
          {' · '}
          <span class="hand-bao-badge">
            {t('bao', lang)} {nameOf(hand.liable_player_id)}
          </span>
        </>
      )}
    </span>
  );
}
