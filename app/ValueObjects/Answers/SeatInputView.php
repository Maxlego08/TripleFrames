<?php

namespace App\ValueObjects\Answers;

use App\Actions\Game\LockGuess;
use App\Enums\RoundPlayerInputState;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\ChoicesPresenter;
use App\ValueObjects\Scoring\TierScore;
use InvalidArgumentException;
use LogicException;

/**
 * La vue de la saisie d'un siège, destinée à ce seul siège (spec 70 § 16,
 * contrat C10 § 3, R-26) : intégrée par 60 au paquet de resynchronisation
 * comme `SelfState.input` (contrat C7), jamais diffusée.
 *
 * Miroir de `SeatInputView` de `resources/js/types/answers.ts` :
 *
 * - `inputState` : l'état de saisie du siège, relu en base — jamais celui
 *   d'un autre siège : `qcm_wrong` révélerait une mauvaise réponse ;
 * - `attemptsLeft` : `attemptsPerRound − wrong_attempts` si la saisie est
 *   `open`, 0 sinon (formule du § 7.7) ; en Facile, où il n'y a pas de texte,
 *   le client ne l'affiche pas ;
 * - `choices` : {@see ChoicesPresenter::forSeat()}, seule voie de sortie des
 *   quatre chaînes — rejouées à l'identique si le siège a une langue de
 *   composition, composées par le cas défensif si la manche l'est et que le
 *   siège accepte encore un clic, NULL sinon (avant le QCM, Expert, cas
 *   terminal) ;
 * - `locked` : le rang et les points du siège, lus sur SA ligne `guess`
 *   ({@see TierScore::fromGuess()} plus `lock_rank`), NULL tant qu'il n'a pas
 *   trouvé.
 *
 * Aucun identifiant interne, aucun titre, aucun index ni drapeau de la bonne
 * proposition, aucune donnée d'un autre siège.
 */
final readonly class SeatInputView
{
    /**
     * @throws InvalidArgumentException Vue incohérente : tentatives restantes
     *                                  négatives ou hors d'une saisie `open`,
     *                                  rang sans points (ou l'inverse), ou
     *                                  verrouillage hors de l'état `locked`.
     */
    public function __construct(
        public RoundPlayerInputState $inputState,
        public int $attemptsLeft,
        public ?ChoicesPayload $choices,
        public ?int $lockRank,
        public ?TierScore $score,
    ) {
        if ($attemptsLeft < 0) {
            throw new InvalidArgumentException('SeatInputView : un nombre de tentatives restantes est positif ou nul.');
        }

        if ($attemptsLeft > 0 && $inputState !== RoundPlayerInputState::Open) {
            throw new InvalidArgumentException('SeatInputView : seules les tentatives d’une saisie ouverte se comptent.');
        }

        if (($lockRank === null) !== ($score === null)) {
            throw new InvalidArgumentException('SeatInputView : un verrouillage porte son rang et ses points, ensemble.');
        }

        if (($lockRank !== null) !== ($inputState === RoundPlayerInputState::Locked)) {
            throw new InvalidArgumentException('SeatInputView : un rang et des points si et seulement si la saisie est locked.');
        }

        if ($lockRank !== null && $lockRank < 1) {
            throw new InvalidArgumentException('SeatInputView : un rang de verrouillage commence à 1.');
        }
    }

    /**
     * La vue de ce siège pour sa participation à cette manche.
     *
     * La participation est relue en base par sa clé : l'instance passée peut
     * être périmée — une composition terminale a pu faire passer un siège
     * `text_exhausted` en `attempts_exhausted` depuis sa lecture.
     *
     * **Ordre des lectures** : la ligne `guess` d'abord, la participation
     * ensuite. La resynchronisation qui appelle cette vue ne tient aucune
     * transaction, et {@see LockGuess} valide `guess` et `locked` dans une
     * seule : un `guess` vu en premier implique donc
     * `locked` lu ensuite, et un `locked` lu sans `guess` signifie que ce
     * commit est tombé entre les deux lectures — la ligne `guess` est alors
     * relue, une fois. Seule une rupture qui survit à cette relecture est une
     * corruption. Pas de transaction ici : en REPEATABLE READ, son instantané
     * rendrait périmée la relecture qui suit l'écriture conditionnelle du cas
     * défensif de {@see ChoicesPresenter}.
     *
     * @throws LogicException Invariant `locked` ⟺ ligne `guess` rompu (10 § 7.6, A16).
     */
    public static function forSeat(RoundPlayer $roundPlayer): self
    {
        $guess = self::guessOf($roundPlayer);
        $current = RoundPlayer::query()->findOrFail($roundPlayer->id);

        if ($current->input_state === RoundPlayerInputState::Locked && ! $guess instanceof Guess) {
            $guess = self::guessOf($current);
        }

        $round = Round::query()->findOrFail($current->round_id);
        $game = Game::query()->findOrFail($round->game_id);

        $locked = $current->input_state === RoundPlayerInputState::Locked;

        if ($locked !== $guess instanceof Guess) {
            throw new LogicException(sprintf(
                'SeatInputView : la saisie %s d’une participation à la manche %d %s de ligne guess.',
                $current->input_state->value,
                $round->sequence_index,
                $guess instanceof Guess ? 'porte' : 'ne porte pas',
            ));
        }

        $attemptsLeft = $current->input_state === RoundPlayerInputState::Open
            ? max(0, $game->settings_snapshot->attemptsPerRound - $current->wrong_attempts)
            : 0;

        return new self(
            inputState: $current->input_state,
            attemptsLeft: $attemptsLeft,
            choices: app(ChoicesPresenter::class)->forSeat($current),
            lockRank: $guess?->lock_rank,
            score: $guess instanceof Guess ? TierScore::fromGuess($guess) : null,
        );
    }

    /**
     * La ligne `guess` de ce siège pour cette manche, ou NULL : le couple
     * `(round_id, player_id)` ne change jamais pour une participation.
     */
    private static function guessOf(RoundPlayer $roundPlayer): ?Guess
    {
        return Guess::query()
            ->where('round_id', $roundPlayer->round_id)
            ->where('player_id', $roundPlayer->player_id)
            ->first();
    }

    /**
     * La forme cliente, à la lettre du contrat C10 § 3 (clés camelCase, dans
     * l'ordre du type) : `locked` = `lockRank` puis les quatre sorties de
     * {@see TierScore::toArray()}.
     *
     * @return array{inputState: string, attemptsLeft: int, choices: array{choices: list<string>, useOriginalTitle: bool, lang: string|null}|null, locked: array{lockRank: int, tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int}|null}
     */
    public function toArray(): array
    {
        return [
            'inputState' => $this->inputState->value,
            'attemptsLeft' => $this->attemptsLeft,
            'choices' => $this->choices?->toArray(),
            'locked' => $this->lockRank === null || $this->score === null
                ? null
                : ['lockRank' => $this->lockRank, ...$this->score->toArray()],
        ];
    }
}
