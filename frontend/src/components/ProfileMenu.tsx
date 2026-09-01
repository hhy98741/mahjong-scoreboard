// The right-hand item in AppNav. Hover/focus reveals a Log out dropdown;
// clicking the label itself goes to the full #/profile page. No props -
// reads session and calls api.logout directly, same pattern Home.tsx used to.

import { api } from '../api.ts';
import { navigate } from '../router.ts';
import { session } from '../store.ts';

export function ProfileMenu() {
  async function logout(): Promise<void> {
    await api.logout().catch(() => {});
    session.value = null;
    navigate('#/login');
  }

  return (
    <div class="profile-menu">
      <a
        href="#/profile"
        class="profile-menu-trigger"
        // Clicking navigates but leaves the anchor focused, which keeps the
        // dropdown open (:focus-within) even after the mouse moves away -
        // blur it here so only an actual hover/keyboard-tab keeps it open.
        onClick={(e) => (e.currentTarget as HTMLAnchorElement).blur()}
      >
        Profile
      </a>
      <div class="profile-menu-dropdown">
        <button type="button" onClick={() => void logout()}>
          Log out
        </button>
      </div>
    </div>
  );
}
