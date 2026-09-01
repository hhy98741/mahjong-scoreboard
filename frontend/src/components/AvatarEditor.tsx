// A player's circular avatar with an overlaid edit button that opens the
// file picker directly - no separate "Avatar" field with a visible <input
// type=file>. Shared by Setup's player cards and Profile's "My player"
// section, both of which edit the same Player.avatar_url.

interface AvatarEditorProps {
  id: string;
  avatarUrl: string;
  preview: string | null;
  color: string;
  name: string;
  busy: boolean;
  onFileChange: (file: File) => void;
  onRemove: () => void;
}

export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0] ?? '';
  const second = parts[1];
  if (parts.length <= 1) return first.slice(0, 2).toUpperCase() || '?';
  return `${first.charAt(0)}${(second ?? '').charAt(0)}`.toUpperCase();
}

export function AvatarEditor({ id, avatarUrl, preview, color, name, busy, onFileChange, onRemove }: AvatarEditorProps) {
  const isDefault = avatarUrl === '/default.svg';

  return (
    <div class="avatar-editor">
      <span class="player-card-avatar" style={{ '--player-color': color } as Record<string, string>}>
        <img src={preview ?? avatarUrl} alt="" />
        {!preview && isDefault && <span class="player-card-initials">{initials(name)}</span>}
      </span>

      {!preview && !isDefault && (
        <button type="button" class="avatar-remove-btn" onClick={onRemove} disabled={busy} title="Remove avatar" aria-label="Remove avatar">
          &times;
        </button>
      )}

      <label class={`avatar-edit-btn${busy ? ' disabled' : ''}`} for={id} title="Change avatar" aria-label="Change avatar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 20h9" />
          <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
        </svg>
        <input
          id={id}
          type="file"
          accept="image/*"
          class="avatar-edit-input"
          disabled={busy}
          onChange={(e) => {
            const input = e.target as HTMLInputElement;
            const file = input.files?.[0];
            input.value = ''; // allow re-selecting the same file next time
            if (file) onFileChange(file);
          }}
        />
      </label>
    </div>
  );
}
