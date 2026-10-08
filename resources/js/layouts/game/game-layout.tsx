import { ConsentBanner } from '@/components/public/consent-banner';
import { CircleAlert } from 'lucide-react';
import { GameAnnouncer } from '@/components/game/game-announcer';
import LanguageSwitcher from '@/components/language-switcher';
import { MaintenanceBanner } from '@/components/public/maintenance-banner';
import { SiteFooter } from '@/components/public/site-footer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useFlashNotice } from '@/hooks/game/use-flash-notice';
import { useOverscrollLock } from '@/hooks/game/use-overscroll-lock';
import { useVisualViewport } from '@/hooks/game/use-visual-viewport';
import type { GameLayoutProps } from '@/types/ui';

/**
 * Coquille plein écran des pages `game/*` (spec 90 § 2.3, contrat C16 § 2.3) :
 * le lobby, le salon expiré et le solo, avec tous leurs états — manche,
 * joueur verrouillé, révélation, podium —, qui ne sont jamais des pages
 * distinctes. L'écran appartient à l'image, au chrono et à la saisie : ni
 * barre latérale, ni fil d'Ariane, ni en-tête.
 *
 * De haut en bas, **exactement** :
 * 1. `MaintenanceBanner` (rien hors drainage) et, sous lui, l'avis de page
 *    expirée ou de rattachement du siège au compte (spec 40 § 13.2), les
 *    seuls flashs qu'une page de jeu subit ;
 * 2. `<main id="game-main">`, la page ;
 * 3. la ligne basse : le sélecteur de langue en icône seule et le déclencheur
 *    du pied de page replié — liens légaux dans une feuille, ouverts en nouvel
 *    onglet, jamais par une visite qui quitterait la partie ;
 * 4. le bandeau de consentement, superposé en bas de la coquille ;
 * 5. `GameAnnouncer`, la SEULE région `aria-live` de la page (C16 § 4), montée
 *    dès la coquille parce qu'une région vivante doit exister avant son
 *    premier message.
 *
 * **Aucun `Toaster`**, et c'est voulu : la section que rend sonner est une
 * région vivante toujours présente, même vide. Un message de jeu passe par du
 * texte à l'écran et par `announce()` ; l'avis de page expirée est rendu dans
 * un `Alert` au rôle `note`, qui ne parle pas, et annoncé une fois.
 *
 * Comportement :
 * - **hauteur** : `--game-viewport-height`, hauteur visible clavier ouvert
 *   compris (`useVisualViewport`), repli `100dvh`, jamais `100vh` (principe
 *   5) ;
 * - **aucun défilement de page** : la racine est `overflow-hidden` ; un état
 *   qui doit défiler (révélation, podium, lobby rempli) le fait dans une
 *   `ScrollArea` à l'intérieur de `main` ; le geste « tirer pour rafraîchir »
 *   est désactivé tant que la coquille est montée (`useOverscrollLock`).
 *
 * Les deux hooks d'effet tiennent un compteur au niveau module : ils
 * résistent au double montage de `strictMode`.
 */
export default function GameLayout({ children }: GameLayoutProps) {
    useVisualViewport();
    useOverscrollLock();

    const notice = useFlashNotice();

    return (
        <div className="relative flex h-[var(--game-viewport-height,100dvh)] flex-col overflow-hidden bg-background text-foreground">
            <MaintenanceBanner />

            {notice !== null && (
                <div className="border-b border-border bg-muted">
                    <Alert
                        role="note"
                        className="mx-auto max-w-5xl rounded-none border-0 bg-transparent"
                    >
                        <CircleAlert aria-hidden="true" />
                        <AlertDescription className="text-foreground">
                            {notice}
                        </AlertDescription>
                    </Alert>
                </div>
            )}

            <main id="game-main" className="min-h-0 flex-1">
                {children}
            </main>

            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border px-2">
                <LanguageSwitcher iconOnly align="start" />
                <SiteFooter variant="collapsed" />
            </div>

            <ConsentBanner />

            <GameAnnouncer />
        </div>
    );
}
