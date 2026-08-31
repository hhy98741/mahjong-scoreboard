// docs-initial-build/04-frontend.md § Entry area, step 2. Offers only the values in the
// game's band (min_faan..max_faan inclusive) — nothing outside it is
// selectable, so an out-of-range faan cannot be recorded. Wraps at 8 per
// row rather than a <select>: one click, every option stays visible.

import { faanLabel } from '../i18n/terms.ts';
import type { Lang } from '../i18n/terms.ts';

interface FaanPickerProps {
  minFaan: number;
  maxFaan: number;
  value: number | null;
  onSelect: (faan: number) => void;
  lang: Lang;
}

export function FaanPicker({ minFaan, maxFaan, value, onSelect, lang }: FaanPickerProps) {
  const values: number[] = [];
  for (let f = minFaan; f <= maxFaan; f++) values.push(f);

  return (
    <div class="faan-picker" role="group" aria-label={faanLabel(0, lang).replace(/^0\s*/, '').trim() || 'Faan'}>
      {values.map((f) => (
        <button
          type="button"
          key={f}
          class={`faan-btn${value === f ? ' selected' : ''}`}
          aria-pressed={value === f}
          onClick={() => onSelect(f)}
        >
          {f}
        </button>
      ))}
    </div>
  );
}
