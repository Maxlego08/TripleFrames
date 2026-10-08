import { CircleCheck, LogOut, Users, WifiOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { usePlayerLabel } from '@/components/game/player-ordinals';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useTranslations } from '@/hooks/use-translations';
import { ordinalKey } from '@/lib/game/scoring-format';
import { cn } from '@/lib/utils';
import type { SeatView } from '@/types/game-wire';
import type { TranslationKey } from '@/types/translations';

export type RoundPlayersProps = {
    /**
     * Les sièges de la partie (identité gelée au lancement) : ceux de
     * `state.seats` qui portent un `firstRoundNumber`, jamais un siège qui
     * attend la partie suivante.
     */
    seats: readonly SeatView[];
    /** `state.self.publicId` : ce joueur, marqué « Vous ». */
    selfPublicId: string;
    /**
     * Qui a trouvé la manche montrée, et en quelle position
     * (`RoundState.locked`, nourri par `player.locked` : `publicId` et
     * `lockRank` seulement, jamais les points ni le palier d'autrui).
     */
    locked: readonly { publicId: string; lockRank: number }[];
    /**
     * Salon multijoueur à un seul siège connecté (60 § 9.3) : affichage
     * cosmétique `game.round.lone_player`, jamais « solo ».
     */
    lonePlayer: boolean;
    /**
     * Contenu de la feuille « Joueurs » : la liste complète des sièges, les
     * gestes de l'hôte et « Quitter le salon » (50 § 11), atteignables
     * pendant la manche sans la quitter. Nul : aucune feuille (solo).
     */
    panel?: ReactNode;
    className?: string;
};

/** État de présence qui se dit sur la bande : icône et texte (principe 8). */
const PRESENCE: Record<
    'disconnected' | 'left',
    { key: TranslationKey; icon: LucideIcon }
> = {
    disconnected: { key: 'room.lobby.seat.disconnected', icon: WifiOff },
    left: { key: 'room.lobby.seat.left', icon: LogOut },
};

/**
 * La bande des joueurs d'une manche et le fil « a trouvé » (spec 60 § 8.4,
 * § 9.3 ; 90 § 7.2 et § 10, états « Manche » et « Joueur verrouillé » ;
 * 00 § Le jeu en une manche).
 *
 * - **Fil « a trouvé »** : un siège qui a trouvé porte le badge `--success`
 *   **et** une icône **et** le texte `game.round.found`, avec sa position
 *   d'arrivée (ordinal de 80) ; ceux qui ont trouvé passent en tête, dans
 *   l'ordre d'arrivée, les autres gardent l'ordre des sièges. Rien d'autre
 *   ne fuit : ni titre, ni réponse, ni points d'autrui avant la révélation
 *   (règle 3, 10 § 7.6).
 * - **Portrait** : une bande horizontale qui défile (`ScrollArea`), avatars
 *   décoratifs à côté des pseudos (I5.9) ; **desktop** (`lg`) : la même
 *   liste en colonne à droite — un élargissement, jamais une autre règle
 *   (règle 10). Les sièges expulsés n'y figurent plus ; déconnectés et
 *   partis y restent, marqués par icône et texte.
 * - **Feuille « Joueurs »** (`panel`) : la liste complète et les gestes de
 *   salon, sans quitter l'écran de manche ni défiler la page. Fermeture
 *   traduite (`common.action.close`), celle que génère `SheetContent` en
 *   anglais étant masquée (90 § 2.5) ; `Échap` ferme ; mouvement réduit.
 * - Rien ici n'est une région vivante : l'arrivée d'un « a trouvé » ne
 *   parle pas (le verrouillage de soi est annoncé par la saisie, 70).
 *
 * Composant de présentation : ni Echo, ni horloge, des props seulement.
 */
export function RoundPlayers({
    seats,
    selfPublicId,
    locked,
    lonePlayer,
    panel,
    className,
}: RoundPlayersProps) {
    const { t, locale } = useTranslations();
    const label = usePlayerLabel();
    const headingId = useId();
    const number = new Intl.NumberFormat(locale);
    const rankOf = new Map(
        locked.map((each) => [each.publicId, each.lockRank]),
    );

    const present = seats.filter((seat) => !seat.kicked);
    const found = present
        .filter((seat) => rankOf.has(seat.publicId))
        .toSorted(
            (left, right) =>
                (rankOf.get(left.publicId) ?? 0) -
                (rankOf.get(right.publicId) ?? 0),
        );
    const ordered = [
        ...found,
        ...present.filter((seat) => !rankOf.has(seat.publicId)),
    ];

    return (
        <section
            aria-labelledby={headingId}
            className={cn('flex min-w-0 flex-col gap-1 lg:w-64', className)}
        >
            <h2 id={headingId} className="sr-only">
                {t('game.round.players')}
            </h2>

            {lonePlayer && (
                <p className="text-sm text-muted-foreground">
                    {t('game.round.lone_player')}
                </p>
            )}

            <div className="flex min-h-0 min-w-0 items-start gap-2 lg:flex-1 lg:flex-col lg:items-stretch">
                {panel !== undefined && panel !== null && (
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                variant="outline"
                                className="min-h-11 shrink-0"
                            >
                                <Users aria-hidden="true" />
                                {t('game.round.players')}
                            </Button>
                        </SheetTrigger>

                        <SheetContent
                            side="bottom"
                            className="max-h-[90dvh] motion-reduce:animate-none! [&>button:last-child]:hidden"
                        >
                            <SheetHeader className="mx-auto w-full max-w-2xl">
                                <SheetTitle>
                                    {t('game.round.players')}
                                </SheetTitle>
                                <SheetDescription>
                                    {t('game.round.players_description')}
                                </SheetDescription>
                            </SheetHeader>

                            <div
                                role="region"
                                aria-label={t('game.round.players')}
                                tabIndex={0}
                                className="mx-auto flex min-h-0 w-full max-w-2xl flex-col gap-4 overflow-y-auto px-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {panel}
                            </div>

                            <SheetFooter className="mx-auto w-full max-w-2xl">
                                <SheetClose asChild>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        {t('common.action.close')}
                                    </Button>
                                </SheetClose>
                            </SheetFooter>
                        </SheetContent>
                    </Sheet>
                )}

                <ScrollArea className="min-w-0 flex-1 lg:min-h-0">
                    <ul className="flex w-max gap-2 pb-1.5 lg:w-full lg:flex-col lg:pb-0">
                        {ordered.map((seat) => {
                            const rank = rankOf.get(seat.publicId);
                            const presence =
                                seat.connection === 'connected'
                                    ? null
                                    : PRESENCE[seat.connection];
                            const Presence = presence?.icon ?? null;

                            return (
                                <li
                                    key={seat.publicId}
                                    className={cn(
                                        'flex min-h-11 items-center gap-2 rounded-md border border-border px-2 py-1',
                                        presence !== null && 'opacity-70',
                                    )}
                                >
                                    <PlayerAvatar
                                        avatar={seat.avatar}
                                        alt=""
                                        className="size-8 text-xs"
                                    />

                                    <span className="max-w-28 min-w-0 truncate text-sm font-medium lg:max-w-none lg:grow lg:contain-inline-size">
                                        {label(seat)}
                                    </span>

                                    {seat.publicId === selfPublicId && (
                                        <Badge variant="secondary">
                                            {t('room.lobby.you')}
                                        </Badge>
                                    )}

                                    {presence !== null && Presence !== null && (
                                        <span className="inline-flex shrink-0 items-center text-muted-foreground">
                                            <Presence
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                            <span className="sr-only">
                                                {t(presence.key)}
                                            </span>
                                        </span>
                                    )}

                                    {rank !== undefined && (
                                        <Badge className="border-transparent bg-success text-success-foreground">
                                            <CircleCheck aria-hidden="true" />
                                            {t('game.round.found')}
                                            <span>
                                                {t(ordinalKey(rank, locale), {
                                                    rank: number.format(rank),
                                                })}
                                            </span>
                                        </Badge>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    <ScrollBar orientation="horizontal" className="h-1.5" />
                </ScrollArea>
            </div>
        </section>
    );
}
