import { CircleCheck, LogOut, Users, WifiOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { usePlayerLabel } from '@/components/game/player-ordinals';
import { Button } from '@/components/ui/button';
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
import type { SeatView } from '@/types/game-wire';
import type { LeaderboardRow } from '@/types/scoring';
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
     * Le classement publié (`state.leaderboard.rows`) : scores des manches
     * révélées seulement, jamais ceux de la manche en cours (règle 3).
     */
    standings: readonly LeaderboardRow[];
    /** Barème nul partout (`leaderboard.scoreless`) : aucun score affiché. */
    scoreless: boolean;
    /**
     * Salon multijoueur à un seul siège connecté (60 § 9.3) : affichage
     * cosmétique `game.round.lone_player`, jamais « solo ».
     */
    lonePlayer: boolean;
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
 * 00 § Le jeu en une manche) — balisage `player-strip` / `game-player` de
 * la maquette `design-test/html/game.html` : un fauteuil de cinéma par
 * siège, l'avatar posé sur le dossier, le pseudo sur l'assise, le score
 * dessous ; le siège du joueur en bleu, marqué « Vous ».
 *
 * - **Fil « a trouvé »** : un siège qui a trouvé porte une pastille avec
 *   icône **et** texte `game.round.found` et sa position d'arrivée (ordinal
 *   de 80) ; ceux qui ont trouvé passent en tête, dans l'ordre d'arrivée,
 *   les autres suivent l'ordre du classement publié. Rien d'autre ne fuit :
 *   ni titre, ni réponse, ni points de la manche en cours (règle 3, 10
 *   § 7.6) — le score affiché est celui du classement des manches révélées.
 * - **Portrait** : la bande reste à l'écran (règle 10) et défile en
 *   largeur ; desktop : une rangée de fauteuils sous l'écran (maquette).
 *   Les sièges expulsés n'y figurent plus ; déconnectés et partis y restent,
 *   grisés, marqués par icône et texte.
 * - Rien ici n'est une région vivante : l'arrivée d'un « a trouvé » ne
 *   parle pas (le verrouillage de soi est annoncé par la saisie, 70).
 *
 * Composant de présentation : ni Echo, ni horloge, des props seulement.
 */
export function RoundPlayers({
    seats,
    selfPublicId,
    locked,
    standings,
    scoreless,
    lonePlayer,
}: RoundPlayersProps) {
    const { t, tChoice, locale } = useTranslations();
    const label = usePlayerLabel();
    const headingId = useId();
    const number = new Intl.NumberFormat(locale);
    const rankOf = new Map(
        locked.map((each) => [each.publicId, each.lockRank]),
    );
    const standingOf = new Map(
        standings.map((row, index) => [row.publicId, { row, index }]),
    );

    const present = seats.filter((seat) => !seat.kicked);
    const found = present
        .filter((seat) => rankOf.has(seat.publicId))
        .toSorted(
            (left, right) =>
                (rankOf.get(left.publicId) ?? 0) -
                (rankOf.get(right.publicId) ?? 0),
        );
    const others = present
        .filter((seat) => !rankOf.has(seat.publicId))
        .toSorted(
            (left, right) =>
                (standingOf.get(left.publicId)?.index ?? present.length) -
                (standingOf.get(right.publicId)?.index ?? present.length),
        );
    const ordered = [...found, ...others];

    return (
        <section aria-labelledby={headingId} className="player-strip">
            <h2 id={headingId} className="sr-only">
                {t('game.round.players')}
            </h2>

            {lonePlayer && (
                <p className="game__lone">{t('game.round.lone_player')}</p>
            )}

            <ul className="player-strip__list">
                {ordered.map((seat) => {
                    const rank = rankOf.get(seat.publicId);
                    const isSelf = seat.publicId === selfPublicId;
                    const score = standingOf.get(seat.publicId)?.row.score ?? 0;
                    const presence =
                        seat.connection === 'connected'
                            ? null
                            : PRESENCE[seat.connection];
                    const Presence = presence?.icon ?? null;
                    const classes = ['game-player'];

                    if (isSelf) {
                        classes.push('game-player--current');
                    }

                    if (presence !== null) {
                        classes.push('game-player--away');
                    }

                    return (
                        <li key={seat.publicId} className={classes.join(' ')}>
                            {(isSelf || rank !== undefined) && (
                                <span className="game-player__badges">
                                    {isSelf && (
                                        <span className="game-player__you">
                                            {t('room.lobby.you')}
                                        </span>
                                    )}

                                    {rank !== undefined && (
                                        <span className="game-player__found">
                                            <CircleCheck aria-hidden="true" />
                                            {t('game.round.found')}{' '}
                                            {t(ordinalKey(rank, locale), {
                                                rank: number.format(rank),
                                            })}
                                        </span>
                                    )}
                                </span>
                            )}

                            <div className="game-player__seat">
                                <span
                                    className="game-player__seat-back"
                                    aria-hidden="true"
                                />
                                <span
                                    className="game-player__seat-cushion"
                                    aria-hidden="true"
                                />
                                <span
                                    className="game-player__seat-arm game-player__seat-arm--left"
                                    aria-hidden="true"
                                />
                                <span
                                    className="game-player__seat-arm game-player__seat-arm--right"
                                    aria-hidden="true"
                                />

                                <PlayerAvatar
                                    avatar={seat.avatar}
                                    alt=""
                                    className="player-avatar"
                                />

                                <strong className="game-player__name">
                                    {label(seat)}
                                </strong>

                                {presence !== null && Presence !== null && (
                                    <span className="game-player__presence">
                                        <Presence aria-hidden="true" />
                                        <span className="sr-only">
                                            {t(presence.key)}
                                        </span>
                                    </span>
                                )}
                            </div>

                            {!scoreless && (
                                <span className="game-player__score">
                                    {tChoice('game.score.points_short', score, {
                                        count: number.format(score),
                                    })}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

export type PlayersSheetProps = {
    /**
     * Contenu de la feuille « Joueurs » : la liste complète des sièges, les
     * gestes de l'hôte et « Quitter le salon » (50 § 11), atteignables
     * pendant la manche sans la quitter.
     */
    panel: ReactNode;
};

/**
 * La feuille « Joueurs » de l'écran de manche, ouverte depuis la barre du
 * haut (pastille au patron de « Pause ») : sièges et gestes de salon sans
 * quitter la manche ni défiler la page. Fermeture traduite
 * (`common.action.close`), celle que génère `SheetContent` en anglais étant
 * masquée (90 § 2.5) ; `Échap` ferme ; mouvement réduit.
 */
export function PlayersSheet({ panel }: PlayersSheetProps) {
    const { t } = useTranslations();

    return (
        <Sheet>
            <SheetTrigger asChild>
                <button
                    type="button"
                    className="pause-button pause-button--players"
                >
                    <span className="pause-button__icon" aria-hidden="true">
                        <Users />
                    </span>
                    <span className="pause-button__label">
                        {t('game.round.players')}
                    </span>
                </button>
            </SheetTrigger>

            <SheetContent
                side="bottom"
                className="max-h-[90dvh] motion-reduce:animate-none! [&>button:last-child]:hidden"
            >
                <SheetHeader className="mx-auto w-full max-w-2xl">
                    <SheetTitle>{t('game.round.players')}</SheetTitle>
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
                        <Button variant="outline" className="min-h-11">
                            {t('common.action.close')}
                        </Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
