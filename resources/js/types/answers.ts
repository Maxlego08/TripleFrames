/**
 * Saisie et QCM (spec 70 § 16, contrats C10 et C11) : charges que le serveur
 * envoie au SEUL siège concerné — réponse HTTP d'une soumission, vue de
 * saisie de la resynchronisation (`SelfState.input`), QCM ciblé
 * (`seat.choices`).
 *
 * Seule déclaration de ces types côté client (R-27) : `types/game-wire.ts`
 * de la spec 60 les importe, jamais ne les redéclare. Déclarations seules,
 * sans aucun import, pour que ce fichier puisse naître avant tout autre.
 *
 * Le client ne juge rien (règle 1) : aucune charge ne porte de titre attendu,
 * d'alias, de nature d'appariement, de forme normalisée, de distance ni
 * l'index de la bonne proposition.
 */

/**
 * État de saisie du siège, cas de `App\Enums\RoundPlayerInputState`. Jamais
 * envoyé qu'au siège lui-même : `qcm_wrong` révélerait une mauvaise réponse.
 * `text_exhausted` (D20 du 23/09) : texte épuisé, QCM attendu, en Normal.
 */
export type InputState =
    | 'open'
    | 'text_exhausted'
    | 'attempts_exhausted'
    | 'locked'
    | 'qcm_wrong'
    | 'revealed'
    | 'skipped';

/**
 * Les quatre propositions du QCM, dans l'ordre propre au siège, rejouées à
 * l'identique (resynchronisation, second onglet, changement de langue), sans
 * aucune métadonnée par proposition. `lang` est la locale effective de la
 * composition (BCP 47), nulle quand les chaînes sortent du titre original ;
 * un clic renvoie la chaîne telle que reçue, jamais un index.
 */
export interface ChoicesPayload {
    choices: [string, string, string, string];
    useOriginalTitle: boolean;
    lang: string | null;
}

/**
 * Vue de la saisie du seul siège demandeur, miroir de
 * `App\ValueObjects\Answers\SeatInputView::toArray()`. `choices` est nul tant
 * que le QCM n'a pas été composé pour ce siège — avant son palier, en Expert,
 * dans le cas terminal ; `locked` est nul tant que le siège n'a pas trouvé.
 */
export interface SeatInputView {
    inputState: InputState;
    attemptsLeft: number;
    choices: ChoicesPayload | null;
    locked: {
        lockRank: number;
        tierIndex: number;
        pointsTier: number;
        pointsBonus: number;
        pointsTotal: number;
    } | null;
}

/**
 * Corps d'une réponse de `round.answer.store` ou `round.choice.store` :
 * acceptée (200), refusée (200, même corps quelle qu'en soit la cause) ou
 * close (409, `message` résolu par le serveur dans la locale de la requête).
 */
export type SubmissionResult =
    | {
          result: 'accepted';
          inputState: 'locked';
          lockRank: number;
          tierIndex: number;
          pointsTier: number;
          pointsBonus: number;
          pointsTotal: number;
      }
    | { result: 'rejected'; inputState: InputState; attemptsLeft: number }
    | { result: 'closed'; inputState: InputState; message: string };
