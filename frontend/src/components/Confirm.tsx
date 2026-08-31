// Generic confirmation dialog. Used by undo ("always names what it will
// remove and confirms first" — docs-initial-build/04-frontend.md § Entry area) and by
// End game.

interface ConfirmProps {
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

export function Confirm({ message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger, onConfirm, onCancel }: ConfirmProps) {
  return (
    <div class="modal-backdrop" onClick={onCancel}>
      <div class="modal-card confirm-card" onClick={(e) => e.stopPropagation()}>
        <p class="confirm-message">{message}</p>
        <div class="modal-actions">
          <button type="button" onClick={onCancel}>
            {cancelLabel}
          </button>
          <button type="button" class={danger ? 'danger-btn' : 'primary-btn'} onClick={onConfirm} autoFocus>
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
