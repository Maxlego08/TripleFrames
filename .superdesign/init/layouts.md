# Shared layouts

## GameLayout

- Source: `resources/js/layouts/game/game-layout.tsx`
- Full-height, dark, overflow-locked game shell with no application header or sidebar.

```tsx
import { CircleAlert } from 'lucide-react';
import { GameAnnouncer } from '@/components/game/game-announcer';
import LanguageSwitcher from '@/components/language-switcher';
import { MaintenanceBanner } from '@/components/public/maintenance-banner';
import { SiteFooter } from '@/components/public/site-footer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useFlashNotice } from '@/hooks/game/use-flash-notice';
import { useOverscrollLock } from '@/hooks/game/use-overscroll-lock';
import { useVisualViewport } from '@/hooks/game/use-visual-viewport';
import { useForcedAppearance } from '@/hooks/use-forced-appearance';
import type { GameLayoutProps } from '@/types/ui';

export default function GameLayout({ children }: GameLayoutProps) {
    useForcedAppearance('dark');
    useVisualViewport();
    useOverscrollLock();
    const notice = useFlashNotice();

    return (
        <div className="flex h-[var(--game-viewport-height,100dvh)] flex-col overflow-hidden bg-background text-foreground">
            <MaintenanceBanner />
            {notice !== null && (
                <div className="border-b border-border bg-muted">
                    <Alert role="note" className="mx-auto max-w-5xl rounded-none border-0 bg-transparent">
                        <CircleAlert aria-hidden="true" />
                        <AlertDescription className="text-foreground">{notice}</AlertDescription>
                    </Alert>
                </div>
            )}
            <main id="game-main" className="min-h-0 flex-1">{children}</main>
            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border px-2">
                <LanguageSwitcher iconOnly align="start" className="min-h-11 min-w-11" />
                <SiteFooter variant="collapsed" />
            </div>
            <GameAnnouncer />
        </div>
    );
}
```

## Standalone design layout

- Source: `design-test/html/waiting-room.html`
- Full viewport game mock with fixed brand at top-left, central room control, game surface, and a bottom interaction area. The page intentionally has no site header or footer.
