<?php

namespace App\ValueObjects\Answers;

use App\Enums\RoundPlayerInputState;
use App\Enums\SubmissionOutcome;
use App\ValueObjects\Scoring\TierScore;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le verdict d'une soumission, rendu au seul siège qui l'a envoyée (spec 70
 * § 7.7, contrat C10 § 2 et § 3) : destinataire unique, jamais diffusé.
 *
 * Trois corps, et trois seulement :
 *
 * | Issue | Statut | Corps |
 * |---|---|---|
 * | `accepted` | 200 | `{ result, inputState: 'locked', lockRank, tierIndex, pointsTier, pointsBonus, pointsTotal }` |
 * | `rejected` | 200 | `{ result, inputState, attemptsLeft }` |
 * | `closed` | 409 | `{ result, inputState }`, auquel le contrôleur ajoute `message` (`game.answer.closed`) |
 *
 * **Aucun corps ne porte** de titre, d'alias, de nature d'appariement, de
 * forme normalisée ni de distance : un refus pour préfixe ou sous-titre
 * ambigu a exactement le corps d'un refus franc, et rien ne suggère la
 * proximité (décision 13). `attemptsLeft` vaut `attemptsPerRound −
 * wrong_attempts` tant que la saisie est `open`, 0 sinon ; il dépend du
 * **compteur**, jamais de la proximité (invariant L4).
 *
 * `inputState` n'est jamais envoyé qu'au siège lui-même : `qcm_wrong`
 * révélerait une mauvaise réponse (10 § 7.6).
 */
final readonly class SubmissionVerdict
{
    /**
     * @throws InvalidArgumentException Verdict incohérent : acceptation sans
     *                                  rang ni points ou dans un autre état que
     *                                  `locked`, refus ou clôture qui en
     *                                  porteraient, tentatives restantes hors
     *                                  d'un refus `open`.
     */
    public function __construct(
        public SubmissionOutcome $outcome,
        public RoundPlayerInputState $inputState,
        public int $attemptsLeft,
        public ?int $lockRank,
        public ?TierScore $score,
    ) {
        if ($attemptsLeft < 0) {
            throw new InvalidArgumentException('SubmissionVerdict : un nombre de tentatives restantes est positif ou nul.');
        }

        if ($attemptsLeft > 0 && ($outcome !== SubmissionOutcome::Rejected || $inputState !== RoundPlayerInputState::Open)) {
            throw new InvalidArgumentException('SubmissionVerdict : seules les tentatives d\'un refus à saisie ouverte se comptent.');
        }

        $accepted = $outcome === SubmissionOutcome::Accepted;

        if ($accepted && ($inputState !== RoundPlayerInputState::Locked || $lockRank === null || $lockRank < 1 || $score === null)) {
            throw new InvalidArgumentException('SubmissionVerdict : une acceptation est locked, avec son rang et ses points.');
        }

        if (! $accepted && ($lockRank !== null || $score !== null)) {
            throw new InvalidArgumentException('SubmissionVerdict : seule une acceptation porte un rang et des points.');
        }

        if ($outcome === SubmissionOutcome::Rejected && $inputState === RoundPlayerInputState::Locked) {
            throw new InvalidArgumentException('SubmissionVerdict : un refus ne verrouille jamais la saisie.');
        }
    }

    /**
     * La bonne réponse verrouillée : rang d'acquisition du verrou et points
     * rendus tels quels par 80 (spec 70 § 9).
     */
    public static function accepted(int $lockRank, TierScore $score): self
    {
        return new self(SubmissionOutcome::Accepted, RoundPlayerInputState::Locked, 0, $lockRank, $score);
    }

    /**
     * Le refus, identique quelle qu'en soit la cause : l'état relu après
     * l'instruction unique et les tentatives restantes.
     */
    public static function rejected(RoundPlayerInputState $inputState, int $attemptsLeft): self
    {
        return new self(SubmissionOutcome::Rejected, $inputState, $attemptsLeft, null, null);
    }

    /**
     * Saisie ou manche close : rien n'est compté. L'état est celui que le
     * serveur connaît du siège, jamais un état supposé.
     */
    public static function closed(RoundPlayerInputState $inputState): self
    {
        return new self(SubmissionOutcome::Closed, $inputState, 0, null, null);
    }

    /**
     * 200 pour une soumission jugée, acceptée ou refusée ; 409 pour une
     * soumission qui n'a pas été jugée.
     */
    public function httpStatus(): int
    {
        return $this->outcome === SubmissionOutcome::Closed ? Response::HTTP_CONFLICT : Response::HTTP_OK;
    }

    /**
     * Le corps de la réponse, clés camelCase du contrat C10 § 3 ; le `message`
     * d'une clôture est résolu par le contrôleur, dans la locale de la requête.
     *
     * @return array{result: 'accepted', inputState: string, lockRank: int, tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int}|array{result: 'rejected', inputState: string, attemptsLeft: int}|array{result: 'closed', inputState: string}
     *
     * @throws LogicException Acceptation sans rang ni points (écartée par le constructeur).
     */
    public function toArray(): array
    {
        return match ($this->outcome) {
            SubmissionOutcome::Accepted => [
                'result' => SubmissionOutcome::Accepted->value,
                'inputState' => $this->inputState->value,
                'lockRank' => $this->lockRank ?? throw new LogicException('SubmissionVerdict : acceptation sans rang.'),
                ...($this->score ?? throw new LogicException('SubmissionVerdict : acceptation sans points.'))->toArray(),
            ],
            SubmissionOutcome::Rejected => [
                'result' => SubmissionOutcome::Rejected->value,
                'inputState' => $this->inputState->value,
                'attemptsLeft' => $this->attemptsLeft,
            ],
            SubmissionOutcome::Closed => [
                'result' => SubmissionOutcome::Closed->value,
                'inputState' => $this->inputState->value,
            ],
        };
    }
}
