<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingState;
use App\Enums\ReviewDecision;
use App\Models\Frame;

/**
 * L'état d'une image tel que l'éditeur de la banque l'affiche (spec 20 § 6.1) :
 * en traitement, en échec, en attente de revue, rejetée, en jeu, écartée — et
 * verrouillée par un administrateur.
 *
 * **Dérivé, jamais stocké** : aucune colonne ne le porte, et il n'est pas un
 * cast de schéma. Il se lit sur la frame (disponibilité, état du job,
 * `first_published_at`) et sur la dernière revue portant sur ses octets
 * courants depuis son dernier changement d'état — la décision que
 * {@see self::of()} reçoit, jamais recalculée ici.
 *
 * Ordre de lecture, le premier qui s'applique l'emporte :
 *
 * 1. `locked` — la frame est `suspended` ou `withdrawn` : geste
 *    d'administrateur, aucun geste de curation n'y touche (§ 2.9) ;
 * 2. `set_aside` — écartée : `unpublished` et jamais publiée
 *    (`first_published_at` nul, § 8.4, EN20-1). Elle ne revient jamais en
 *    revue, même en échec ou en traitement ;
 * 3. `processing` — le job n'a pas rendu son verdict ;
 * 4. `failed` — le job a échoué (`processing_error` dit pourquoi) ;
 * 5. `in_play` — publiée, donc servable — même rejetée en re-revue : elle
 *    reste en jeu jusqu'à décision (§ 7.5), et c'est le drapeau
 *    `review_rejected` de l'éditeur qui le dit
 *    ({@see FrameBankSnapshot::reviewRejected()}) ;
 * 6. `rejected` — prête, NON publiée, et sa dernière revue sur ces octets, à
 *    la version courante, depuis son dernier changement d'état, est rejetée.
 *
 * Un état d'AFFICHAGE, pas le prédicat de la liste « Rejetées » du § 7.3,
 * qui compte aussi les images publiées rejetées en re-revue : `ReviewQueue`
 * (lot L20-12) porte ce prédicat-là.
 * 7. `awaiting_review` — prête et sans revue qui la juge : brouillon, ou
 *    image dépubliée que seule une revue remettra en jeu.
 */
enum FrameCurationState: string
{
    case Locked = 'locked';

    case SetAside = 'set_aside';

    case Processing = 'processing';

    case Failed = 'failed';

    case InPlay = 'in_play';

    case Rejected = 'rejected';

    case AwaitingReview = 'awaiting_review';

    /**
     * L'état affiché d'une frame, sa dernière décision de revue lue ailleurs.
     *
     * `$lastDecision` : la décision de la dernière revue à la version courante
     * de la grille, sur `published_hash`, postérieure ou égale à
     * `COALESCE(availability_changed_at, created_at)` — `null` s'il n'y en a
     * aucune.
     */
    public static function of(Frame $frame, ?ReviewDecision $lastDecision): self
    {
        return match (true) {
            in_array($frame->availability, [ContentAvailability::Suspended, ContentAvailability::Withdrawn], true) => self::Locked,
            $frame->availability === ContentAvailability::Unpublished && $frame->first_published_at === null => self::SetAside,
            $frame->processing_state === FrameProcessingState::Pending => self::Processing,
            $frame->processing_state === FrameProcessingState::Failed => self::Failed,
            $frame->availability === ContentAvailability::Published => self::InPlay,
            $lastDecision === ReviewDecision::Rejected => self::Rejected,
            default => self::AwaitingReview,
        };
    }
}
