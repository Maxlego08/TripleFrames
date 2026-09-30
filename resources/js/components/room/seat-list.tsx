import { Crown, LogOut, UserX, WifiOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useRef } from 'react';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { SeatActions } from '@/components/room/seat-actions';
import type { RoomGestureContext } from '@/components/room/seat-actions';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import type { SeatView } from '@/types/game-wire';
import type { TranslationKey } from '@/types/translations';

type SeatListProps = {
    /**
     * Les sièges du salon (`state.seats`, `SeatView` de 60), dans l'ordre
     * reçu — `joined_at` croissant au lobby —, partis et expulsés compris.
     */
    seats: readonly SeatView[];
    /** `state.self.publicId` : le siège de ce joueur, marqué « Vous ». */
    selfPublicId: string;
    /** Capacité du salon (`settings.capacity`), pour « Joueurs (n sur c) ». */
    capacity: number;
    /**
     * Les gestes de l'hôte sur les autres sièges (retirer, nommer hôte,
     * § 11.3, § 11.4) : l'hôte seul les reçoit ; `null` pour les autres
     * sièges, qui voient la même liste sans bouton.
     */
    actions?: RoomGestureContext | null;
};

/** État de présence d'un siège qui n'est plus simplement « là ». */
type SeatPresence = 'disconnected' | 'left' | 'kicked';

/** Libellé et icône de chaque état : jamais la seule couleur (principe 8). */
const PRESENCE: Record<
    SeatPresence,
    { key: TranslationKey; icon: LucideIcon }
> = {
    disconnected: { key: 'room.lobby.seat.disconnected', icon: WifiOff },
    left: { key: 'room.lobby.seat.left', icon: LogOut },
    kicked: { key: 'room.lobby.seat.kicked', icon: UserX },
};

function presenceOf(seat: SeatView): SeatPresence | null {
    if (seat.kicked) {
        return 'kicked';
    }

    return seat.connection === 'connected' ? null : seat.connection;
}

type SeatRowProps = {
    seat: SeatView;
    isSelf: boolean;
    actions: RoomGestureContext | null;
    /** Rend le focus à la liste après un geste réussi sur ce siège. */
    onGestureDone: () => void;
};

/**
 * Une ligne de siège : avatar décoratif, pseudo, badges, état ; et, pour
 * l'hôte, ses gestes sur ce siège — jamais sur le sien (il quitte le salon
 * au lieu de se retirer).
 */
function SeatRow({ seat, isSelf, actions, onGestureDone }: SeatRowProps) {
    const { t } = useTranslations();
    const nicknameId = useId();
    const presence = presenceOf(seat);
    const Presence = presence === null ? null : PRESENCE[presence].icon;
    const nickname = seat.nickname ?? seat.avatar.initials;

    return (
        <li className="flex min-h-11 flex-col gap-2 rounded-md border border-border px-3 py-2">
            <div className="flex items-center gap-3">
                <PlayerAvatar
                    avatar={seat.avatar}
                    alt=""
                    className="size-10 text-sm"
                />

                {/* `contain-inline-size` : sans lui, un pseudo long (vingt
                    « W ») donne sa largeur entière à la ligne, qui déborde la
                    colonne d'une zone défilante (même remède qu'E118-7). */}
                <span
                    id={nicknameId}
                    className="min-w-0 flex-1 truncate font-medium contain-inline-size"
                >
                    {nickname}
                </span>

                <span className="flex shrink-0 flex-wrap items-center justify-end gap-1">
                    {seat.isHost && (
                        <Badge>
                            <Crown aria-hidden="true" />
                            {t('room.lobby.host_badge')}
                        </Badge>
                    )}

                    {isSelf && (
                        <Badge variant="secondary">{t('room.lobby.you')}</Badge>
                    )}

                    {presence !== null && Presence !== null && (
                        <Badge variant="outline">
                            <Presence aria-hidden="true" />
                            {t(PRESENCE[presence].key)}
                        </Badge>
                    )}
                </span>
            </div>

            {actions !== null && !isSelf && !seat.kicked && (
                <SeatActions
                    {...actions}
                    seat={seat}
                    nickname={nickname}
                    describedBy={nicknameId}
                    onDone={onGestureDone}
                />
            )}
        </li>
    );
}

/**
 * Liste des sièges du salon (spec 50 § 8.1, 90 § 10) : visible de tous, c'est
 * l'un des deux seuls remèdes produit à la triche à plusieurs sièges, avec
 * l'expulsion (§ 7.1).
 *
 * Chaque siège : son avatar, DÉCORATIF à côté du pseudo affiché (C5 I5.9 : un
 * lecteur d'écran ne lit pas deux fois l'identité), son pseudo en texte, les
 * badges « Hôte » et « Vous », et son état — déconnecté, parti, retiré — par
 * une icône et un texte, jamais par la seule couleur. Le décompte
 * (`room.lobby.players`) est l'effectif PRÉSENT, sièges partis et expulsés
 * exclus (`holdingSeat()`, § 1), rapporté à la capacité ; il peut la
 * dépasser, un siège repris ne consommant aucune place (§ 7.3).
 *
 * L'hôte voit en plus, sur chaque autre siège non retiré, « Retirer du
 * salon » et, s'il est connecté, « Nommer hôte » (`seat-actions.tsx`). Après
 * un geste réussi, le focus revient à la liste : le bouton qui l'a déclenché
 * disparaît avec le siège retiré ou le rôle confié.
 *
 * Composant de présentation : ni Echo ni horloge, des props seulement.
 */
export function SeatList({
    seats,
    selfPublicId,
    capacity,
    actions = null,
}: SeatListProps) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const number = new Intl.NumberFormat(locale);
    const present = seats.filter((seat) => seat.connection !== 'left').length;

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3">
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="text-lg font-semibold outline-none"
            >
                {t('room.lobby.players', {
                    count: number.format(present),
                    capacity: number.format(capacity),
                })}
            </h2>

            <ul className="flex flex-col gap-2">
                {seats.map((seat) => (
                    <SeatRow
                        key={seat.publicId}
                        seat={seat}
                        isSelf={seat.publicId === selfPublicId}
                        actions={actions}
                        onGestureDone={() => headingRef.current?.focus()}
                    />
                ))}
            </ul>
        </section>
    );
}
