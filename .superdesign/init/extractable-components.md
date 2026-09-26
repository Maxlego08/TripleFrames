# Extractable components

## GameFrame

- Source: `resources/js/components/game/game-frame.tsx`
- Category: basic
- Description: fixed-ratio 16:9 image frame with loading and unavailable states.
- Extractable props: `src`, `pending`, `alt`, `loadingLabel`, `unavailableLabel`, `format`.
- Hardcoded: anti-drag behavior, object-contain image rendering, tokenized muted surface.

## PlayerAvatar

- Source: `resources/js/components/game/player-avatar.tsx`
- Category: basic
- Description: player avatar with image and initials fallback.
- Extractable props: `avatar`, `alt`, `className`.
- Hardcoded: Radix avatar structure and fallback behavior.

## Button

- Source: `resources/js/components/ui/button.tsx`
- Category: basic
- Description: CVA button with semantic variants and sizes.
- Extractable props: `variant`, `size`, `disabled`.
- Hardcoded: focus treatment and transition classes.

## Input

- Source: `resources/js/components/ui/input.tsx`
- Category: basic
- Description: shared form input with focus and invalid states.
- Extractable props: standard input state and attributes.
- Hardcoded: semantic token classes.

## GameLayout

- Source: `resources/js/layouts/game/game-layout.tsx`
- Category: layout
- Description: full-height dark game shell with viewport locking and accessibility announcer.
- Extractable props: `children`.
- Hardcoded: maintenance placement, low utility rail, no application header/sidebar.

## MockBrand

- Source: `design-test/html/waiting-room.html`
- Category: layout
- Description: compact top-left TripleFrames mark using `brand-logo.svg`.
- Extractable props: link target and accessible label.
- Hardcoded: logo artwork, display font, paper pill, ink outline, hard shadow.
