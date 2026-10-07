import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId } from 'react';
import { BrandMark } from '@/components/auth/auth-brand';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { useTranslations } from '@/hooks/use-translations';
import type { SeatView } from '@/types/game-wire';
import type { AvatarData } from '@/types/player';

type CinemaSeatMapProps = {
    seats: readonly SeatView[];
    selfPublicId: string;
    /**
     * Les flèches ‹ › du siège du joueur : avatar précédent ou suivant, envoyé
     * aussitôt (D55 du 02/10, amendé le 06/10). Toujours affichées sur le
     * siège du joueur ; grisées par `avatarBusy`.
     */
    onCycleAvatar: (direction: 1 | -1) => void;
    /**
     * Un changement d'avatar en vol, ou les gestes désactivés (onglet
     * supplanté, connexion temps réel absente) : flèches visibles, inactives.
     */
    avatarBusy: boolean;
    /**
     * L'avatar affiché du siège du joueur, choix optimiste compris
     * (`lobbyAvatarData`) ; nul : celui du salon.
     */
    selfAvatar: AvatarData | null;
    /** Le clic sur l'avatar du joueur : la grille « Votre avatar ». */
    onOpenAvatar: () => void;
};

const OTHER_POSITIONS = [1, 3, 5, 7, 8, 11, 2, 4, 6, 9, 12] as const;
const SELF_POSITION = 10;
const ROOM_SEATS = 12;

/** Salle et placement des sièges identiques à la maquette `waiting-room`. */
export function CinemaSeatMap({
    seats,
    selfPublicId,
    onCycleAvatar,
    avatarBusy,
    selfAvatar,
    onOpenAvatar,
}: CinemaSeatMapProps) {
    const { t } = useTranslations();
    const { name } = usePage().props;
    const headingId = useId();
    const presentSeats = seats.filter(
        (seat) => seat.connection !== 'left' && !seat.kicked,
    );
    const selfSeat = presentSeats.find(
        (seat) => seat.publicId === selfPublicId,
    );
    const otherSeats = presentSeats.filter(
        (seat) => seat.publicId !== selfPublicId,
    );
    const slots = new Map<number, SeatView>();

    if (selfSeat !== undefined) {
        slots.set(SELF_POSITION, selfSeat);
    }

    otherSeats.slice(0, OTHER_POSITIONS.length).forEach((seat, index) => {
        const position = OTHER_POSITIONS[index];

        if (position !== undefined) {
            slots.set(position, seat);
        }
    });

    return (
        <section className="cinema-room" aria-labelledby={headingId}>
            <h2 id={headingId} className="sr-only">
                {t('room.lobby.title')}
            </h2>

            <div className="cinema-room__scene">
                <div className="cinema-room__screen" aria-hidden="true">
                    <div className="cinema-room__screen-content">
                        <BrandMark />
                        <strong>{name}</strong>
                        <span>{t('room.lobby.screen_waiting')}</span>
                    </div>
                </div>

                <div className="cinema-room__tiers" aria-hidden="true">
                    <span className="cinema-room__tier cinema-room__tier--back" />
                    <span className="cinema-room__tier cinema-room__tier--mobile-middle-one" />
                    <span className="cinema-room__tier cinema-room__tier--mobile-middle-two" />
                    <span className="cinema-room__tier cinema-room__tier--front" />
                </div>

                <ul className="cinema-room__seats">
                    {Array.from({ length: ROOM_SEATS }, (_, index) => {
                        const position = index + 1;
                        const seat = slots.get(position);
                        const isSelf = seat?.publicId === selfPublicId;
                        const nickname =
                            seat?.nickname ?? seat?.avatar.initials ?? '';

                        return (
                            <li
                                key={seat?.publicId ?? `empty-${position}`}
                                className={`cinema-seat cinema-seat--position-${position} ${
                                    seat === undefined
                                        ? 'cinema-seat--empty'
                                        : 'cinema-seat--occupied'
                                } ${isSelf ? 'cinema-seat--current' : ''}`}
                                data-disconnected={
                                    seat?.connection === 'disconnected'
                                        ? ''
                                        : undefined
                                }
                                aria-hidden={
                                    seat === undefined ? true : undefined
                                }
                            >
                                <span
                                    className="cinema-seat__chair"
                                    aria-hidden="true"
                                >
                                    <span className="cinema-seat__back" />
                                    <span className="cinema-seat__cushion" />
                                    <span className="cinema-seat__arm cinema-seat__arm--left" />
                                    <span className="cinema-seat__arm cinema-seat__arm--right" />
                                </span>

                                {seat !== undefined && (
                                    <div className="cinema-seat__occupant">
                                        {isSelf && (
                                            <button
                                                type="button"
                                                className="cinema-seat__arrow cinema-seat__arrow--previous"
                                                aria-label={t(
                                                    'room.lobby.avatar.previous',
                                                )}
                                                aria-disabled={
                                                    avatarBusy || undefined
                                                }
                                                onClick={() => {
                                                    if (!avatarBusy) {
                                                        onCycleAvatar(-1);
                                                    }
                                                }}
                                            >
                                                <ChevronLeft aria-hidden="true" />
                                            </button>
                                        )}

                                        {isSelf ? (
                                            <button
                                                type="button"
                                                className="cinema-seat__avatar-button"
                                                aria-label={t(
                                                    'room.lobby.avatar.open',
                                                )}
                                                aria-haspopup="dialog"
                                                aria-disabled={
                                                    avatarBusy || undefined
                                                }
                                                onClick={() => {
                                                    if (!avatarBusy) {
                                                        onOpenAvatar();
                                                    }
                                                }}
                                            >
                                                <PlayerAvatar
                                                    avatar={
                                                        selfAvatar ??
                                                        seat.avatar
                                                    }
                                                    alt=""
                                                    className="player-avatar player-avatar--large"
                                                />
                                            </button>
                                        ) : (
                                            <PlayerAvatar
                                                avatar={seat.avatar}
                                                alt=""
                                                className="player-avatar"
                                            />
                                        )}

                                        {isSelf && (
                                            <button
                                                type="button"
                                                className="cinema-seat__arrow cinema-seat__arrow--next"
                                                aria-label={t(
                                                    'room.lobby.avatar.next',
                                                )}
                                                aria-disabled={
                                                    avatarBusy || undefined
                                                }
                                                onClick={() => {
                                                    if (!avatarBusy) {
                                                        onCycleAvatar(1);
                                                    }
                                                }}
                                            >
                                                <ChevronRight aria-hidden="true" />
                                            </button>
                                        )}

                                        <strong className="cinema-seat__name">
                                            {nickname}
                                            {isSelf && (
                                                <span className="sr-only">
                                                    {' '}
                                                    ({t('room.lobby.you')})
                                                </span>
                                            )}
                                        </strong>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}
