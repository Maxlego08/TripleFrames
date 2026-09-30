<?php

namespace App\Http\Requests\Game;

use App\Actions\Game\SubmitTextAnswer;
use App\Http\Middleware\EnsureActiveSeat;
use App\Settings\RoomSettingsBounds;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Soumission d'une réponse en texte libre — `round.answer.store`, `POST
 * /seat/{player:public_id}/answer` (spec 70 § 7.1, contrat C10 § 2 et § 3).
 *
 * Étape S2 de la séquence : la forme et la longueur **brute** seulement, en
 * caractères. `round` est la `sequence_index` de la manche que le client
 * croit ouverte, jamais `round.id`. Au-delà de la longueur maximale, 422, non
 * compté ; la saisie n'est jamais jugée ici — la normalisation (S5) et le
 * verdict appartiennent à {@see SubmitTextAnswer}.
 *
 * La longueur maximale se lit sur la partie courante du siège, résolue et
 * mise en mémoire par `seat.active` ({@see EnsureActiveSeat::game()}) :
 * `settings_snapshot.maxAnswerLength`, figée au lancement. Sans partie
 * courante, la règle prend la borne haute de `RoomSettingsBounds` et le
 * contrôleur répond 409 `closed` (arbitrage 10 du § 18) : aucun littéral de
 * jeu ici (règle 2).
 */
class AnswerStoreRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'round' => ['required', 'integer', 'min:1'],
            'answer' => ['required', 'string', 'max:'.$this->maxAnswerLength()],
        ];
    }

    /** La manche annoncée, validée. */
    public function roundSequence(): int
    {
        $round = $this->validated('round');

        return is_numeric($round) ? (int) $round : 0;
    }

    /** La saisie brute, validée. */
    public function rawAnswer(): string
    {
        $answer = $this->validated('answer');

        return is_string($answer) ? $answer : '';
    }

    /**
     * `settings_snapshot.maxAnswerLength` de la partie courante du siège, ou
     * la borne haute sans partie courante.
     */
    private function maxAnswerLength(): int
    {
        return EnsureActiveSeat::game($this)?->settings_snapshot->maxAnswerLength
            ?? RoomSettingsBounds::MAX_ANSWER_LENGTH;
    }
}
