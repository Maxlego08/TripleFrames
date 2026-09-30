<?php

namespace App\Http\Requests\Game;

use App\Actions\Game\SubmitChoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Clic d'une proposition du QCM — `round.choice.store`, `POST
 * /seat/{player:public_id}/choice` (spec 70 § 7.1, contrat C10 § 2 et § 3).
 *
 * Étape S2 de la séquence : la forme et la longueur **brute** seulement.
 * `round` est la `sequence_index` de la manche que le client croit ouverte,
 * jamais `round.id` ; `choice` est l'une des quatre chaînes reçues, renvoyée
 * telle quelle, **jamais un index**. Au-delà de la largeur des colonnes
 * `choice_1..4`, 422 : aucune proposition ne peut l'excéder. Le clic n'est
 * jamais jugé ici — l'appartenance aux quatre propositions du siège et
 * l'égalité avec la cible appartiennent à {@see SubmitChoice}.
 *
 * Le siège et sa partie courante sont résolus et mis en mémoire par
 * `seat.active` ; sans partie courante, le contrôleur répond 409 `closed`
 * sans appeler l'action.
 */
class ChoiceStoreRequest extends FormRequest
{
    /**
     * Largeur des colonnes `round_choice_set.choice_1..4` (spec 10 § 7.8) :
     * une propriété du schéma, jamais une valeur de jeu.
     */
    public const int CHOICE_MAX_LENGTH = 255;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'round' => ['required', 'integer', 'min:1'],
            'choice' => ['required', 'string', 'max:'.self::CHOICE_MAX_LENGTH],
        ];
    }

    /** La manche annoncée, validée. */
    public function roundSequence(): int
    {
        $round = $this->validated('round');

        return is_numeric($round) ? (int) $round : 0;
    }

    /** La proposition cliquée, validée, telle que `TrimStrings` l'a nettoyée. */
    public function rawChoice(): string
    {
        $choice = $this->validated('choice');

        return is_string($choice) ? $choice : '';
    }
}
