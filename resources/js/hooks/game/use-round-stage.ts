import { useAnswerSubmission } from '@/hooks/game/use-answer-submission';
import type { AnswerSubmission } from '@/hooks/game/use-answer-submission';
import { useRoundAnnouncements } from '@/hooks/game/use-round-announcements';
import { useRoundClock } from '@/hooks/game/use-round-clock';
import type { RoundClockView } from '@/hooks/game/use-round-clock';
import { serverNow } from '@/lib/game/server-clock';
import { visibleTierValue } from '@/lib/game/store';
import type { GameStore, GameStoreState } from '@/lib/game/store';

export type UseRoundStageOptions = {
    /** L'état du magasin de la page (`useGameState().state`). */
    state: GameStoreState;
    /** Le magasin, qui applique chaque verdict de soumission. */
    store: GameStore;
};

export type RoundStage = {
    /** La manche montrée, son palier courant et son chrono, à la seconde. */
    clock: RoundClockView;
    /** La valeur du palier affichée, masquée (nulle) hors phase `running`. */
    tierValue: number | null;
    /** La saisie de la manche montrée (texte libre et QCM, 70). */
    submission: AnswerSubmission;
};

/**
 * Ce qu'un écran de partie branche sur le temps, une seule fois par page
 * (spec 60 § 2.4 à § 2.6, § 19.3 ; 90 § 7.3 et § 7.4) — `game/lobby` en
 * partie, `game/solo` (L60-16) :
 *
 * - l'**horloge d'affichage** (`useRoundClock`) : la manche montrée, son
 *   palier et son chrono, cadencés sur l'horloge serveur resynchronisée ;
 * - les **annonces de manche** (`useRoundAnnouncements`), sur la chronologie
 *   de la manche montrée : seuils relatifs, nouvelle image, fin sur
 *   l'événement serveur — la page est montée sous `GameLayout`, dont la
 *   région vivante existe avant le premier message ;
 * - la **valeur du palier**, masquée par le seul sélecteur
 *   `visibleTierValue()` du magasin (§ 2.5), jamais par un composant ;
 * - la **soumission** de la manche montrée (`useAnswerSubmission`, 70),
 *   clé (`gameRef`, `sequenceIndex`).
 *
 * Les composants de `components/game/` ne lisent ni Echo ni horloge
 * (C16 § 2.9) : ils reçoivent tout cela en props. Rien ici ne décide
 * (règle 8 reformulée) : aucune saisie ne se ferme, aucun palier ne s'ouvre.
 */
export function useRoundStage(options: UseRoundStageOptions): RoundStage {
    const { state, store } = options;
    const clock = useRoundClock(state);

    useRoundAnnouncements(clock.timeline, serverNow);

    const submission = useAnswerSubmission({
        store,
        publicId: state.self.publicId,
        gameRef: state.gameRef,
        sequenceIndex: clock.round?.sequenceIndex ?? null,
    });

    return {
        clock,
        tierValue: visibleTierValue(state, clock.nowMs),
        submission,
    };
}
