// Live checklist rendered under a "new password" field, ticking off each
// PASSWORD_RULES entry as the typed value satisfies it.

import { PASSWORD_RULES } from '../passwordPolicy.ts';

interface PasswordRequirementsProps {
  password: string;
}

export function PasswordRequirements({ password }: PasswordRequirementsProps) {
  return (
    <ul class="password-requirements">
      {PASSWORD_RULES.map((rule) => {
        const met = rule.test(password);
        return (
          <li key={rule.key} class={met ? 'requirement-met' : ''}>
            <span class="requirement-check" aria-hidden="true">
              {met ? '✓' : '○'}
            </span>
            {rule.label}
          </li>
        );
      })}
    </ul>
  );
}
