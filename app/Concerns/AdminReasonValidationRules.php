<?php

namespace App\Concerns;

use App\Enums\AdminActionType;
use App\Models\AdminAction;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le MOTIF OBLIGATOIRE d'un geste consigné au journal d'administration —
 * `movie.unpublished` et `movie.content_verified` au jalon 1 (contrat C14,
 * {@see AdminActionType::requiresReason()}).
 *
 * Partagé par les requêtes de dépublication (ou mise à l'écart) d'un film et
 * de la coche « contenu vérifié » (spec 20 § 4.4, § 8.3), et plus tard par
 * les gestes administrateur du jalon 2 qui exigent un motif.
 *
 * Borné par la colonne `admin_action.reason` ({@see AdminAction::REASON_MAX_LENGTH}),
 * que le motif alimente tel quel. Un motif fait d'espaces seulement est
 * refusé comme un motif absent : `TrimStrings` et
 * `ConvertEmptyStringsToNull` le rendent nul avant la règle `required`, et la
 * garde du modèle refuserait de toute façon d'écrire une ligne sans motif.
 */
trait AdminReasonValidationRules
{
    /**
     * @return array<int, ValidationRule|string>
     */
    protected function requiredReasonRules(): array
    {
        return ['required', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH];
    }

    /** Le motif saisi, rogné — non vide une fois la validation passée. */
    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }
}
