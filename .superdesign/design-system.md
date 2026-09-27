# TripleFrames design system

## Product

TripleFrames is a gamified multiplayer cinema quiz. The interface must feel
playful and immediately readable while remaining suitable for account and
security workflows.

## Brand

- Display type: `Arial Rounded MT Bold`, fallback `Trebuchet MS`, bold and compact.
- Body type: `Trebuchet MS`, fallback `Segoe UI`.
- Ink: `oklch(0.27 0.08 292)`.
- Violet: `oklch(0.4 0.15 295)`.
- Dark violet: `oklch(0.3 0.11 295)`.
- Paper: `oklch(0.98 0.03 91)`.
- Coral: `oklch(0.7 0.2 25)`.
- Cyan: `oklch(0.81 0.13 195)`.
- Yellow: `oklch(0.88 0.16 89)`.
- Lavender: `oklch(0.86 0.08 296)`.
- Error: `oklch(0.47 0.17 20)`.

## Components

- Structural outlines are `0.1875rem` solid ink.
- Surfaces use radii between `0.65rem` and `1.5rem`.
- Shadows are hard ink offsets without blur.
- Primary actions are yellow or coral with an ink outline and hard shadow.
- Inputs are paper surfaces with an ink outline and a cyan focus treatment.
- Icons are SVG, never emoji.
- The exact shared TripleFrames SVG logo is used in every brand position.

## Layout

- Public and account pages keep a compact branded header and a yellow footer.
- The background uses the existing vertical violet curtain stripes.
- Account settings use one main paper surface with a clear settings navigation;
  avoid a dashboard made of many identical cards.
- Desktop breakpoint: settings navigation on the left, content on the right.
- Mobile breakpoint: settings navigation becomes a compact horizontal tab row.

## Account functionality

- Profile: edit name and e-mail, show e-mail verification status, resend the
  verification e-mail, save changes.
- Security: current password, new password and confirmation; enable or disable
  two-factor authentication; view or regenerate recovery codes; list, add and
  remove passkeys.
- Appearance: light, dark and system choices.
- Account deletion is intentionally absent from the current Laravel routes and
  must not appear in this design.

## Accessibility and behavior

- Visible keyboard focus on every interactive element.
- Labels remain visible; placeholders never replace labels.
- Destructive actions need confirmation.
- Success and error feedback is placed near the action that triggered it.
- Responsive from 320px upward and respectful of reduced-motion preferences.
