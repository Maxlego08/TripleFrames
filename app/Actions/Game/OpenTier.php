<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\InputClosed;
use App\Events\Game\SeatChoicesOffered;
use App\Events\Game\TierOpened;
use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Game\GameJournal;
use App\Support\Game\RoundStep;
use App\Support\Game\TierImageRefPresenter;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Answers\ChoicesPayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;

/**
 * L'ouverture d'un palier à `Tᵢ` — spec 60 § 6.3, contrat C8 § 2 et § 4.3
 * (nom et signature figés).
 *
 * **Seule écrivaine** de `round_tier.served_at` et de `seen_frame` (C8 § 2,
 * E10-47 ; prouvé par `TierServingWritersTest`). Sous les verrous `game` →
 * `round` → `round_tier` (ordre global room → player → game → round →
 * round_player, § 4.5), dans cet ordre :
 *
 * 1. **péremption** (§ 4.3) : partie close ou en pause, manche ni `pending`
 *    ni `running` ou non programmée, palier dont `Tᵢ ≥ ended_at` (fin
 *    anticipée) : RIEN — ni `served_at`, ni `seen_frame`, ni frappe du
 *    palier suivant, ni composition du QCM, ni diffusion. Un palier dont
 *    `Tᵢ` n'est pas atteint, ou déjà ouvert (`served_at` non nul), n'écrit
 *    rien non plus ;
 * 2. **revérification** de la frame frappée : non servable →
 *    `CancelRound(frame_unavailable)`, **jamais de seconde substitution**
 *    (C8 § 4.3), fin ;
 * 3. **`i = 1`** : `round.status = running` ; une ligne `round_player`
 *    (`input_state = open`) pour chaque **siège non parti** éligible de
 *    `game_player` — `status ≠ kicked`, `first_round_number` nul ou
 *    `≤ round_number` (E10-49) : un joueur déconnecté cinq secondes à `T₁`
 *    répond à son retour ;
 * 4. **QCM** au palier `InputDifficulty::choicesOpenTierIndex(N)` — `T₁` en
 *    Facile, `T_N` en Normal, jamais en Expert — : {@see ComposeChoiceSets}
 *    compose (ou rejoue, idempotente) les quatre propositions à l'instant
 *    **théorique** `Tᵢ`, APRÈS la naissance des lignes `round_player` de
 *    l'étape 3 (contrat C7 § 4.8 : dans l'ordre inverse, aucun siège ne
 *    recevrait `choices_locale`). Cas terminal (contrat C11) : en **Facile**,
 *    `CancelRound(choices_unavailable)` (E10-07), fin ; en **Normal**, la
 *    manche continue en saisie texte seule, la composition ayant fermé les
 *    sièges `text_exhausted` — leurs `InputClosed` sont retenus jusqu'après
 *    la transaction et ses rappels (étape 7) : la fin anticipée qu'ils
 *    peuvent déclencher suit toujours `tier.opened` et la programmation de
 *    la frontière suivante ;
 * 5. **`i < N`** : frappe du palier `i+1` ({@see MintTierServeToken}), un cran
 *    à l'avance ; si cette frappe annule la manche, fin, sans `served_at(i)`
 *    ni `seen_frame` ;
 * 6. `served_at(i)` = **instant théorique** `Tᵢ`, même si le job est en
 *    retard ; en multijoueur seulement, upsert de `seen_frame(room_id,
 *    served_frame_id, last_seen_at = served_at)` (10 § 7.9) — sur la variante
 *    réellement servie, jamais sur celle du tirage ;
 * 7. après commit : en multijoueur, `tier.opened` `{ sequenceIndex,
 *    roundNumber, tierIndex, opensAt, next }` (`next` = palier `i+1` frappé
 *    à l'étape 5, nul au dernier palier), diffusion de frontière mesurée
 *    contre `Tᵢ` ; puis, au palier du QCM et **seulement si la composition a
 *    rendu vrai**, un `seat.choices` CIBLÉ par participation dont la saisie
 *    accepte un clic (`open` et `text_exhausted`, D20 du 23/09) et dont le
 *    siège n'est ni parti ni expulsé, déconnectés compris (§ 8.3) — charge
 *    `{ sequenceIndex }` + {@see ChoicesPresenter::forSeat()}, composée ici
 *    sous le verrou, jamais une diffusion au salon (règle 3) ; en solo,
 *    rien : le QCM n'y part que par `solo.state` ; le job de l'étape
 *    suivante — `OpenTier(i+1)` à `Tᵢ₊₁`, ou `Close` à `started_at + D` ;
 *    l'ouverture d'une manche (`i = 1`) au journal `game` (§ 4.7).
 *
 * **Toute annulation décidée ici précède l'écriture de `served_at(i)`** : un
 * palier dont l'ouverture annule la manche n'est jamais marqué servi.
 *
 * **Ordre à instant égal** (§ 4.1, contrat C7 § 4.14) : `EndReveal(k)` précède
 * `OpenTier(k+1, 1)`. Précision de 60 appliquée ici : l'ouverture d'un
 * palier 1 attend — sans rien écrire, sans se périmer — tant qu'une manche de
 * la partie est encore en `revealing`. Comme `T₁(k+1) ≥ reveal_ends_at(k)`
 * par construction (enchaînement, remplacement, « manche suivante »), la fin
 * de révélation de `k` est alors échue elle aussi : l'ordre tient quel que
 * soit l'ordre des appels, dans le job comme dans le rattrapage.
 *
 * **Paliers ouverts dans l'ordre** : le jeton du palier `i ≥ 2` est frappé par
 * `OpenTier(i−1)` ; ouvrir un palier non frappé, ou un palier `i ≥ 2` d'une
 * manche qui n'a pas démarré, est un défaut de l'appelant (`LogicException`).
 */
final readonly class OpenTier
{
    /**
     * Colonnes que l'ouverture écrit, recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array OPENED_COLUMNS = ['served_at', 'updated_at'];

    public function __construct(
        private MintTierServeToken $mint,
        private ComposeChoiceSets $composeChoices,
        private ChoicesPresenter $choices,
    ) {}

    /**
     * Au retour, l'instance reçue porte `served_at` tel qu'écrit (par cet
     * appel ou un appel antérieur), nul si l'étape était périmée, non échue ou
     * si elle a annulé la manche.
     *
     * @param  CarbonImmutable  $now  Instant d'exécution de l'étape : échéance, fenêtre de
     *                                mémoire du salon pour la frappe, instant d'une annulation.
     *
     * @throws LogicException Palier non frappé, ou palier `i ≥ 2` d'une manche
     *                        qui n'a pas démarré.
     */
    public function handle(RoundTier $tier, CarbonImmutable $now): void
    {
        // La partie est lue AVANT la transaction de l'étape : en REPEATABLE
        // READ (InnoDB), la première lecture non verrouillante d'une
        // transaction fixe son instantané (30 § 6.5). Faite après les verrous,
        // la lecture des sièges voit tout ce qui a été validé avant eux.
        $gameId = (int) Round::query()->whereKey($tier->round_id)->value('game_id');

        // Les clôtures de saisie de l'étape 4 (cas terminal du QCM en Normal)
        // sont émises dans la transaction IMBRIQUÉE de la composition, dont
        // les rappels après commit partent avant ceux de cette transaction
        // (E90-4) : leur écouteur réévaluerait la fin anticipée — et
        // annoncerait `round.closed` — avant `tier.opened` et avant la
        // programmation de la frontière suivante, qu'une exception de
        // l'écouteur empêcherait. Elles sont donc retenues jusqu'après la
        // transaction et ses rappels (E108-1).
        Event::defer(fn () => $this->open($tier, $now, $gameId), [InputClosed::class]);
    }

    /**
     * Les étapes 1 à 7, dans une seule transaction.
     *
     * @throws LogicException
     */
    private function open(RoundTier $tier, CarbonImmutable $now, int $gameId): void
    {
        DB::transaction(function () use ($tier, $now, $gameId): void {
            $lockedGame = Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
            $lockedRound = Round::query()->whereKey($tier->round_id)->lockForUpdate()->firstOrFail();
            // Garde d'idempotence relue sous verrou, jamais par une lecture
            // simple (E90-7).
            $lockedTier = RoundTier::query()->whereKey($tier->id)->lockForUpdate()->firstOrFail();

            $lockedRound->setRelation('game', $lockedGame);
            $lockedTier->setRelation('round', $lockedRound);

            $opensAt = self::dueOpening($lockedGame, $lockedRound, $lockedTier, $now);

            if (! $opensAt instanceof CarbonImmutable) {
                self::reflect($tier, $lockedTier);

                return;
            }

            // 2. Jamais de seconde substitution : la frame frappée est la
            // seule candidate à l'ouverture.
            $servedFrame = $lockedTier->served_frame_id === null ? null : Frame::query()->find($lockedTier->served_frame_id);

            if (! $servedFrame instanceof Frame || ! $servedFrame->isServable()) {
                app(CancelRound::class)->handle($lockedRound, RoundIncidentReason::FrameUnavailable, $now);
                self::reflect($tier, $lockedTier);

                return;
            }

            // 3. La manche démarre, et ses participants naissent (E10-49).
            if ($lockedTier->tier_index === 1) {
                $lockedRound->forceFill(['status' => RoundStatus::Running])->save();
                self::seatParticipants($lockedGame, $lockedRound);
            }

            // 4. Le QCM, au palier qui l'ouvre, après les participants : son
            // cas terminal annule la manche en Facile, jamais en Normal.
            $choicesComposed = $this->composeChoicesIfDue($lockedGame, $lockedRound, $lockedTier, $opensAt);

            if ($choicesComposed === false && $lockedGame->input_difficulty === InputDifficulty::Easy) {
                app(CancelRound::class)->handle($lockedRound, RoundIncidentReason::ChoicesUnavailable, $now);
                self::reflect($tier, $lockedTier);

                return;
            }

            // 5. Frappe un cran à l'avance ; une annulation clôt l'étape.
            $nextTier = $this->mintNext($lockedRound, $lockedTier, $now);

            if (Round::query()->whereKey($lockedRound->id)->value('status') === RoundStatus::Cancelled) {
                self::reflect($tier, $lockedTier);

                return;
            }

            // 6. L'instant THÉORIQUE, jamais l'heure d'exécution.
            $lockedTier->forceFill(['served_at' => $opensAt])->save();

            if ($lockedGame->mode === GameMode::Multiplayer) {
                self::rememberServedFrame($lockedGame, $lockedTier, $opensAt);
            }

            self::reflect($tier, $lockedTier);

            if ($lockedTier->tier_index === 1) {
                GameJournal::roundOpened($lockedGame, $lockedRound, $opensAt);
            }

            // 7. Après commit : la diffusion, le QCM ciblé, puis la frontière
            // suivante.
            if ($lockedGame->mode === GameMode::Multiplayer) {
                event((new TierOpened(self::room($lockedGame), $lockedGame, [
                    'sequenceIndex' => $lockedRound->sequence_index,
                    'roundNumber' => (int) $lockedRound->round_number,
                    'tierIndex' => $lockedTier->tier_index,
                    'opensAt' => WireTime::iso($opensAt),
                    'next' => $nextTier instanceof RoundTier ? TierImageRefPresenter::image($lockedGame, $nextTier) : null,
                ]))->atBoundary($opensAt));

                if ($choicesComposed === true) {
                    $this->offerChoices($lockedGame, $lockedRound);
                }
            }

            self::scheduleNextBoundary($lockedGame, $lockedRound, $nextTier);
        });
    }

    /**
     * L'instant théorique `Tᵢ` si l'étape est valide, échue et pas encore
     * faite ; `null` sinon (§ 4.3).
     *
     * @throws LogicException
     */
    private static function dueOpening(Game $lockedGame, Round $lockedRound, RoundTier $lockedTier, CarbonImmutable $now): ?CarbonImmutable
    {
        // Toute étape de manche d'une partie close ou en pause est périmée
        // (écart (e) du § 22 bis).
        if ($lockedGame->ended_at !== null || $lockedGame->status !== GameStatus::Running) {
            return null;
        }

        if (! in_array($lockedRound->status, [RoundStatus::Pending, RoundStatus::Running], true)) {
            return null;
        }

        $opensAt = $lockedTier->absoluteStartsAt();

        // Manche déprogrammée (pause) ou jamais programmée : rien à ouvrir.
        if (! $opensAt instanceof CarbonImmutable) {
            return null;
        }

        // Déjà faite.
        if ($lockedTier->served_at !== null) {
            return null;
        }

        // Fin anticipée : un palier qui s'ouvrirait à `ended_at` ou après ne
        // s'ouvre jamais (C8 § 4.3).
        if ($lockedRound->ended_at !== null && $opensAt->greaterThanOrEqualTo($lockedRound->ended_at)) {
            return null;
        }

        // Pas encore échue.
        if ($opensAt->greaterThan($now)) {
            return null;
        }

        if ($lockedTier->tier_index > 1 && $lockedRound->status !== RoundStatus::Running) {
            throw new LogicException(sprintf(
                'OpenTier : le palier %d de la manche %d s’ouvre avant son palier 1.',
                $lockedTier->tier_index,
                $lockedRound->sequence_index,
            ));
        }

        if ($lockedTier->serve_token === null) {
            throw new LogicException(sprintf(
                'OpenTier : le palier %d de la manche %d n’est pas frappé ; il l’est par la programmation (palier 1) ou par l’ouverture du palier précédent.',
                $lockedTier->tier_index,
                $lockedRound->sequence_index,
            ));
        }

        // Ordre à instant égal : la fin de révélation d'une autre manche
        // précède l'ouverture d'un palier 1 (§ 4.1). Rien d'écrit : l'étape
        // reste due, et s'exécutera après elle.
        if ($lockedTier->tier_index === 1 && Round::query()
            ->where('game_id', $lockedGame->id)
            ->where('status', RoundStatus::Revealing->value)
            ->exists()) {
            return null;
        }

        return $opensAt;
    }

    /**
     * Une ligne `round_player` (`open`) par siège non parti éligible (E10-49) :
     * `game_player.status ≠ kicked`, entré à cette manche ou avant, siège
     * `connected` ou `disconnected`, ni parti ni expulsé.
     */
    private static function seatParticipants(Game $lockedGame, Round $lockedRound): void
    {
        $roundNumber = (int) $lockedRound->round_number;

        $playerIds = GamePlayer::query()
            ->where('game_id', $lockedGame->id)
            ->where('status', '<>', GamePlayerStatus::Kicked->value)
            ->where(static function (Builder $query) use ($roundNumber): void {
                $query->whereNull('first_round_number')->orWhere('first_round_number', '<=', $roundNumber);
            })
            ->whereIn('player_id', Player::query()
                ->whereIn('connection_state', [PlayerConnectionState::Connected->value, PlayerConnectionState::Disconnected->value])
                ->whereNull('left_at')
                ->whereNull('kicked_at')
                ->select('id'))
            ->orderBy('id')
            ->pluck('player_id')
            ->all();

        if ($playerIds === []) {
            return;
        }

        $stamp = (new RoundPlayer)->freshTimestampString();

        RoundPlayer::query()->insert(array_map(
            static fn (int $playerId): array => [
                'round_id' => $lockedRound->id,
                'player_id' => $playerId,
                'input_state' => RoundPlayerInputState::Open->value,
                'wrong_attempts' => 0,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ],
            array_map(intval(...), $playerIds),
        ));
    }

    /**
     * L'étape QCM (§ 6.3, étape 4) : `null` hors du palier qui ouvre le QCM
     * (donc toujours en Expert) ; sinon ce que rend {@see ComposeChoiceSets}
     * — vrai si les quatre propositions existent, faux dans le cas terminal.
     * L'instant passé est l'instant THÉORIQUE `Tᵢ`, jamais l'heure
     * d'exécution : les leurres ne dépendent pas du retard d'un job.
     */
    private function composeChoicesIfDue(Game $lockedGame, Round $lockedRound, RoundTier $lockedTier, CarbonImmutable $opensAt): ?bool
    {
        $choicesTierIndex = $lockedGame->input_difficulty->choicesOpenTierIndex($lockedGame->frames_per_round);

        if ($choicesTierIndex !== $lockedTier->tier_index) {
            return null;
        }

        return $this->composeChoices->handle($lockedRound, $opensAt);
    }

    /**
     * Un `seat.choices` CIBLÉ par participation qui accepte un clic — `open`
     * et `text_exhausted` (D20 du 23/09), prédicat de 70, jamais recopié — et
     * dont le siège n'est ni parti ni expulsé, déconnectés compris (contrat
     * C7 § 4.8). Chaque charge est composée ici, sous le verrou de la manche,
     * par {@see ChoicesPresenter::forSeat()} : quatre chaînes permutées pour
     * CE siège, dans sa langue de composition, rejouées à l'identique par
     * toute resynchronisation. Un siège sans propositions (cas défensif du
     * présentateur) ne reçoit rien ici : la resynchronisation les lui rend.
     */
    private function offerChoices(Game $lockedGame, Round $lockedRound): void
    {
        $participations = RoundPlayer::query()
            ->where('round_id', $lockedRound->id)
            ->whereIn('player_id', Player::query()
                ->whereIn('connection_state', [PlayerConnectionState::Connected->value, PlayerConnectionState::Disconnected->value])
                ->whereNull('left_at')
                ->whereNull('kicked_at')
                ->select('id'))
            ->with('player')
            ->orderBy('id')
            ->get()
            ->filter(static fn (RoundPlayer $participation): bool => $participation->input_state->acceptsChoice());

        foreach ($participations as $participation) {
            $payload = $this->choices->forSeat($participation);

            if (! $payload instanceof ChoicesPayload) {
                continue;
            }

            event(new SeatChoicesOffered($participation->player, $lockedGame, [
                'sequenceIndex' => $lockedRound->sequence_index,
                ...$payload->toArray(),
            ]));
        }
    }

    /**
     * Frappe le palier suivant, s'il existe, et le rend relié à sa manche —
     * `null` au dernier palier.
     */
    private function mintNext(Round $lockedRound, RoundTier $lockedTier, CarbonImmutable $now): ?RoundTier
    {
        $nextTier = RoundTier::query()
            ->where('round_id', $lockedRound->id)
            ->where('tier_index', $lockedTier->tier_index + 1)
            ->first();

        if (! $nextTier instanceof RoundTier) {
            return null;
        }

        $this->mint->handle($nextTier, $now);

        return $nextTier->setRelation('round', $lockedRound);
    }

    /**
     * La mémoire d'images du salon, sur la variante réellement servie
     * (10 § 7.9). `last_seen_at` est une colonne à la seconde : l'instant est
     * écrit au format du modèle, tronqué, jamais arrondi par le moteur SQL.
     */
    private static function rememberServedFrame(Game $lockedGame, RoundTier $lockedTier, CarbonImmutable $servedAt): void
    {
        SeenFrame::query()->upsert(
            [[
                'room_id' => $lockedGame->room_id,
                'frame_id' => $lockedTier->served_frame_id,
                'last_seen_at' => (new SeenFrame)->fromDateTime($servedAt),
            ]],
            ['room_id', 'frame_id'],
            ['last_seen_at'],
        );
    }

    /**
     * Le job de la frontière suivante : ouverture du palier suivant à son
     * `Tᵢ₊₁`, ou clôture à `started_at + D` après le dernier palier.
     *
     * @throws LogicException
     */
    private static function scheduleNextBoundary(Game $lockedGame, Round $lockedRound, ?RoundTier $nextTier): void
    {
        if ($nextTier instanceof RoundTier) {
            $nextOpensAt = $nextTier->absoluteStartsAt() ?? throw new LogicException('OpenTier : manche sans origine de temps.');

            AdvanceRound::dispatch($lockedGame->id, $lockedRound->id, RoundStep::OpenTier, $nextTier->tier_index, WireTime::iso($nextOpensAt));

            return;
        }

        $startedAt = $lockedRound->started_at ?? throw new LogicException('OpenTier : manche sans origine de temps.');

        AdvanceRound::dispatch(
            $lockedGame->id,
            $lockedRound->id,
            RoundStep::Close,
            null,
            WireTime::iso($startedAt->addMilliseconds($lockedRound->duration_ms)),
        );
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('OpenTier : partie multijoueur sans salon.');
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte le palier relu, sans
     * rien réécrire en base.
     */
    private static function reflect(RoundTier $tier, RoundTier $lockedTier): void
    {
        $tier->forceFill($lockedTier->only(self::OPENED_COLUMNS))
            ->syncOriginalAttributes(self::OPENED_COLUMNS);
    }
}
