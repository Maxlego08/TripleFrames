/**
 * Rapport de vivier (spec 30 § 4.2, contrat C2) : miroir client EXACT de
 * `App\Support\Draw\PoolReport::toArray()`.
 *
 * Seule déclaration de ces types côté client (R-27) : les types des réglages
 * de salon et des messages de jeu les importent, jamais ne les redéclarent.
 *
 * Aucune chaîne à afficher : des entiers, des booléens et des codes, que la
 * page rend par les clés `room.pool.cause.*` et `room.pool.remedy.*` (spec 50)
 * dans la langue du joueur, par une table `Record<Valeur, TranslationKey>`.
 * Aucun identifiant interne : `themesPruned` est un booléen, jamais une liste
 * de thèmes.
 */

/**
 * Réglage fautif, sous son nom de champ client (`themeKeys`, jamais
 * `themeIds`) : cas de `App\Enums\PoolFault`, dans l'ordre fixe des causes.
 */
export type PoolFault =
    | 'noRepeatMovies'
    | 'themeKeys'
    | 'framesPerRound'
    | 'roundsCount';

/**
 * Remède proposé : cas de `App\Enums\PoolRemedyKind`, dans l'ordre fixe des
 * remèdes. `disable_no_repeat` est retiré au J1 par le présentateur du salon
 * (D28 du 23/09).
 */
export type PoolRemedyKind =
    | 'open_new_room'
    | 'disable_no_repeat'
    | 'clear_themes'
    | 'lower_frames_per_round'
    | 'reduce_rounds_count';

/** Un remède, qui débloque à lui seul. */
export type PoolRemedy = {
    kind: PoolRemedyKind;
    /** `N` proposé ou `M` réduit ; `null` pour les remèdes sans valeur. */
    value: number | null;
    /** Vivier obtenu en appliquant ce seul remède, en œuvres. */
    count: number;
};

/** Le rapport, identique pour tous les joueurs du salon. */
export type PoolReport = {
    /** Vivier du salon, en œuvres. */
    count: number;
    framesPerRound: number;
    roundsCount: number;
    /** `count < roundsCount` : le lancement est refusé. */
    blocked: boolean;
    /** Vide si non bloqué ; vide ET bloqué : aucun réglage ne suffit. */
    causes: PoolFault[];
    remedies: PoolRemedy[];
    /** Plus grand `N` inférieur atteignant `roundsCount`, si bloqué. */
    nearestPlayableFramesPerRound: number | null;
    /** Un thème demandé n'est plus publié et ne filtre plus rien. */
    themesPruned: boolean;
};
