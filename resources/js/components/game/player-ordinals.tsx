import { createContext, useContext, useMemo } from 'react';
import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { playerLabel, seatOrdinals } from '@/lib/game/player-label';
import type { SeatOrdinals } from '@/lib/game/player-label';
import type { PlayerIdentity } from '@/types/player';

const PlayerOrdinalsContext = createContext<SeatOrdinals>(new Map());

type PlayerOrdinalsProviderProps = {
    /** Les sièges du salon (`state.seats`), dans l'ordre reçu. */
    seats: readonly Pick<PlayerIdentity, 'publicId'>[];
    children: ReactNode;
};

/**
 * Les rangs des sièges du salon, pour le libellé d'un pseudo masqué
 * (`common.player.masked`, spec 40 § 13.3) : posés une fois par la page, lus
 * par tout composant qui affiche un pseudo — liste des sièges, bande des
 * joueurs, révélation, classement, podium —, pour qu'un même siège porte le
 * même rang partout.
 */
export function PlayerOrdinalsProvider({
    seats,
    children,
}: PlayerOrdinalsProviderProps) {
    const ordinals = useMemo(() => seatOrdinals(seats), [seats]);

    return (
        <PlayerOrdinalsContext.Provider value={ordinals}>
            {children}
        </PlayerOrdinalsContext.Provider>
    );
}

/**
 * Le nom affiché d'une identité de siège : son pseudo, « Joueur n » s'il est
 * masqué, ses initiales s'il a été effacé ({@link playerLabel}).
 */
export function usePlayerLabel(): (
    identity: Pick<
        PlayerIdentity,
        'publicId' | 'nickname' | 'masked' | 'avatar'
    >,
) => string {
    const { t } = useTranslations();
    const ordinals = useContext(PlayerOrdinalsContext);

    return (identity) => playerLabel(identity, ordinals, t);
}
