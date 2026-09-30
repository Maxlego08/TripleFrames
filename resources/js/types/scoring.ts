/**
 * Types du score (spec 80 § 9 et § 11, contrat C13 § 2.4 et § 3) : miroirs
 * EXACTS des blocs de données que `App\Support\Scoring\Scoreboard` et les
 * objets valeur `App\ValueObjects\Scoring\*` envoient, et que la spec 60
 * transporte (`round.revealed`, `game.ended`, `GameStatePacket`).
 *
 * Seule déclaration de ces types côté client (R-27), posée en entier dès le
 * lot L80-3, `Podium` compris : `types/game-wire.ts` les importe, jamais ne
 * les redéclare. Les types d'autres contrats sont importés : `PlayerIdentity`
 * (C5, `types/player.ts`), `RevealMovie` et `IsoMs` (C7, `types/game-wire.ts`).
 *
 * Règle 3 : aucun de ces blocs ne porte d'identifiant interne, de nature
 * d'appariement ni de source de réponse ; un siège s'y désigne par son
 * `publicId`, une manche par son `roundNumber`. Seul `Podium.recap` porte des
 * titres, après le gel. Les entiers restent des entiers, les durées sont en
 * millisecondes : le client met tout en forme dans la langue du joueur (05).
 */

import type { IsoMs, RevealMovie } from '@/types/game-wire';
import type { PlayerIdentity } from '@/types/player';

/**
 * Issue figée d'un siège dans la partie, cas de `App\Enums\GamePlayerStatus`.
 * Un siège parti ou expulsé reste classé avec ses points (§ 8.5).
 */
type SeatOutcome = 'playing' | 'left' | 'kicked';

/**
 * La fenêtre d'un palier, `[startsAtOffsetMs, startsAtOffsetMs + durationMs)`
 * en millisecondes depuis le début de la manche, et sa valeur. Publique : les
 * valeurs de palier sont un réglage connu du salon, figé au lancement (C13
 * § 3), diffusé dans `RoundTimeline.tiers` dès la programmation de la manche.
 */
export interface TierWindow {
    tierIndex: number;
    startsAtOffsetMs: number;
    durationMs: number;
    /** 0 à 1 000. */
    points: number;
}

/**
 * Le palier retenu et les points d'une bonne réponse du siège lui-même :
 * CIBLÉ, jamais diffusé (réponse de soumission et vue de saisie de 70).
 */
export interface TierScore {
    tierIndex: number;
    /** 0 à 1 000. */
    pointsTier: number;
    /** 0 à 500. */
    pointsBonus: number;
    /** 0 à 1 500. */
    pointsTotal: number;
}

/**
 * Le score du seul siège demandeur, manche en cours comprise (portée `Own`) :
 * CIBLÉ, resynchronisation HTTP seulement (`SelfState.ownScore` de 60).
 */
export interface SeatScore {
    ownScore: number;
}

/**
 * Une ligne du classement intermédiaire. Ni pseudo ni avatar : le client les
 * lit dans les sièges de 60 (`SeatView`, identité gelée), par `publicId`.
 */
export interface LeaderboardRow {
    publicId: string;
    /** Rang de compétition (1, 2, 2, 4) ; nul en solo ou sans manche jouée. */
    rank: number | null;
    rankShared: boolean;
    /** Portée `Publishable` : manches révélées ou closes seulement. */
    score: number;
    correctAnswers: number;
    roundsPlayed: number;
    /** Somme brute des instants de réponse, en millisecondes. */
    totalAnswerTimeMs: number;
    /** Points du siège sur la manche révélée ; 0 hors révélation. */
    roundDelta: number;
    status: SeatOutcome;
    /**
     * Manche d'entrée du siège (1 au lancement, 10 § 7.3) ; plus grande que 1
     * pour un retardataire, seul cas affiché (`game.leaderboard.late_joiner`) ;
     * nulle pour une ligne antérieure à cette règle.
     */
    firstRoundNumber: number | null;
}

/**
 * Le classement intermédiaire, diffusé dès la révélation d'une manche et
 * présent dans toute resynchronisation — figé à la dernière manche révélée
 * pendant une manche en cours.
 */
export interface Leaderboard {
    /** Tous les paliers valent 0 : le classement commence aux bonnes réponses. */
    scoreless: boolean;
    /** Manche révélée à l'origine du calcul ; nul hors révélation. */
    roundNumber: number | null;
    /** Tous les sièges de la partie, sans plafond, dans l'ordre du classement. */
    rows: LeaderboardRow[];
}

/**
 * Une bonne réponse d'une manche révélée, diffusée dès la révélation et
 * reprise au récapitulatif. Les trouvailles d'une manche sont triées par
 * `lockRank`.
 */
export interface RoundFinder {
    publicId: string;
    lockRank: number;
    tierIndex: number;
    /** Instant de réception serveur, en millisecondes depuis le début de la manche. */
    answeredAtMs: number;
    pointsTier: number;
    pointsBonus: number;
    pointsTotal: number;
}

/**
 * Le paquet de titres d'une manche au récapitulatif : exactement le
 * `RevealMovie` de la révélation (R-24), un titre par locale activée.
 */
export type TitlePacket = RevealMovie;

/**
 * Une entrée du récapitulatif, par numéro de manche : la manche close, ou
 * l'annulation non remplacée, sans titre ni trouvaille.
 */
export type RecapEntry =
    | {
          roundNumber: number;
          outcome: 'completed';
          titles: TitlePacket;
          foundCount: number;
          finders: RoundFinder[];
      }
    | {
          roundNumber: number;
          outcome: 'cancelled';
          titles: null;
          foundCount: number;
          finders: RoundFinder[];
      };

/**
 * Une ligne du classement final : l'identité GELÉE du siège (C5) et ses
 * agrégats figés au gel.
 */
export interface PodiumStanding extends PlayerIdentity {
    status: SeatOutcome;
    firstRoundNumber: number | null;
    /** `game_player.final_rank` ; nul en solo ou sans manche jouée. */
    rank: number | null;
    rankShared: boolean;
    finalScore: number;
    correctAnswers: number;
    roundsPlayed: number;
    totalAnswerTimeMs: number;
}

/**
 * Les trois faits marquants de la partie (D25 du 23/09). Le titre d'un fait
 * se lit dans l'entrée du récapitulatif de même `roundNumber`.
 */
export interface PodiumHighlights {
    bestAnswer: {
        publicId: string;
        roundNumber: number;
        tierIndex: number;
        answeredAtMs: number;
        pointsTotal: number;
    } | null;
    fastestFind: {
        publicId: string;
        roundNumber: number;
        answeredAtMs: number;
    } | null;
    unfoundRoundNumbers: number[];
}

/**
 * Le podium, diffusé par `game.ended` après le gel et rejoué à l'identique
 * par toute resynchronisation jusqu'à l'archivage du salon (en solo, par la
 * prop de la page). Texte seul, sans aucune URL d'image.
 */
export interface Podium {
    gameStatus: 'completed' | 'interrupted';
    mode: 'multiplayer' | 'solo';
    /** `k`, manches closes. */
    roundsCompleted: number;
    /** `M`, manches prévues. */
    roundsCount: number;
    /** `N`, images par manche. */
    framesPerRound: number;
    scoreless: boolean;
    endedAt: IsoMs;
    standings: PodiumStanding[];
    recap: RecapEntry[];
    highlights: PodiumHighlights;
}
