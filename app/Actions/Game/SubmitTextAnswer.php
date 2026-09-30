<?php

namespace App\Actions\Game;

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\InputClosed;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Answers\AcceptanceWindow;
use App\Support\Answers\AnswerMatcher;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Game\ReceptionInstant;
use App\Support\Game\RoundClock;
use App\ValueObjects\Answers\SubmissionVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * La soumission d'une réponse en texte libre — spec 70 § 7, contrat C10 § 2
 * et § 4 (nom et signature figés).
 *
 * **Séquence à travail constant (invariant L4)**, dans cet ordre, sans aucune
 * branche qui dépende de la proximité de la réponse. Les étapes S1
 * (`seat.active`), S0 (limiteur `answer`) et S2 (`AnswerStoreRequest`, et
 * 409 sans partie courante) précèdent l'action :
 *
 * - **S3** — {@see CatchUpGame} à l'instant de RÉCEPTION, jamais au-delà :
 *   les transitions échues ont eu lieu, et la soumission ne déclenche jamais
 *   une révélation postérieure à sa réception ; puis la manche annoncée, lue
 *   par `(game_id, sequence_index)`, et la fenêtre d'acceptation
 *   ({@see AcceptanceWindow}). Hors fenêtre : 409 `closed`, rien de compté ;
 * - **S4** — la participation du siège, par `round_player_round_player_uq`,
 *   puis `acceptsText()`, et une difficulté qui a du texte libre (jamais
 *   Facile) : 409 sinon. La participation est lue AVANT le verdict de S3 pour
 *   que toute clôture rende l'état exact du siège — un siège déjà verrouillé
 *   qui soumet encore reçoit `locked` (idempotence) ;
 * - **S5** — {@see AnswerKeyNormalizer::normalize()} : une forme vide est une
 *   erreur de validation (422, `game.answer.unreadable`), non comptée ;
 * - **S6 et S7** — {@see AnswerMatcher::match()} : les deux lectures K et O et
 *   toutes les distances, puis la précédence du verdict ;
 * - **S8a** — refus : une transaction, deux lectures inconditionnelles de
 *   l'état du QCM, **exactement une** instruction `UPDATE` de `round_player`,
 *   sa relecture verrouillante par clé primaire, et **aucune autre
 *   écriture** (J1) ;
 * - **S8b** — acceptation : la transaction de verrouillage {@see LockGuess}
 *   (§ 9), qui revérifie sous verrou la recevabilité et l'état de saisie,
 *   sans réévaluer le verdict, avec `answeredAtMs` =
 *   {@see RoundClock::offsetMs()} de l'instant de réception, calculé une
 *   fois.
 *
 * **Interdits** (§ 7.4) : toute lecture conditionnelle, tout cache, toute
 * sortie anticipée du calcul de distance, tout message, journal ou écriture
 * qui dépendrait du verdict de proximité. Le seul événement du refus,
 * {@see InputClosed}, dépend du **compteur** : il signale une saisie close
 * par épuisement, jamais la proximité de la réponse.
 *
 * `$receivedAt` est l'instant de réception capturé à l'entrée de la requête
 * ({@see ReceptionInstant}), jamais une valeur du client (L3) ; `$game` est la
 * partie courante du siège, résolue par `seat.active` (contrat C17), jamais
 * nulle.
 */
final readonly class SubmitTextAnswer
{
    public function __construct(
        private CatchUpGame $catchUp,
        private AnswerMatcher $matcher,
        private LockGuess $lock,
    ) {}

    /**
     * @param  int  $roundSequence  `sequence_index` de la manche que le client croit ouverte, jamais `round.id`.
     * @param  string  $answer  La saisie brute, déjà bornée en longueur par le FormRequest.
     *
     * @throws ValidationException Saisie vide après normalisation (S5) : 422, non comptée.
     */
    public function handle(Player $seat, Game $game, int $roundSequence, string $answer, CarbonImmutable $receivedAt): SubmissionVerdict
    {
        // S3 — le rattrapage à l'instant de réception, puis la manche annoncée.
        $this->catchUp->handle($game, $receivedAt);

        $round = Round::query()
            ->where('game_id', $game->id)
            ->where('sequence_index', $roundSequence)
            ->first();

        // S4 — la participation du siège, lue pour tout verdict de clôture.
        $roundPlayer = $round instanceof Round
            ? RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->first()
            : null;

        if (! $round instanceof Round || ! AcceptanceWindow::admits($round, $game, $receivedAt)) {
            return SubmissionVerdict::closed($roundPlayer->input_state ?? RoundPlayerInputState::Open);
        }

        if (! $roundPlayer instanceof RoundPlayer) {
            return SubmissionVerdict::closed(RoundPlayerInputState::Open);
        }

        if ($game->input_difficulty === InputDifficulty::Easy || ! $roundPlayer->input_state->acceptsText()) {
            return SubmissionVerdict::closed($roundPlayer->input_state);
        }

        // S5 — une saisie sans lettre ni chiffre n'est pas une tentative.
        $submittedNormalized = AnswerKeyNormalizer::normalize($answer);

        if ($submittedNormalized === '') {
            throw ValidationException::withMessages(['answer' => __('game.answer.unreadable')]);
        }

        // S6 et S7 — deux lectures inconditionnelles, toutes les distances,
        // puis la décision pure.
        $match = $this->matcher->match($round, $submittedNormalized);

        if ($match->accepted) {
            // S8b — la transaction de verrouillage (§ 9). L'instant dans la
            // manche est calculé une fois, depuis l'instant de réception :
            // l'attente du verrou ne fait jamais changer de palier.
            return $this->lock->handle(
                $round,
                $roundPlayer,
                $game,
                $match,
                GuessSource::Text,
                $receivedAt,
                RoundClock::offsetMs($round, $receivedAt),
            );
        }

        // S8a — le refus, quelle qu'en soit la cause.
        return self::refuse($seat, $game, $round, $roundPlayer, $receivedAt);
    }

    /**
     * Le refus (§ 7.5) : dans une transaction, les deux lectures de l'état du
     * QCM, l'instruction unique, puis sa relecture verrouillante par clé
     * primaire.
     *
     * Les deux lectures se font DANS la transaction, jamais sur la manche
     * chargée en S3 : une soumission reçue avant `T_N` et traitée après le
     * commit d'`OpenTier(N)` — requête lente, ou job de `T_N` concurrent —
     * lirait sinon un état périmé, et écrirait `text_exhausted` après que la
     * composition terminale a converti ces sièges. Le verrou PARTAGÉ sur
     * `round` sérialise le refus avec la composition (`round FOR UPDATE` dans
     * la transaction d'`OpenTier`), dans l'ordre `round` puis `round_player`
     * (contrat C7 § 4.3). Elles sont exécutées pour TOUT refus, en toute
     * difficulté : leur nombre ne dépend pas de la proximité.
     */
    private static function refuse(
        Player $seat,
        Game $game,
        Round $round,
        RoundPlayer $roundPlayer,
        CarbonImmutable $receivedAt,
    ): SubmissionVerdict {
        $cap = $game->settings_snapshot->attemptsPerRound;

        // Le palier dont l'ouverture pousse le QCM en Normal, seule difficulté
        // qui lit l'état ci-dessous ; lu en toute difficulté (L4).
        $choicesTierIndex = InputDifficulty::Normal->choicesOpenTierIndex($game->frames_per_round)
            ?? throw new LogicException('SubmitTextAnswer : la difficulté Normal ouvre toujours son QCM à un palier.');

        return DB::transaction(static function () use ($seat, $game, $round, $roundPlayer, $receivedAt, $cap, $choicesTierIndex): SubmissionVerdict {
            $firstDecoyId = Round::query()->whereKey($round->id)->sharedLock()->value('decoy_movie_id_1');
            $choicesTierServedAt = RoundTier::query()
                ->where('round_id', $round->id)
                ->where('tier_index', $choicesTierIndex)
                ->value('served_at');

            $exhaustedState = self::exhaustedState($game, $firstDecoyId !== null, $choicesTierServedAt !== null);

            $touched = self::countWrongAttempt($roundPlayer, $cap, $exhaustedState, $receivedAt);

            // Relecture VERROUILLANTE, pour tout refus : en REPEATABLE READ
            // (InnoDB), la lecture de `served_at` ci-dessus a fixé l'instantané
            // de la transaction. Sur une fermeture concurrente validée après
            // lui, l'instruction ne touche rien et une lecture simple rendrait
            // cet instantané périmé (`open`) ; une lecture verrouillante lit la
            // dernière version validée. Aucun verrou nouveau : l'instruction
            // tient déjà la ligne, et l'ordre `round` puis `round_player` reste.
            $reread = RoundPlayer::query()->whereKey($roundPlayer->id)->lockForUpdate()->firstOrFail();

            // Fermeture concurrente (bonne réponse, plafond déjà atteint) : rien
            // n'a été compté, et le nombre de requêtes est le même.
            if ($touched === 0) {
                return SubmissionVerdict::closed($reread->input_state);
            }

            // La fin anticipée se réévalue par 60 sur le COMPTEUR, jamais sur la
            // proximité ; après commit (`ShouldDispatchAfterCommit`).
            if ($reread->input_state === RoundPlayerInputState::AttemptsExhausted) {
                InputClosed::dispatch($round->id, $seat->id, RoundPlayerInputState::AttemptsExhausted);
            }

            return SubmissionVerdict::rejected(
                $reread->input_state,
                $reread->input_state === RoundPlayerInputState::Open ? $cap - $reread->wrong_attempts : 0,
            );
        });
    }

    /**
     * L'état qu'écrit le refus qui atteint le plafond (§ 3.2, § 7.5) :
     * `attempts_exhausted` hors Normal ; en Normal, `attempts_exhausted`
     * seulement si l'ouverture de `T_N` a été appliquée SANS QCM (composition
     * terminale, § 10.7) — sinon `text_exhausted`, le QCM restant à venir ou
     * à cliquer (D20 du 23/09). `text_exhausted` est donc inatteignable hors
     * Normal.
     */
    private static function exhaustedState(Game $game, bool $choicesComposed, bool $choicesTierOpened): RoundPlayerInputState
    {
        if ($game->input_difficulty !== InputDifficulty::Normal) {
            return RoundPlayerInputState::AttemptsExhausted;
        }

        return $choicesTierOpened && ! $choicesComposed
            ? RoundPlayerInputState::AttemptsExhausted
            : RoundPlayerInputState::TextExhausted;
    }

    /**
     * L'instruction unique du refus (§ 7.5), portable MySQL et SQLite : les
     * affectations d'état PRÉCÈDENT l'incrément, parce que MySQL évalue de
     * gauche à droite quand SQLite lit les valeurs d'origine. Rend le nombre
     * de lignes touchées, 0 sur une saisie close entre-temps.
     *
     * Exécutée par la connexion du modèle, paramètres liés : le constructeur
     * de requêtes n'admet aucune liaison à l'intérieur d'un `CASE`. Les deux
     * instants passent par le format du modèle (`Y-m-d H:i:s.v`) —
     * `fromDateTime()` pour `:receivedAt`, `freshTimestampString()` pour
     * `updated_at` — : aucune milliseconde n'est perdue (10 § 1.2).
     */
    private static function countWrongAttempt(
        RoundPlayer $roundPlayer,
        int $cap,
        RoundPlayerInputState $exhaustedState,
        CarbonImmutable $receivedAt,
    ): int {
        $connection = $roundPlayer->getConnection();
        $grammar = $connection->getQueryGrammar();

        $table = $grammar->wrapTable($roundPlayer->getTable());
        $key = $grammar->wrap($roundPlayer->getKeyName());
        $state = $grammar->wrap('input_state');
        $closedAt = $grammar->wrap('input_closed_at');
        $attempts = $grammar->wrap('wrong_attempts');
        $updatedAt = $grammar->wrap('updated_at');

        $sql = "update {$table} set "
            ."{$state} = case when {$attempts} + 1 >= ? then ? else {$state} end, "
            ."{$closedAt} = case when {$attempts} + 1 >= ? and ? = ? then ? else {$closedAt} end, "
            ."{$attempts} = {$attempts} + 1, "
            ."{$updatedAt} = ? "
            ."where {$key} = ? and {$state} = ? and {$attempts} < ?";

        return $connection->update($sql, [
            $cap,
            $exhaustedState->value,
            $cap,
            $exhaustedState->value,
            RoundPlayerInputState::AttemptsExhausted->value,
            $roundPlayer->fromDateTime($receivedAt),
            $roundPlayer->freshTimestampString(),
            $roundPlayer->id,
            RoundPlayerInputState::Open->value,
            $cap,
        ]);
    }
}
