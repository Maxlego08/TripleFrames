<?php

namespace App\Actions\Room;

use App\Actions\Game\OpenGame;
use App\Enums\GameMode;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Events\Game\GameLaunched;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Game\SeatViewPresenter;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\LaunchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

/**
 * Le lancement d'une partie multijoueur par l'hôte — spec 50 § 12.2,
 * contrat C6 § 3 (nom et signature figés). Une seule transaction, dans cet
 * ordre :
 *
 * | Étape | Action |
 * |---|---|
 * | L1 | Verrou du salon : premier verrou de l'ordre global `room → player → game → round → round_player` (E10-51). |
 * | L2 | `$now`, pris APRÈS le verrou, à la milliseconde. |
 * | L3 | Hôte sans cible valide → `TransferHost::automatic()` ; puis hôte ≠ demandeur → `not_host`. Un salon archivé est refusé `room_archived` AVANT cette réparation, qui écrirait un hôte sur un salon que l'archivage a vidé du sien. |
 * | L4 | En partie, podium compris → `not_in_lobby`, qui rend le double clic idempotent. |
 * | L5 | Réglages d'une version antérieure → `normalize()`, écrivain unique, refus `settings_outdated` avec le rapport : **la seule branche de refus qui écrit**. |
 * | L6 | Sièges non partis (`connected` ou `disconnected` ; un expulsé est parti, D15 du 23/09) ; moins de `MIN_CONNECTED_PLAYERS_TO_LAUNCH` connectés → `not_enough_players`. |
 * | L7 | {@see OpenGame} sur `room.settings` : drainage, garde de vivier REJOUÉE, graine, partie, participations, tirage, matérialisation, programmation de la manche 1. Un refus est rendu tel quel. |
 * | L8 | `room.status = playing`, `launched_at` au premier lancement seulement, `last_activity_at`, par mise à jour ciblée. |
 * | L9 | APRÈS la validation : `game.launched` au salon (`ShouldDispatchAfterCommit`). |
 *
 * **Autorité relue sous verrou** — hôte, statut, drapeau et vivier : la
 * policy HTTP ne suffit jamais (§ 12.6, invariant 2). **Tout ou rien** : une
 * seule transaction porte la partie, ses participations, ses manches, ses
 * paliers, la programmation de la manche 1 et le statut du salon ; une
 * exception levée dans la transaction l'annule entière, et le contrôleur la
 * rend traduite (§ 12.5). **Aucun effet externe avant la validation** : jobs
 * et diffusions partent après, aucun appel réseau pendant (ni TMDB, ni
 * Reverb). Leurs rappels courent donc APRÈS le COMMIT : une file en panne à
 * la poussée du job de la manche 1 lève hors de la transaction déjà validée,
 * sur une partie née — le contrôleur relit alors le statut du salon.
 *
 * Le contrôleur, après la transaction, dispatche la diffusion anti-rebondie
 * de l'état du lobby sur les refus `settings_outdated` et
 * `pool_insufficient` (§ 12.2 L5, § 12.3 O3) : tout le salon voit alors les
 * mêmes réglages et le même blocage.
 */
final readonly class LaunchGame
{
    public function __construct(
        private TransferHost $transferHost,
        private WriteRoomSettings $writer,
        private PoolQuery $pool,
        private OpenGame $openGame,
    ) {}

    /**
     * @param  Player  $requester  Siège du demandeur, résolu par son `player_token`.
     *
     * @throws PoolTooSmallException Le tirage retient moins de `M` œuvres
     *                               (échec technique, transaction annulée).
     * @throws LogicException Un défaut d'appelant sous la transaction.
     * @throws JsonException Charge de réglages illisible en base.
     *
     * Une exception d'un rappel `afterCommit` (poussée d'un job ; une
     * diffusion en échec est avalée par `ShouldRescue`) remonte aussi,
     * transaction déjà validée.
     */
    public function handle(Room $room, Player $requester): LaunchOutcome
    {
        return DB::transaction(function () use ($room, $requester): LaunchOutcome {
            // L1 — premier verrou, puis L2 — l'instant.
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $now = Date::now()->toImmutable()->startOfMillisecond();

            // L4, premier temps — un salon archivé n'a plus d'hôte (§ 16.2,
            // `TransferHost::clear()`) : il est refusé AVANT la réparation de
            // L3, qui lui en rendrait un.
            if ($locked->status === RoomStatus::Archived) {
                return LaunchOutcome::refused(RoomRefusal::RoomArchived);
            }

            // L3 — réparation de l'hôte, puis autorité relue sous le verrou.
            if (! TransferHost::hasValidHost($locked)) {
                $this->transferHost->automatic($locked, $now);
            }

            if ($locked->host_player_id === null
                || $locked->host_player_id !== $requester->id
                || $requester->room_id !== $locked->id) {
                return LaunchOutcome::refused(RoomRefusal::NotHost);
            }

            // L4 — en partie, podium compris : le double clic est idempotent.
            if ($locked->status !== RoomStatus::Lobby) {
                return LaunchOutcome::refused(RoomRefusal::NotInLobby);
            }

            // L5 — des réglages d'une version antérieure ne se figent jamais.
            if ($locked->settings_version !== RoomSettings::VERSION) {
                return $this->refuseOutdated($locked, $now);
            }

            // L6 — les sièges qui participent, et le seuil de lancement.
            $seats = self::participatingSeats($locked);
            $connected = $seats->filter(
                static fn (Player $seat): bool => $seat->connection_state === PlayerConnectionState::Connected,
            )->count();

            if ($connected < RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH) {
                return LaunchOutcome::refused(
                    RoomRefusal::NotEnoughPlayers,
                    ['min' => RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH],
                );
            }

            // L7 — la partie, sur les réglages du salon.
            $outcome = $this->openGame->handle(GameMode::Multiplayer, $locked, $locked->settings, $seats, $now);
            $game = $outcome->game;

            if (! $outcome->isLaunched() || ! $game instanceof Game) {
                return $outcome;
            }

            // L8 — le salon passe en partie, par mise à jour ciblée.
            Room::query()->whereKey($locked->id)->update([
                'status' => RoomStatus::Playing->value,
                'launched_at' => $locked->launched_at ?? $now,
                'last_activity_at' => $now,
            ]);

            // L9 — après la validation de la transaction.
            GameLaunched::dispatch($locked, $game, self::launchedPayload($locked, $game));

            return $outcome;
        });
    }

    /**
     * L5 — `normalize()` de la charge BRUTE, sous la version où elle a été
     * écrite, contre les thèmes encore publiés ; l'écrivain unique ; puis le
     * refus, avec le rapport sous les clés client. La transaction est
     * validée : c'est la seule branche de refus qui écrit.
     *
     * @throws JsonException
     */
    private function refuseOutdated(Room $locked, CarbonImmutable $now): LaunchOutcome
    {
        $raw = json_decode((string) $locked->getRawOriginal('settings'), true, flags: JSON_THROW_ON_ERROR);

        $normalized = RoomSettings::normalize(
            is_array($raw) ? self::stringKeyed($raw) : [],
            $locked->settings_version,
            $this->pool->publishedThemeIds(),
        );

        $this->writer->handle($locked, $normalized['settings'], $now);

        return LaunchOutcome::refused(
            RoomRefusal::SettingsOutdated,
            changes: RoomSettingsPresenter::changes($normalized['changes']),
        );
    }

    /**
     * L6 — les sièges `connected` ou `disconnected`, jamais expulsés, par
     * ordre d'arrivée : la participation d'un siège déconnecté naît au
     * lancement, et il rejoint la manche en revenant.
     *
     * @return Collection<int, Player>
     */
    private static function participatingSeats(Room $room): Collection
    {
        return Player::query()
            ->whereBelongsTo($room)
            ->whereIn('connection_state', [
                PlayerConnectionState::Connected->value,
                PlayerConnectionState::Disconnected->value,
            ])
            ->whereNull('kicked_at')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * La charge de `game.launched` (spec 60 § 11.3 et § 11.5) : les colonnes
     * figées de la partie, la durée de révélation et l'interrupteur du bonus
     * lus dans l'instantané, les sièges gelés par `game_player.id` croissant.
     * Aucune graine, aucun film, aucun identifiant interne (§ 12.5).
     *
     * @return array<string, mixed>
     */
    private static function launchedPayload(Room $room, Game $game): array
    {
        $snapshot = $game->settings_snapshot;

        return [
            'mode' => $game->mode->value,
            'roundsCount' => $game->rounds_count,
            'framesPerRound' => $game->frames_per_round,
            'inputDifficulty' => $game->input_difficulty->value,
            'revealDurationMs' => $snapshot->revealDuration * 1000,
            'speedBonus' => $snapshot->speedBonus,
            'seats' => SeatViewPresenter::gameSeats($game, $room->host_player_id),
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $raw): array
    {
        $payload = [];

        foreach ($raw as $field => $value) {
            if (is_string($field)) {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }
}
