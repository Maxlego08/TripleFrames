<?php

namespace App\Actions\Game;

use App\Actions\Room\LaunchGame;
use App\Enums\GameMode;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\RoomRefusal;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Answers\AnswerRules;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\GameDrawer;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Draw\SeededPrf;
use App\Support\Scoring\ScoringRules;
use App\ValueObjects\Room\LaunchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La naissance d'une partie — spec 50 § 12.3, contrat C6 § 3 (nom et
 * signature figés). **Seul code qui insère dans `game`** (E10-41) : le
 * lancement multijoueur ({@see LaunchGame}, étape L7) et le
 * démarrage solo de `60` (`StartSoloGame`) l'appellent, dans LEUR
 * transaction, qu'elle n'ouvre ni ne valide.
 *
 * | Étape | Action |
 * |---|---|
 * | O0 | Assertions : transaction ouverte ; `(mode = solo) ⇔ room = null` ; réglages de la version courante ; sièges non vides (exactement un en solo). |
 * | O1 | Drapeau de drainage posé → `draining` (message unique `common.maintenance.launch_blocked`, D32 du 23/09). |
 * | O2 | Le périmètre du vivier, construit UNE fois — `PoolScope::forRoom()` au salon, `PoolScope::catalogue()` en solo —, puis le rapport pour `M`, compté en œuvres. |
 * | O3 | Vivier bloqué → `pool_insufficient` (`:playable`, `:required`), rapport en données : la garde de lobby n'était qu'indicative, celle-ci fait autorité. |
 * | O4 | La graine, par `SeededPrf::generateSeed()` seule (contrat C3). |
 * | O5 | INSERT `game` (§ 12.4). |
 * | O6 | INSERT `game_player` par siège : `playing`, manche d'entrée 1, pseudo et avatar gelés depuis `player` (E10-42). |
 * | O7 | Le tirage, SUR LE MÊME `PoolScope` et dans la même transaction que la garde : `min(M + drawSubstituteMargin(), vivier)` œuvres × `N` paliers. |
 * | O8 | `MaterializeDraw` : `round` et `round_tier`. |
 * | O9 | `ScheduleRound` de la manche 1 à `$now + launchCountdownMs()` ; ses jobs et sa diffusion partent après la validation. Les `round_player` naissent à `T₁`, jamais ici (E10-49). |
 *
 * **Provenance** (§ 12.4) : les colonnes typées de `game` sont la projection
 * des réglages reçus, figés en `settings_snapshot` ; `tier_grace_ms` et
 * `preload_lead_ms` sont les constantes non surchargeables de
 * {@see PlatformLimits} ; les deux versions de règle sont les constantes de
 * code de `70` et `80` ; `started_at` est l'instant serveur de l'appelant.
 * Aucune valeur de jeu écrite ici en littéral (règle 2).
 *
 * **Tout ou rien** : toute exception — `PoolTooSmallException` du tirage,
 * échec de la matérialisation ou de la programmation, base — remonte à
 * l'appelant, dont la transaction est annulée. Un refus de règle, lui, est
 * rendu AVANT toute écriture. Aucun appel réseau : ni TMDB (règle 6), ni
 * Reverb, dont les diffusions attendent la validation.
 */
final readonly class OpenGame
{
    public function __construct(
        private DeployDrain $drain,
        private PoolReporter $reporter,
        private GameDrawer $drawer,
        private MaterializeDraw $materialize,
        private ScheduleRound $schedule,
    ) {}

    /**
     * @param  Collection<int, Player>  $seats  Les sièges qui participent, dans l'ordre de leur arrivée.
     *
     * @throws LogicException Une assertion d'entrée (O0) est violée : un défaut
     *                        de l'appelant, jamais un cas d'exécution.
     * @throws PoolTooSmallException Le tirage retient moins de `M` œuvres
     *                               (projection périmée) : un échec technique,
     *                               jamais un refus `pool_insufficient`.
     */
    public function handle(GameMode $mode, ?Room $room, RoomSettings $settings, Collection $seats, CarbonImmutable $now): LaunchOutcome
    {
        // O0 — les assertions de l'appelant.
        self::assertCallable($mode, $room, $settings, $seats);

        // O1 — le drainage bloque exactement la naissance d'une partie.
        if ($this->drain->isDraining()) {
            return LaunchOutcome::refused(RoomRefusal::Draining);
        }

        // O2 — le périmètre, construit une fois, lu par la garde ET le tirage.
        $scope = $room instanceof Room
            ? PoolScope::forRoom($room, $settings, $now)
            : PoolScope::catalogue($settings->themeIds, $settings->framesPerRound);
        $report = $this->reporter->report($scope, $settings->roundsCount);

        // O3 — la garde de vivier, seule à faire autorité.
        if ($report->blocked()) {
            return LaunchOutcome::refused(
                RoomRefusal::PoolInsufficient,
                ['playable' => $report->count, 'required' => $settings->roundsCount],
                $report,
            );
        }

        // O4 — la graine, jamais `random_bytes` en ligne.
        $seed = SeededPrf::generateSeed();

        // O5 — la partie, et la règle qu'elle applique, figées à l'INSERT.
        $game = new Game;
        $game->forceFill([
            'room_id' => $room?->id,
            'mode' => $mode,
            'status' => GameStatus::Running,
            'input_difficulty' => $settings->inputDifficulty,
            'rounds_count' => $settings->roundsCount,
            'frames_per_round' => $settings->framesPerRound,
            'draw_seed' => $seed,
            'draw_pool_size' => $report->count,
            'tier_grace_ms' => PlatformLimits::tierGraceMs(),
            'preload_lead_ms' => PlatformLimits::preloadLeadMs(),
            'settings_snapshot' => $settings,
            'scoring_version' => ScoringRules::VERSION,
            'validation_version' => AnswerRules::VERSION,
            'started_at' => $now,
        ])->save();

        // O6 — une participation par siège, affichage gelé.
        self::insertParticipations($game, $seats);

        // O7 — le tirage, sur le même périmètre, dans la même transaction.
        $result = $this->drawer->draw($scope, $settings->roundsCount, new SeededPrf($seed));

        // O8 — manches et paliers.
        $this->materialize->handle($game, $result);

        // O9 — la manche 1, après le décompte de lancement.
        $first = Round::query()->where('game_id', $game->id)->toPlay()->firstOrFail();
        $this->schedule->handle($first, $now->addMilliseconds(EngineConstants::launchCountdownMs()));

        return LaunchOutcome::launched($game);
    }

    /**
     * O0 — ce que l'appelant garantit ; chaque violation lève.
     *
     * @param  Collection<int, Player>  $seats
     *
     * @throws LogicException
     */
    private static function assertCallable(GameMode $mode, ?Room $room, RoomSettings $settings, Collection $seats): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OpenGame : une transaction doit être ouverte par l’appelant.');
        }

        if (($mode === GameMode::Solo) !== ($room === null)) {
            throw new LogicException('OpenGame : une partie solo n’a pas de salon, une partie multijoueur en a un.');
        }

        if ($settings->sourceVersion !== RoomSettings::VERSION) {
            throw new LogicException(sprintf(
                'OpenGame : réglages de version %d, la version courante est %d ; normaliser d’abord.',
                $settings->sourceVersion,
                RoomSettings::VERSION,
            ));
        }

        if ($seats->isEmpty() || ($mode === GameMode::Solo && $seats->count() !== 1)) {
            throw new LogicException('OpenGame : au moins un siège, et exactement un en solo.');
        }

        foreach ($seats as $seat) {
            if ($seat->room_id !== $room?->id) {
                throw new LogicException('OpenGame : un siège n’appartient pas au salon de la partie.');
            }
        }
    }

    /**
     * O6 — une ligne `game_player` par siège, en une insertion : `playing`,
     * `first_round_number = 1`, pseudo et avatar prédéfini gelés depuis la
     * ligne `player` (E10-42) — jamais le chemin d'une copie provider, qu'un
     * siège ne porte pas (10 § 7.3). Les cinq agrégats restent nuls : seul le
     * gel les écrit (`FinalizeGame`).
     *
     * @param  Collection<int, Player>  $seats
     */
    private static function insertParticipations(Game $game, Collection $seats): void
    {
        $stamp = (new GamePlayer)->freshTimestampString();
        $rows = [];

        foreach ($seats as $seat) {
            $rows[] = [
                'game_id' => $game->id,
                'player_id' => $seat->id,
                'display_nickname' => $seat->nickname,
                'display_avatar_kind' => $seat->avatar_kind?->value,
                'display_avatar_preset' => $seat->avatar_preset,
                'first_round_number' => 1,
                'status' => GamePlayerStatus::Playing->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        GamePlayer::query()->insert($rows);
    }
}
