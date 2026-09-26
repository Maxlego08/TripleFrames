<?php

namespace App\ValueObjects\Answers;

use App\Enums\Locale;
use App\Support\Answers\ChoicesPresenter;
use InvalidArgumentException;

/**
 * Les quatre propositions du QCM telles qu'un siège les reçoit (spec 70
 * § 10.8, contrat C11) — charge de `seat.choices` et de
 * `SelfState.input.choices`, destinataire unique.
 *
 * **Tout ce qu'elle porte, et rien d'autre** (règle 3, A-44, 05 § QCM) :
 *
 * - `choices` : les quatre chaînes, **déjà permutées pour ce siège** — l'ordre
 *   reçu est l'ordre affiché, et aucune position n'est conventionnelle ;
 * - `useOriginalTitle` : le seul drapeau, `round.choices_use_original_title`,
 *   commun à tout le salon ;
 * - `lang` : la locale effective atteinte à la composition
 *   (`round_choice_set.rendered_locale`), NULL quand les chaînes sortent de
 *   `title_original`.
 *
 * Aucun identifiant (film, manche, siège), aucun index ni drapeau de la bonne
 * réponse, aucune métadonnée par proposition. Construite par
 * {@see ChoicesPresenter} seul ; ni `Arrayable` ni `JsonSerializable` : la
 * forme cliente est celle de {@see self::toArray()}, et elle seule.
 */
final readonly class ChoicesPayload
{
    /**
     * Nombre de propositions : la cible plus les trois leurres, cardinalité
     * du schéma (`round_choice_set.choice_1..4`, spec 10 § 7.8), jamais un
     * réglage.
     */
    public const int COUNT = DecoyPick::COUNT + 1;

    /**
     * @param  list<string>  $choices  Exactement quatre chaînes non vides et distinctes, dans l'ordre d'affichage du siège.
     * @param  bool  $useOriginalTitle  Mode dégradé du salon.
     * @param  Locale|null  $lang  Locale effective atteinte ; NULL = titre original.
     *
     * @throws InvalidArgumentException Nombre de chaînes, chaîne vide ou doublon.
     */
    public function __construct(
        public array $choices,
        public bool $useOriginalTitle,
        public ?Locale $lang,
    ) {
        if (count($choices) !== self::COUNT) {
            throw new InvalidArgumentException(sprintf(
                'ChoicesPayload : %d propositions, attendu exactement %d.',
                count($choices),
                self::COUNT,
            ));
        }

        if (in_array('', $choices, true)) {
            throw new InvalidArgumentException('ChoicesPayload : une proposition est vide.');
        }

        // Deux chaînes égales rendraient un clic ambigu : la composition les
        // garantit distinctes, jusque dans leur forme normalisée.
        if (count(array_unique($choices)) !== self::COUNT) {
            throw new InvalidArgumentException('ChoicesPayload : deux propositions sont identiques.');
        }
    }

    /**
     * La charge cliente, à la lettre du contrat C11 : `lang` est le code
     * BCP 47 de la locale atteinte, jamais la valeur d'enum brute.
     *
     * @return array{choices: list<string>, useOriginalTitle: bool, lang: string|null}
     */
    public function toArray(): array
    {
        return [
            'choices' => $this->choices,
            'useOriginalTitle' => $this->useOriginalTitle,
            'lang' => $this->lang?->bcp47(),
        ];
    }
}
