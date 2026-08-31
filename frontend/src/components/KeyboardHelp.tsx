// The `?` overlay — drawn as the three chair-ordered key rows themselves
// rather than a table, since that is the picture that makes the scheme
// learnable (docs-initial-build/04-frontend.md § Keyboard shortcuts).

interface KeyboardHelpProps {
  onClose: () => void;
}

function Key({ label }: { label: string }) {
  return <span class="kbd-key">{label}</span>;
}

export function KeyboardHelp({ onClose }: KeyboardHelpProps) {
  return (
    <div class="modal-backdrop" onClick={onClose}>
      <div class="modal-card keyboard-help-card" onClick={(e) => e.stopPropagation()}>
        <h2>Keyboard shortcuts</h2>

        <div class="kbd-row">
          <div class="kbd-keys">
            <Key label="Q" />
            <Key label="W" />
            <Key label="E" />
            <Key label="R" />
          </div>
          <div class="kbd-desc">Who won — by chair: East, South, West, North</div>
        </div>

        <div class="kbd-row">
          <div class="kbd-keys">
            <Key label="A" />
            <Key label="S" />
            <Key label="D" />
            <Key label="F" />
            <Key label="G" />
          </div>
          <div class="kbd-desc">Who fed it — by chair, then G = nobody (自摸 self-pick)</div>
        </div>

        <div class="kbd-row">
          <div class="kbd-keys">
            <Key label="Z" />
            <Key label="X" />
            <Key label="C" />
            <Key label="V" />
          </div>
          <div class="kbd-desc">Who pays it all — by chair, 包 on a self-pick only</div>
        </div>

        <ul class="kbd-extra">
          <li>
            <Key label="0–9" /> select the 番 faan value
          </li>
          <li>
            <Key label="B" /> toggle 包 (the whole interaction on a discard win)
          </li>
          <li>
            <Key label="Y" /> 黃莊 Draw — records immediately
          </li>
          <li>
            <Key label="P" /> 罰 Penalty — opens this hand's penalty modal
          </li>
          <li>
            <Key label="Enter" /> Record hand
          </li>
          <li>
            <Key label="Esc" /> Clear the form, or close an open modal
          </li>
          <li>
            <Key label="Ctrl/⌘" />+<Key label="Z" /> Undo last hand (confirms first)
          </li>
          <li>
            <Key label="?" /> Toggle this overlay
          </li>
        </ul>

        <p class="kbd-note">
          Only occupied chairs are bound. A key naming the winner's own chair does nothing. Shortcuts are ignored while a
          text field has focus.
        </p>

        <div class="modal-actions">
          <button type="button" onClick={onClose}>
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
