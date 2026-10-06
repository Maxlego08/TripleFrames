<?php

namespace App\Actions\Game;

use App\Avatars\SeatAvatar;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomRefusal;
use App\Enums\RoundStatus;
use App\Enums\SettingPresetKey;
use App\Enums\SoloRefusal;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\User;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Game\GameJournal;
use App\Support\Game\SoloPresets;
use App\Support\Game\SoloRoundClosure;
use App\Support\Game\SoloSeat;
use App\Support\Game\SoloStartRefused;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\SeatPublicId;
use App\Support\Visitor\VisitorTracker;
use App\ValueObjects\Game\SoloStartOutcome;
use App\ValueObjects\Room\LaunchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le démarrage d'une partie solo — `solo.store` (spec 60 § 16.2, § 16.3 et
 * § 16.6 ; contrat C7 § 4.12 ; contrat C6 : la partie naît par
 * {@see OpenGame}). **Une seule transaction**, dans cet ordre :
 *
 * 1. **drainage d'abord** : drapeau posé → refus
 *    `common.maintenance.launch_blocked`, aucune partie créée ni
 *    interrompue (C17 § 4.4) ;
 * 2. **siège** : le siège solo du jeton courant ({@see SoloSeat::heldBy()}),
 *    pris `FOR UPDATE` — **première instruction SQL de la transaction** :
 *    deux lancements d'un même siège se sérialisent sur cette ligne, et
 *    aucun instantané de lecture (`REPEATABLE READ`) n'est ouvert avant
 *    elle, si bien que le second voit la partie que le premier a validée et
 *    l'interrompt au lieu d'en laisser deux en cours ;
 * 3. `$now`, pris APRÈS le verrou, à la milliseconde ;
 * 4. **réglages** : le preset, ramené d'office au `N` jouable le plus proche
 *    (D19 du 23/09, {@see SoloPresets::resolve()}) ; sans `N` jouable, refus
 *    `game.errors.pool_too_small`, rapport de vivier en données, **avant
 *    toute écriture** ;
 * 5. **jeton** : `PlayerTokenManager::ensure()` (C4 I4.1) — APRÈS les refus
 *    de drainage et de vivier (40 § 11, ligne `60`) : un démarrage refusé ne
 *    pose aucun `Set-Cookie` sur un visiteur sans jeton ;
 * 6. **siège solo** : repris tel quel s'il existe (le cookie glisse), sinon
 *    créé — pseudo validé, avatar attribué par le serveur (D55 du 02/10 :
 *    aucun choix en solo), locale de la requête, `solo_token_hash`
 *    écrit dans la même écriture que `player_token_hash`. Deux premiers
 *    lancements concurrents n'ont aucune ligne à verrouiller :
 *    `player_solo_token_uq` (E10-N3) en refuse un, qui relit alors le siège
 *    existant et le prend `FOR UPDATE` ;
 * 7. **partie solo en cours du siège : interrompue** (§ 16.6) — rattrapage
 *    ({@see CatchUpGame}) d'abord, puis clôture d'une manche `running` comme
 *    par « Passer la manche », ou passage `completed` d'une manche en phase
 *    `closed` sans toucher `input_state` ({@see SoloRoundClosure}), puis
 *    `FinalizeGame(Interrupted, now)` ;
 * 8. {@see OpenGame}`(Solo, null, $settings, [$seat], $now)` : garde de
 *    drainage et de vivier rejouées, graine, partie, participation, tirage,
 *    matérialisation, programmation de la manche 1 à `now +
 *    launchCountdownMs` ;
 * 9. après la validation : re-signature du jeton avec le prédéfini attribué, pour
 *    un siège neuf (I4.5).
 *
 * **Tout refus annule la transaction entière, interruption comprise** — y
 * compris le `LaunchOutcome::refused` d'`OpenGame`, qui ne lève pas : l'action
 * lève alors {@see SoloStartRefused} pour annuler, et rend le refus. Un
 * relancement refusé ne tue jamais la partie solo en cours.
 *
 * **Échec technique** : toute autre exception (`PoolTooSmallException` du
 * tirage, matérialisation, programmation, base, délai de verrou) annule tout
 * et remonte au contrôleur, qui la rend traduite (`room.errors.launch_failed`)
 * — jamais en `game.errors.pool_too_small`, dont le rapport affirmerait le
 * contraire (30 § 4.6).
 *
 * **Interblocage** : sur MySQL, deux premiers lancements concurrents
 * prennent chacun un verrou d'intervalle vide sur `player_token_idx`, puis
 * s'attendent à l'insertion ; InnoDB en désigne une victime (1213). La
 * transaction est alors rejouée entière ({@see self::ATTEMPTS}) : la victime
 * bute sur le siège inséré par l'autre, le relit après sa validation, et
 * l'interrompt — au plus une partie solo en cours par siège.
 *
 * Aucune diffusion (10 § 7.10, barrière 2) ; aucune écriture de
 * `seen_frame`. **Résidu nommé** : un refus rendu par `OpenGame` lui-même
 * (drapeau posé, ou catalogue réduit entre l'étape 4 et sa garde) suit
 * `ensure()` : le `Set-Cookie` du jeton part avec lui.
 */
final readonly class StartSoloGame
{
    /**
     * Exécutions de la transaction : la première, plus une reprise après un
     * interblocage ou un délai de verrou (Laravel ne rejoue que ces deux
     * erreurs de concurrence). Une constante technique, jamais un réglage de
     * jeu.
     */
    private const int ATTEMPTS = 2;

    public function __construct(
        private DeployDrain $drain,
        private PlayerTokenManager $tokens,
        private SoloPresets $presets,
        private CatchUpGame $catchUp,
        private FinalizeGame $finalize,
        private OpenGame $openGame,
        private VisitorTracker $visitors,
    ) {}

    /**
     * @param  Request  $request  La requête du geste : source du jeton courant,
     *                            et du jeton frappé à l'écriture du siège.
     * @param  string|null  $nickname  Forme canonique validée ; nulle seulement
     *                                 quand le jeton tient déjà un siège solo.
     * @param  Locale  $locale  Locale effective de la requête (`SetLocale`).
     *
     * @throws PoolTooSmallException Le tirage retient moins de `M` œuvres
     *                               (projection périmée) : échec technique.
     * @throws LogicException Siège neuf sans pseudo validé, ou
     *                        défaut d'un appelant sous la transaction.
     */
    public function handle(
        Request $request,
        SettingPresetKey $preset,
        ?string $nickname,
        Locale $locale,
    ): SoloStartOutcome {
        try {
            return DB::transaction(
                fn (): SoloStartOutcome => $this->begin($request, $preset, $nickname, $locale),
                self::ATTEMPTS,
            );
        } catch (SoloStartRefused $refused) {
            return $refused->outcome;
        }
    }

    /**
     * Les étapes 1 à 9, dans la transaction. Jamais nommée `start()` : ce
     * nom est celui de la pose du drapeau de drainage, dont
     * `GameCreationBoundaryTest` interdit tout appel hors des commandes de
     * déploiement.
     *
     * @throws SoloStartRefused
     */
    private function begin(
        Request $request,
        SettingPresetKey $preset,
        ?string $nickname,
        Locale $locale,
    ): SoloStartOutcome {
        // 1. Le drainage d'abord : rien n'est lu ni écrit en base.
        if ($this->drain->isDraining()) {
            throw new SoloStartRefused(SoloStartOutcome::refused(SoloRefusal::Draining));
        }

        // 2. Le siège du jeton courant, verrouillé : première instruction SQL.
        $current = $this->tokens->current($request);
        $seat = $current === null ? null : self::lockSeat($current);

        // 3. L'instant, après le verrou.
        $now = Date::now()->toImmutable()->startOfMillisecond();

        // 4. Les réglages (D19), refus de vivier avant toute écriture.
        $choice = $this->presets->resolve($preset);
        $settings = $choice->settings;

        if ($settings === null) {
            throw new SoloStartRefused(SoloStartOutcome::refused(SoloRefusal::PoolTooSmall, $choice->report));
        }

        // 5. Le jeton, une fois les refus de règle écartés.
        $token = $this->tokens->ensure($request);

        // 6. Le siège : repris, sinon créé.
        $created = false;

        if ($seat === null) {
            $account = $request->user() instanceof User ? $request->user() : null;
            [$seat, $created] = $this->seat($token, $account, $nickname, $current?->avatar, $locale, $now);

            // Le visiteur consentant et l'appareil du siège (D62 du 06/10).
            if ($created) {
                $this->visitors->stamp($seat, $request);
            }
        }

        // 7. La partie solo en cours du siège : interrompue.
        $this->interruptGamesOf($seat, $now);

        // 8. La nouvelle partie.
        $outcome = $this->openGame->handle(GameMode::Solo, null, $settings, $seat->newCollection([$seat]), $now);
        $game = $outcome->game;

        if (! $outcome->isLaunched() || ! $game instanceof Game) {
            throw new SoloStartRefused(self::refusedByOpenGame($outcome));
        }

        // 9. Après la validation : le prédéfini attribué rejoint le jeton (I4.5).
        if ($created && $seat->avatar_preset !== null) {
            $chosen = $seat->avatar_preset;
            DB::afterCommit(fn (): PlayerToken => $this->tokens->resign($request, $token->withAvatar($chosen)));
        }

        return SoloStartOutcome::started($game, $seat, $choice->notice());
    }

    /**
     * Le siège solo tenu par ce jeton, pris `FOR UPDATE`, ou `null`.
     */
    private static function lockSeat(PlayerToken $token): ?Player
    {
        return SoloSeat::heldBy($token)->orderByDesc('id')->lockForUpdate()->first();
    }

    /**
     * Étape 6 : le siège solo neuf, ou celui qu'un lancement concurrent vient
     * d'écrire (violation de `player_solo_token_uq`, relu et verrouillé).
     *
     * @return array{0: Player, 1: bool} le siège, et s'il vient d'être créé
     *
     * @throws LogicException Siège neuf sans pseudo validé.
     * @throws UniqueConstraintViolationException Une collision qu'aucun siège
     *                                            solo de ce jeton n'explique.
     */
    private function seat(
        PlayerToken $token,
        ?User $account,
        ?string $nickname,
        ?string $preferred,
        Locale $locale,
        CarbonImmutable $now,
    ): array {
        if ($nickname === null) {
            throw new LogicException('StartSoloGame : un siège solo neuf exige un pseudo validé.');
        }

        // L'avatar, attribué par le serveur et relu sur le compte (spec 40
        // § 11.4, D55 du 02/10) ; aucun avatar pris en solo.
        $avatar = SeatAvatar::assign($account, $preferred, []);

        $seat = new Player;

        try {
            $seat->forceFill([
                'public_id' => SeatPublicId::generate(),
                'room_id' => null,
                'nickname' => $nickname,
                'nickname_normalized' => NicknameNormalizer::normalize($nickname),
                'player_token_hash' => $token->hash(),
                // E10-N3 : le créneau d'unicité, dans la même écriture.
                'solo_token_hash' => $token->hash(),
                'user_id' => $account?->id,
                'locale' => $locale,
                'avatar_kind' => $avatar->kind,
                'avatar_preset' => $avatar->preset,
                'joined_at' => $now,
                'last_seen_at' => $now,
                'connection_state' => PlayerConnectionState::Connected,
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            $existing = self::lockSeat($token);

            if ($existing === null) {
                throw $exception;
            }

            return [$existing, false];
        }

        return [$seat, true];
    }

    /**
     * Étape 7 (§ 16.6) : toute partie solo en cours du siège — au plus une
     * par l'invariant, toutes par prudence —, lue par une lecture
     * VERROUILLANTE (qui voit la dernière version validée), rattrapée, puis
     * close et gelée `interrupted` à `$now`.
     */
    private function interruptGamesOf(Player $seat, CarbonImmutable $now): void
    {
        $games = Game::query()
            ->inProgress()
            ->whereNull('room_id')
            ->where('mode', GameMode::Solo->value)
            ->whereHas('gamePlayers', static fn (Builder $participation) => $participation->where('player_id', $seat->id))
            ->orderBy('started_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($games as $game) {
            $this->interrupt($game, $now);
        }
    }

    /**
     * Rattrapage, clôture des manches `running`, puis gel `interrupted` —
     * `game` relue sous son verrou, puis les manches (`game → round`, § 4.5).
     */
    private function interrupt(Game $game, CarbonImmutable $now): void
    {
        // Les étapes échues d'abord (§ 4.4) : une manche close à `D`, une
        // révélation passée, une pause échue s'écrivent à leurs instants
        // théoriques, jamais à celui de la relance.
        $this->catchUp->handle($game, $now);

        $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->first();

        if (! $lockedGame instanceof Game || $lockedGame->ended_at !== null) {
            return;
        }

        $runningRounds = Round::query()
            ->where('game_id', $lockedGame->id)
            ->where('status', RoundStatus::Running->value)
            ->orderBy('sequence_index')
            ->lockForUpdate()
            ->get();

        foreach ($runningRounds as $runningRound) {
            if ($runningRound->ended_at === null) {
                SoloRoundClosure::skip($lockedGame, $runningRound, $now);
            } else {
                SoloRoundClosure::completeClosed($lockedGame, $runningRound);
            }
        }

        if ($this->finalize->handle($lockedGame, GameStatus::Interrupted, $now)) {
            GameJournal::gameFinalized($lockedGame, GameStatus::Interrupted, $now);
        }
    }

    /**
     * Le refus rendu par `OpenGame` (C6 O1, O3), traduit en refus du solo :
     * drapeau posé ou vivier devenu insuffisant depuis l'étape 4.
     *
     * @throws LogicException Un refus qu'`OpenGame` ne rend jamais en solo.
     */
    private static function refusedByOpenGame(LaunchOutcome $outcome): SoloStartOutcome
    {
        return match ($outcome->refusal) {
            RoomRefusal::Draining => SoloStartOutcome::refused(SoloRefusal::Draining),
            RoomRefusal::PoolInsufficient => SoloStartOutcome::refused(SoloRefusal::PoolTooSmall, $outcome->pool),
            default => throw new LogicException(sprintf(
                'StartSoloGame : refus inattendu d’OpenGame en solo (%s).',
                $outcome->refusal === null ? 'aucun' : $outcome->refusal->value,
            )),
        };
    }
}
