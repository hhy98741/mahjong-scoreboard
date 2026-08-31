// 罰 Penalty modal — offender picker plus a points-each field pre-filled
// from ruleset.penalty_default (docs/04-frontend.md § Entry area). `P`
// opens it with focus on the offender picker; `Esc` closes it.

import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../i18n/terms.ts';
import type { Lang } from '../i18n/terms.ts';
import { PlayerPicker } from './PlayerPicker.tsx';
import type { Seat } from '../types.ts';

interface PenaltyModalProps {
  seats: Seat[];
  penaltyDefault: number;
  lang: Lang;
  onSubmit: (offenderId: number, pointsEach: number, note: string | null) => void;
  onClose: () => void;
}

export function PenaltyModal({ seats, penaltyDefault, lang, onSubmit, onClose }: PenaltyModalProps) {
  const [offenderId, setOffenderId] = useState<number | null>(null);
  const [pointsEach, setPointsEach] = useState(String(penaltyDefault));
  const [note, setNote] = useState('');
  const firstButtonRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Focus the offender picker on open, per the keyboard-shortcut spec for `P`.
    const btn = firstButtonRef.current?.querySelector('button');
    (btn as HTMLButtonElement | null)?.focus();
  }, []);

  const points = Number(pointsEach);
  const valid = offenderId !== null && Number.isInteger(points) && points > 0;

  function submit(): void {
    if (!valid || offenderId === null) return;
    onSubmit(offenderId, points, note.trim() === '' ? null : note.trim());
  }

  return (
    <div class="modal-backdrop" onClick={onClose}>
      <div class="modal-card penalty-card" onClick={(e) => e.stopPropagation()}>
        <h2>{t('penalty', lang)}</h2>

        <div class="field">
          <label>Offender</label>
          <div ref={firstButtonRef}>
            <PlayerPicker seats={seats} value={offenderId} onSelect={setOffenderId} label="Offender" />
          </div>
        </div>

        <div class="field">
          <label for="penalty-points">Points each</label>
          <input
            id="penalty-points"
            type="number"
            min={1}
            step={1}
            value={pointsEach}
            onInput={(e) => setPointsEach((e.target as HTMLInputElement).value)}
          />
        </div>

        <div class="field">
          <label for="penalty-note">Note (optional)</label>
          <input id="penalty-note" value={note} onInput={(e) => setNote((e.target as HTMLInputElement).value)} />
        </div>

        <div class="modal-actions">
          <button type="button" onClick={onClose}>
            Cancel
          </button>
          <button type="button" class="primary-btn" disabled={!valid} onClick={submit}>
            Record penalty
          </button>
        </div>
      </div>
    </div>
  );
}
