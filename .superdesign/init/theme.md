# Theme

## Compact token summary

- Mock display font: `Arial Rounded MT Bold`, fallback `Trebuchet MS`.
- Mock body font: `Trebuchet MS`, fallback `Segoe UI`.
- Ink: `oklch(0.27 0.08 292)`.
- Violet: `oklch(0.4 0.15 295)`; dark violet: `oklch(0.3 0.11 295)`; night: `oklch(0.16 0.04 290)`.
- Paper: `oklch(0.98 0.03 91)`.
- Coral: `oklch(0.7 0.2 25)`; cyan: `oklch(0.81 0.13 195)`; yellow: `oklch(0.88 0.16 89)`.
- Lavender: `oklch(0.86 0.08 296)`.
- Structural outline: `0.1875rem`; radii: `0.65rem`, `0.85rem`, `1.5rem`.
- Shadows are hard, offset, and use ink/night rather than blur.
- Mock breakpoints: `35rem` mobile and `51.25rem` tablet; current waiting-room refinement also adapts at `55rem`.
- Production game frame ratio: `16 / 9`.

## Raw mock token source

```scss
$color-ink: oklch(0.27 0.08 292);
$color-violet: oklch(0.4 0.15 295);
$color-violet-dark: oklch(0.3 0.11 295);
$color-paper: oklch(0.98 0.03 91);
$color-white: oklch(1 0 0);
$color-coral: oklch(0.7 0.2 25);
$color-coral-dark: oklch(0.58 0.19 24);
$color-cyan: oklch(0.81 0.13 195);
$color-yellow: oklch(0.88 0.16 89);
$color-lavender: oklch(0.86 0.08 296);
$color-muted: oklch(0.47 0.04 295);
$color-error: oklch(0.47 0.17 20);
$color-night: oklch(0.16 0.04 290);
$font-display: 'Arial Rounded MT Bold', 'Trebuchet MS', sans-serif;
$font-body: 'Trebuchet MS', 'Segoe UI', sans-serif;
$line-width: 0.1875rem;
$radius-small: 0.65rem;
$radius-medium: 0.85rem;
$radius-large: 1.5rem;
```

## Production Tailwind tokens

The production application uses Tailwind 4 with OKLCH CSS variables from `resources/css/app.css`, `Instrument Sans`, shared light/dark semantic tokens, and `--aspect-frame: 16 / 9`.
