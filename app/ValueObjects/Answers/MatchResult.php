<?php

namespace App\ValueObjects\Answers;

use App\Enums\GuessMatchKind;
use InvalidArgumentException;

/**
 * Le verdict d'appariement d'une saisie et l'**instantané de règle** qu'une
 * acceptation fige dans `guess` (spec 70 § 6.4 et § 9.3, contrat C10).
 *
 * Une acceptation porte tout ce que le journal conservé douze mois doit dire
 * sans relire aucune table vivante : la clé retenue (`answerKeyId`, nulle pour
 * un clic, `nullOnDelete` en base), sa forme (`answerKeyNormalized`), la forme
 * soumise (`submittedNormalized`), la distance acceptée (`editDistance`, 0 en
 * appariement exact), la nature (`matchKind`, qui dit quelle règle de
 * collision s'appliquait) et l'homonymie publiée à l'instant du match
 * (`prefixWasAmbiguous`, E10-50). Le seuil qui a accepté la distance, lui, se
 * lit par `game.validation_version`, jamais ici.
 *
 * Un refus ne porte **que** la forme soumise : ni clé, ni nature, ni distance,
 * quelle qu'en soit la cause — préfixe ambigu, garde exacte ou saisie
 * lointaine. Rien de ce qui suit un refus ne peut ainsi dépendre de la
 * proximité de la réponse (invariant L4).
 *
 * **Jamais sérialisé vers un client** : la réponse HTTP est
 * `SubmissionVerdict`, qui ne porte ni titre, ni nature, ni forme, ni distance
 * (§ 7.7).
 */
final readonly class MatchResult
{
    /**
     * @throws InvalidArgumentException si l'instantané est incohérent : une
     *                                  acceptation sans nature ni forme de clé,
     *                                  ou un refus qui en porterait une
     */
    public function __construct(
        public bool $accepted,
        public string $submittedNormalized,
        public ?int $answerKeyId,
        public ?string $answerKeyNormalized,
        public ?GuessMatchKind $matchKind,
        public int $editDistance,
        public bool $prefixWasAmbiguous,
    ) {
        if ($editDistance < 0) {
            throw new InvalidArgumentException('Une distance d\'édition est positive ou nulle.');
        }

        if ($accepted && ($matchKind === null || $answerKeyNormalized === null)) {
            throw new InvalidArgumentException('Une acceptation porte sa nature et la forme de la clé retenue.');
        }

        if (! $accepted && ($answerKeyId !== null || $answerKeyNormalized !== null || $matchKind !== null
            || $editDistance !== 0 || $prefixWasAmbiguous)) {
            throw new InvalidArgumentException('Un refus ne porte que la forme soumise.');
        }
    }

    /**
     * Le refus, identique quelle qu'en soit la cause (étapes c et e du § 6.2).
     */
    public static function rejected(string $submittedNormalized): self
    {
        return new self(
            accepted: false,
            submittedNormalized: $submittedNormalized,
            answerKeyId: null,
            answerKeyNormalized: null,
            matchKind: null,
            editDistance: 0,
            prefixWasAmbiguous: false,
        );
    }
}
