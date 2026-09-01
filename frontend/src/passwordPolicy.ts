// Mirrors App\Domain\PasswordPolicy (app/Domain/PasswordPolicy.php), which is
// what actually rejects a weak password server-side - this copy only drives
// the live checklist under each "new password" field. Keep both in sync if
// the rule ever changes.

export interface PasswordRule {
  key: string;
  label: string;
  test: (password: string) => boolean;
}

export const PASSWORD_MIN_LENGTH = 12;

export const PASSWORD_RULES: readonly PasswordRule[] = [
  { key: 'length', label: `At least ${PASSWORD_MIN_LENGTH} characters`, test: (p) => p.length >= PASSWORD_MIN_LENGTH },
  { key: 'letter', label: 'A letter', test: (p) => /[A-Za-z]/.test(p) },
  { key: 'number', label: 'A number', test: (p) => /[0-9]/.test(p) },
  { key: 'symbol', label: 'A symbol', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

export function isPasswordValid(password: string): boolean {
  return PASSWORD_RULES.every((rule) => rule.test(password));
}
