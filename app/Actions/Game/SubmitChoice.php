<?php

namespace App\Actions\Game;

use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\InputClosed;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Answers\AcceptanceWindow;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Game\ReceptionInstant;
use App\Support\Game\RoundClock;
use App\ValueObjects\Answers\MatchResult;
use App\ValueObjects\Answers\SubmissionVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le clic d'une proposition du QCM — spec 70 § 7.6, contrat C10 § 2 et § 4
 * (nom et signature figés).
 *
 * **Essai unique et définitif, par siège** (§ 2, A-05) : une proposition
 * cliquée ferme le QCM pour la manche, juste (`locked`) ou fausse
 * (`qcm_wrong`). En Normal, un clic faux ferme **aussi** le texte libre : il
 * a consommé la seule information que le QCM vendait. Les plafonds de
 * tentatives ne concernent que le texte : un clic ne touche jamais
 * `wrong_attempts`. Un siège `text_exhausted` (D20 du 23/09) peut encore
 * cliquer, une fois, dès l'ouverture du QCM.
 *
 * Séquence, dans cet ordre. Les étapes S1 (`seat.active`), S0 (limiteur
 * `answer`, budget `choice`) et S2 (`ChoiceStoreRequest`, et 409 sans partie
 * courante) précèdent l'action :
 *
 * - **S3** — {@see CatchUpGame} à l'instant de RÉCEPTION, jamais au-delà :
 *   l'ouverture du palier du QCM et la composition qui l'accompagne ont eu
 *   lieu avant que S4 ne juge le clic ; puis la manche annoncée, lue par
 *   `(game_id, sequence_index)`, et la fenêtre d'acceptation
 *   ({@see AcceptanceWindow}). Hors fenêtre : 409 `closed`, rien d'écrit ;
 * - **S4** — la participation du siège, puis `acceptsChoice()`, une
 *   difficulté qui a un QCM (jamais Expert), un instant de réception au plus
 *   tôt à l'ouverture du palier `choicesOpenTierIndex(N)` — **sans grâce** :
 *   les propositions n'existaient pas avant la frontière — et un QCM composé
 *   (`round.decoy_movie_id_1` non nul ; en cas terminal, aucun QCM n'existe).
 *   Sinon 409 `closed`, décidé ici **avant tout appel à 80** ;
 * - **S5'** — la ligne `round_choice_set` de la langue de composition du
 *   siège (`round_player.choices_locale`). Une chaîne hors des quatre
 *   propositions, ou un siège sans langue de composition, est une requête
 *   fabriquée : 422 `game.choices.invalid`, rien d'écrit, la saisie reste
 *   ouverte ;
 * - **jugement** — égalité **stricte** avec `choice_1`, sans jamais lire
 *   `answer_key` (10 § 7.8) : une correction de titre entre la composition et
 *   le clic ne change pas le verdict. La chaîne reçue est celle que le
 *   middleware global `TrimStrings` a nettoyée par `Str::trim()`, la fonction
 *   même qui a nettoyé les quatre chaînes à la composition (§ 10.5) ;
 * - **clic juste** — la transaction de verrouillage {@see LockGuess} (§ 9),
 *   en nature `choice`, sans clé, avec `answeredAtMs` calculé une fois : le
 *   plancher du QCM de 80 le crédite au palier du QCM, jamais avant ;
 * - **clic faux** — dans une transaction, `round FOR SHARE` et la fenêtre
 *   relue sous ce verrou (la révélation l'emporte, § 7.3), l'instruction
 *   conditionnelle `qcm_wrong`, puis sa relecture verrouillante par clé
 *   primaire. {@see InputClosed} n'est émis que si l'instruction a touché la
 *   ligne : sur une fermeture concurrente (bonne réponse texte, autre clic),
 *   la réponse est 409 `closed` avec l'état relu, jamais `rejected`.
 *
 * `$receivedAt` est l'instant de réception capturé à l'entrée de la requête
 * ({@see ReceptionInstant}), jamais une valeur du client (L3) ; `$game` est la
 * partie courante du siège, résolue par `seat.active` (contrat C17), jamais
 * nulle ; `$choice` est l'une des quatre chaînes reçues, renvoyée telle
 * quelle, jamais un index.
 */
final readonly class SubmitChoice
{
    public function __construct(
        private CatchUpGame $catchUp,
        private LockGuess $lock,
    ) {}

    /**
     * @param  int  $roundSequence  `sequence_index` de la manche que le client croit ouverte, jamais `round.id`.
     * @param  string  $choice  La proposition cliquée, déjà bornée en longueur par le FormRequest.
     *
     * @throws ValidationException Chaîne absente des quatre propositions du siège (S5') : 422, rien d'écrit.
     */
    public function handle(Player $seat, Game $game, int $roundSequence, string $choice, CarbonImmutable $receivedAt): SubmissionVerdict
    {
        // S3 — le rattrapage à l'instant de réception, puis la manche annoncée.
        $this->catchUp->handle($game, $receivedAt);

        $round = Round::query()
            ->where('game_id', $game->id)
            ->where('sequence_index', $roundSequence)
            ->first();

        // S4 — la participation du siège, lue pour tout verdict de clôture :
        // un siège déjà verrouillé qui clique encore reçoit `locked`.
        $roundPlayer = $round instanceof Round
            ? RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->first()
            : null;

        if (! $round instanceof Round || ! AcceptanceWindow::admits($round, $game, $receivedAt)) {
            return SubmissionVerdict::closed($roundPlayer->input_state ?? RoundPlayerInputState::Open);
        }

        if (! $roundPlayer instanceof RoundPlayer) {
            return SubmissionVerdict::closed(RoundPlayerInputState::Open);
        }

        // L'instant dans la manche, calculé une fois depuis l'instant de
        // réception : l'attente d'un verrou ne fait jamais changer de palier.
        $answeredAtMs = RoundClock::offsetMs($round, $receivedAt);

        if (! $roundPlayer->input_state->acceptsChoice() || ! self::choicesOpen($round, $game, $answeredAtMs)) {
            return SubmissionVerdict::closed($roundPlayer->input_state);
        }

        // S5' — les quatre chaînes de la langue de composition du siège.
        $set = $roundPlayer->choices_locale === null
            ? null
            : RoundChoiceSet::query()
                ->where('round_id', $round->id)
                ->where('locale', $roundPlayer->choices_locale->value)
                ->first();

        if (! $set instanceof RoundChoiceSet || ! in_array($choice, self::choicesOf($set), true)) {
            throw ValidationException::withMessages(['choice' => __('game.choices.invalid')]);
        }

        // Égalité STRICTE avec la chaîne du film cible, jamais `answer_key`.
        if ($choice === $set->choice_1) {
            return $this->lock->handle(
                $round,
                $roundPlayer,
                $game,
                new MatchResult(
                    accepted: true,
                    submittedNormalized: AnswerKeyNormalizer::normalize($choice),
                    answerKeyId: null,
                    answerKeyNormalized: AnswerKeyNormalizer::normalize($set->choice_1),
                    matchKind: GuessMatchKind::Choice,
                    editDistance: 0,
                    prefixWasAmbiguous: false,
                ),
                GuessSource::Choice,
                $receivedAt,
                $answeredAtMs,
            );
        }

        return self::refuse($seat, $game, $round, $roundPlayer, $receivedAt);
    }

    /**
     * Le QCM est-il ouvert à cet instant (§ 7.6, S4) : une difficulté qui en
     * a un, le palier `choicesOpenTierIndex(N)` ouvert — `answeredAtMs ≥
     * starts_at_offset_ms`, sans grâce — et la manche composée.
     *
     * Le palier d'ouverture ne s'écrit jamais en littéral : il se lit par
     * `InputDifficulty::choicesOpenTierIndex()`, seule source, partagée avec
     * le plancher de 80 et l'envoi de 60.
     */
    private static function choicesOpen(Round $round, Game $game, int $answeredAtMs): bool
    {
        $tierIndex = $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round);

        if ($tierIndex === null || $round->decoy_movie_id_1 === null) {
            return false;
        }

        $opensAtOffsetMs = RoundTier::query()
            ->where('round_id', $round->id)
            ->where('tier_index', $tierIndex)
            ->value('starts_at_offset_ms');

        return is_numeric($opensAtOffsetMs) && $answeredAtMs >= (int) $opensAtOffsetMs;
    }

    /**
     * Les quatre chaînes de la ligne, dans l'ordre des colonnes : l'ordre
     * n'importe pas, seule l'appartenance est jugée ici. Elles ne quittent
     * jamais le serveur par ce chemin ({@see ChoicesPresenter} seul les
     * présente).
     *
     * @return list<string>
     */
    private static function choicesOf(RoundChoiceSet $set): array
    {
        return [$set->choice_1, $set->choice_2, $set->choice_3, $set->choice_4];
    }

    /**
     * Le clic faux (§ 7.6) : la saisie passe `qcm_wrong`, texte compris, à
     * l'instant de réception ; `wrong_attempts` n'est pas touché.
     *
     * Dans une transaction, dans l'ordre de verrouillage `round` puis
     * `round_player` (contrat C7 § 4.3) : le verrou PARTAGÉ sur `round`
     * sérialise le clic avec les transitions qui la verrouillent en exclusif
     * — une révélation validée entre S3 et cette transaction ferme la
     * fenêtre, relue ici, et la révélation l'emporte (§ 7.3) — et avec la
     * transaction de verrouillage d'une bonne réponse texte concurrente.
     * L'instruction est conditionnelle (`WHERE input_state IN ('open',
     * 'text_exhausted')`) : un clic faux et une bonne réponse texte
     * concurrents ne donnent jamais `locked` et `qcm_wrong` ensemble.
     *
     * La relecture par clé primaire est VERROUILLANTE, pour tout clic faux :
     * en REPEATABLE READ (InnoDB), elle lit la dernière version validée, là où
     * une lecture simple pourrait rendre un instantané périmé. Si
     * l'instruction n'a rien touché, la réponse est 409 `closed` avec l'état
     * relu — `locked` ou `qcm_wrong` —, et {@see InputClosed} n'est pas émis :
     * la réponse ne dit jamais `rejected` d'un état qu'elle n'a pas écrit.
     */
    private static function refuse(
        Player $seat,
        Game $game,
        Round $round,
        RoundPlayer $roundPlayer,
        CarbonImmutable $receivedAt,
    ): SubmissionVerdict {
        return DB::transaction(static function () use ($seat, $game, $round, $roundPlayer, $receivedAt): SubmissionVerdict {
            $sharedRound = Round::query()->whereKey($round->id)->sharedLock()->firstOrFail();

            $touched = AcceptanceWindow::admits($sharedRound, $game, $receivedAt)
                ? RoundPlayer::query()
                    ->whereKey($roundPlayer->id)
                    ->whereIn('input_state', self::choiceAcceptingValues())
                    ->update([
                        'input_state' => RoundPlayerInputState::QcmWrong->value,
                        'input_closed_at' => $roundPlayer->fromDateTime($receivedAt),
                    ])
                : 0;

            $reread = RoundPlayer::query()->whereKey($roundPlayer->id)->lockForUpdate()->firstOrFail();

            if ($touched === 0) {
                return SubmissionVerdict::closed($reread->input_state);
            }

            // La fin anticipée se réévalue par 60, après commit
            // (`ShouldDispatchAfterCommit`) ; jamais diffusé : `qcm_wrong`
            // révélerait une mauvaise réponse.
            InputClosed::dispatch($round->id, $seat->id, RoundPlayerInputState::QcmWrong);

            return SubmissionVerdict::rejected($reread->input_state, 0);
        });
    }

    /**
     * Les valeurs SQL des états qui acceptent encore un clic — `open` et
     * `text_exhausted` —, lues sur le seul prédicat
     * {@see RoundPlayerInputState::acceptsChoice()}, jamais recopiées.
     *
     * @return list<string>
     */
    private static function choiceAcceptingValues(): array
    {
        return array_values(array_map(
            static fn (RoundPlayerInputState $state): string => $state->value,
            array_filter(
                RoundPlayerInputState::cases(),
                static fn (RoundPlayerInputState $state): bool => $state->acceptsChoice(),
            ),
        ));
    }
}
