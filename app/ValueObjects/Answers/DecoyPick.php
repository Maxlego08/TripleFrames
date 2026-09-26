<?php

namespace App\ValueObjects\Answers;

use App\Support\Answers\DecoyPicker;
use InvalidArgumentException;

/**
 * Les trois leurres d'une manche, tirés par {@see DecoyPicker} (spec 70
 * § 10.3, contrat C11).
 *
 * `movieIds` : exactement {@see self::COUNT} films distincts, **dans l'ordre du
 * tirage**, qui devient celui de `round.decoy_movie_id_1..3` et de
 * `round_choice_set.choice_2..4`. `useOriginalTitle` : mode dégradé, décidé une
 * fois pour tout le salon (`round.choices_use_original_title`).
 *
 * Porte des identifiants de film : **jamais sérialisé**, ni `Arrayable` ni
 * `JsonSerializable` (règle 3). Les leurres ne quittent le serveur que rendus
 * en chaînes par `ChoicesPresenter`.
 */
final readonly class DecoyPick
{
    /**
     * Nombre de leurres : cardinalité du schéma (`round.decoy_movie_id_1..3`,
     * spec 10 § 7.4), jamais un réglage.
     */
    public const int COUNT = 3;

    /**
     * @param  list<int>  $movieIds  Exactement trois identifiants distincts, dans l'ordre du tirage.
     *
     * @throws InvalidArgumentException Nombre, doublon ou identifiant invalide.
     */
    public function __construct(
        public array $movieIds,
        public bool $useOriginalTitle,
    ) {
        if (count($movieIds) !== self::COUNT) {
            throw new InvalidArgumentException(sprintf(
                'DecoyPick : %d leurres, attendu exactement %d.',
                count($movieIds),
                self::COUNT,
            ));
        }

        if (count(array_unique($movieIds)) !== self::COUNT) {
            throw new InvalidArgumentException('DecoyPick : un même film ne peut être deux leurres.');
        }

        foreach ($movieIds as $movieId) {
            if ($movieId < 1) {
                throw new InvalidArgumentException('DecoyPick : identifiant de film invalide.');
            }
        }
    }
}
