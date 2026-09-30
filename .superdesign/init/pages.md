# Page dependency trees

## Waiting room mock

Entry: `design-test/html/waiting-room.html`

- `design-test/html/waiting-room.scss`
  - `design-test/html/scss/_tokens.scss`
  - `design-test/html/scss/_mixins.scss`
- `design-test/html/waiting-room.js`
- `design-test/html/brand-logo.svg`

## Game page mock — new target

Entry: `design-test/html/game.html`

- `design-test/html/game.scss`
  - `design-test/html/scss/_tokens.scss`
  - `design-test/html/scss/_mixins.scss`
- `design-test/html/game.js`
- `design-test/html/brand-logo.svg`

## Room entry

Entry: `resources/js/pages/room/join.tsx`

- `resources/js/layouts/public/public-layout.tsx`
- `resources/js/components/room/seat-form.tsx`
  - `resources/js/components/game/avatar-picker.tsx`
  - `resources/js/components/ui/button.tsx`
  - `resources/js/components/ui/input.tsx`
- `resources/js/hooks/use-translations.ts`

## Game shell

Entry: server-provided page for `room.show`

- `resources/js/layouts/game/game-layout.tsx`
  - `resources/js/components/game/game-announcer.tsx`
  - `resources/js/components/public/maintenance-banner.tsx`
  - `resources/js/components/public/site-footer.tsx`
- `resources/js/components/game/game-frame.tsx`
  - `resources/js/components/ui/spinner.tsx`
- `resources/js/components/game/player-avatar.tsx`
  - `resources/js/components/ui/avatar.tsx`
- `resources/js/hooks/game/use-game-state.ts`
- `resources/js/hooks/game/use-game-channel.ts`
- `resources/js/hooks/game/use-round-clock.ts`
- `resources/css/app.css`
