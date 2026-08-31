// Inline SVG per docs/04-frontend.md § Seating diamond. Chairs are fixed;
// the wind glyph shown at each chair is its CURRENT wind and rotates with
// the deal. All four winds always render, including at empty chairs — never
// derive which sides are empty from player_count, read it from seats[].chair.

import { roundLabel, t, windGlyph } from '../i18n/terms.ts';
import type { Lang } from '../i18n/terms.ts';
import type { Seat } from '../types.ts';

interface SeatingDiamondProps {
  seats: Seat[];
  playerCount: number;
  dealerWindIndex: number;
  roundWind: number;
  dealInRound: number;
  lang: Lang;
}

type Chair = 0 | 1 | 2 | 3;
const CHAIRS: readonly Chair[] = [0, 1, 2, 3];

// Exact coordinates from docs/04-frontend.md § Seating diamond.
const WIND_LABEL_POS: Record<Chair, readonly [number, number]> = {
  0: [155, 155],
  1: [155, 245],
  2: [245, 245],
  3: [245, 155],
};
const NAME_POS: Record<Chair, readonly [number, number]> = {
  0: [86, 86],
  1: [86, 314],
  2: [314, 314],
  3: [314, 86],
};

// Diamond polygon (200,60) (340,200) (200,340) (60,200). Traversal
// upper-left -> lower-left -> lower-right -> upper-right is counterclockwise
// — the direction the deal travels.
const V_TOP: readonly [number, number] = [200, 60];
const V_RIGHT: readonly [number, number] = [340, 200];
const V_BOTTOM: readonly [number, number] = [200, 340];
const V_LEFT: readonly [number, number] = [60, 200];

const EDGES: Record<Chair, readonly [readonly [number, number], readonly [number, number]]> = {
  0: [V_TOP, V_LEFT], // upper-left
  1: [V_LEFT, V_BOTTOM], // lower-left
  2: [V_BOTTOM, V_RIGHT], // lower-right
  3: [V_RIGHT, V_TOP], // upper-right
};

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  const second = parts[1];
  if (parts.length <= 1) return first.slice(0, 2).toUpperCase() || '?';
  return `${first.charAt(0)}${(second ?? '').charAt(0)}`.toUpperCase();
}

export function SeatingDiamond(props: SeatingDiamondProps) {
  const { seats, playerCount, dealerWindIndex, roundWind, dealInRound, lang } = props;
  const byChair = new Map<Chair, Seat>();
  for (const seat of seats) byChair.set(seat.chair as Chair, seat);

  return (
    <div>
      <div class="round-label">
        {roundLabel(roundWind, lang)}
        <div class="deal-position">
          Deal {dealInRound} of {playerCount}
        </div>
      </div>
      <svg class="diamond-svg" viewBox="0 0 400 400" role="img" aria-label="Seating diamond">
        <polygon class="diamond-fill" points={`${V_TOP.join(',')} ${V_RIGHT.join(',')} ${V_BOTTOM.join(',')} ${V_LEFT.join(',')}`} />

        <defs>
          {CHAIRS.map((chair) => {
            const seat = byChair.get(chair);
            if (!seat) return null;
            const [nx, ny] = NAME_POS[chair];
            return (
              <clipPath id={`avatar-clip-${chair}`} key={`clip-${chair}`}>
                <circle cx={nx} cy={ny} r={26} />
              </clipPath>
            );
          })}
        </defs>

        {CHAIRS.map((chair) => {
          const seat = byChair.get(chair);
          const [ex1, ey1] = EDGES[chair][0];
          const [ex2, ey2] = EDGES[chair][1];
          return <line key={`edge-${chair}`} class={`diamond-edge${seat ? '' : ' empty'}`} x1={ex1} y1={ey1} x2={ex2} y2={ey2} />;
        })}

        {CHAIRS.map((chair) => {
          const seat = byChair.get(chair);
          const currentWindIndex = seat ? seat.current_wind_index : (chair - dealerWindIndex + 4) % 4;
          const isDealer = currentWindIndex === 0;
          const glyph = windGlyph(currentWindIndex, lang);
          const [wx, wy] = WIND_LABEL_POS[chair];
          const [nx, ny] = NAME_POS[chair];

          return (
            <g key={`chair-${chair}`}>
              {isDealer && <circle class="dealer-highlight" cx={wx} cy={wy} r={34} />}
              <text class={`chair-wind${isDealer ? ' dealer' : ''}${seat ? '' : ' empty'}`} x={wx} y={wy}>
                {glyph.main}
                {glyph.sup !== null && (
                  <tspan class="chair-wind-sup" dx="2" dy="-14">
                    {glyph.sup}
                  </tspan>
                )}
              </text>

              {seat && (
                <>
                  <circle class="avatar-ring" cx={nx} cy={ny} r={28} style={{ stroke: seat.player.color }} />
                  <image
                    href={seat.player.avatar_url}
                    x={nx - 26}
                    y={ny - 26}
                    width={52}
                    height={52}
                    clip-path={`url(#avatar-clip-${chair})`}
                  />
                  {seat.player.avatar_url === '/default.svg' && (
                    <text class="avatar-initials" x={nx} y={ny} style={{ fill: seat.player.color }}>
                      {initials(seat.player.name)}
                    </text>
                  )}
                  <text class="chair-name" x={nx} y={ny + 44} style={{ fill: seat.player.color }}>
                    {seat.player.name}
                  </text>
                  {chair === 0 && (
                    // Static reference point for the whole game — stacked
                    // below the name rather than beside the avatar, so it
                    // never overlaps regardless of how long the label runs
                    // in 'both' mode. Text pairs the dot so color is never
                    // the only signal (04-frontend.md § Accessibility).
                    <text class="opening-dealer-label" x={nx} y={ny + 62}>
                      ● {t('openingDealer', lang)}
                    </text>
                  )}
                </>
              )}
            </g>
          );
        })}
      </svg>
    </div>
  );
}
