<?php

namespace App\Actions\Identity;

use App\Avatars\SeatAvatar;
use App\Enums\AvatarKind;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Account\PlayHistoryCache;
use App\Support\Game\SeatViewPresenter;
use App\Support\Room\RoomExpiry;
use App\Support\Room\TakenAvatars;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Rattachement AUTOMATIQUE d'un siège invité au compte connecté — spec 40
 * § 13.2 (sujet 5 ; D66 du 07/10, n° 23, n° 24, n° 25).
 *
 * **Où** : au rendu d'une page de siège (`room.show`, `solo.show`), dans la
 * requête qui la sert, pour le seul siège de la page (n° 24 : jamais les
 * autres sièges du jeton). **Jamais** dans un écouteur de `Login`, `Logout`
 * ou `Registered` (I4.6) : la connexion ne lit ni n'écrit le `player_token`.
 *
 * **Gardes**, relues sous `lockForUpdate` (salon puis siège, ordre global
 * `room → player`, E10-51), chacune rendant « rien à faire » en silence :
 * salon non archivé et hash du jeton présent (archivage, effacement du siège
 * solo) ; siège non expulsé ; `player.user_id` toujours nul — jamais
 * l'écrasement d'un siège lié ; compte non anonymisé ; aucun autre siège du
 * même compte dans le même salon (double compte dans l'historique).
 *
 * **Écritures** : `player.user_id` et `player.locale` = `users.locale`
 * (comme une reprise, `05`) ; jamais `nickname` (I5.11), jamais
 * `game_player` (identité gelée de la partie en cours). Le cache court de
 * l'historique du compte est oublié après la validation.
 *
 * **Avatar** (n° 25, règle D55) : salon au lobby → la règle d'attribution de
 * la prise de siège est rejouée pour ce siège (image téléversée visible du
 * compte, repli libre par {@see TakenAvatars}), puis `seat.updated` après la
 * validation ; partie en cours ou podium → une marque de cache, consommée au
 * retour au lobby par {@see self::applyPendingAvatars()} (« Rejouer ») ; solo
 * → aucune bascule (pas de choix d'avatar en solo).
 */
final readonly class ClaimSeatForAccount
{
    /** Préfixe de la marque d'avatar en attente, suivi de `player.id`. */
    public const string PENDING_AVATAR_PREFIX = 'seat-claim:avatar-pending:';

    /**
     * Rattache ce siège à ce compte, si toutes les gardes tiennent.
     *
     * @return bool vrai si cet appel a rattaché le siège
     */
    public function handle(Player $seat, User $user): bool
    {
        // Chemin courant, sans verrou : siège déjà lié, effacé, ou compte
        // anonymisé.
        if ($seat->user_id !== null || $seat->player_token_hash === null || $user->anonymized_at !== null) {
            return false;
        }

        // Gardes durables lues sans verrou : un siège expulsé, ou un compte
        // qui tient déjà un autre siège de ce salon, échouerait sous le verrou
        // à chaque rendu — partiels de partie compris — en prenant pour rien
        // la ligne du salon contre le moteur (frontières de palier, réponses).
        // Elles sont relues sous le verrou, qui reste la lecture qui fait foi.
        if ($seat->kicked_at !== null
            || ($seat->room_id !== null && Player::query()
                ->where('room_id', $seat->room_id)
                ->where('user_id', $user->id)
                ->exists())) {
            return false;
        }

        return DB::transaction(function () use ($seat, $user): bool {
            $room = null;

            if ($seat->room_id !== null) {
                $room = Room::query()->whereKey($seat->room_id)->lockForUpdate()->first();

                if ($room === null || $room->status === RoomStatus::Archived) {
                    return false;
                }
            }

            $locked = Player::query()->whereKey($seat->id)->lockForUpdate()->first();

            if ($locked === null
                || $locked->player_token_hash === null
                || $locked->kicked_at !== null
                || $locked->user_id !== null
                || $locked->room_id !== $room?->id) {
                return false;
            }

            $account = User::query()->whereKey($user->id)->whereNull('anonymized_at')->first();

            if ($account === null) {
                return false;
            }

            if ($room !== null && Player::query()
                ->whereBelongsTo($room)
                ->where('user_id', $account->id)
                ->exists()) {
                return false;
            }

            $locked->forceFill([
                'user_id' => $account->id,
                'locale' => $account->locale,
            ])->save();

            DB::afterCommit(static fn () => PlayHistoryCache::forget($account->id));

            if ($room !== null) {
                $this->avatarAfterClaim($room, $locked, $account);
            }

            // L'appelant rend la page sur le siège rattaché.
            $seat->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Le retour au lobby (« Rejouer », spec 50 § 13) : chaque siège tenu du
     * salon qui porte une marque d'avatar en attente reçoit la règle
     * d'attribution, sous le verrou du salon. Marque consommée dans tous les
     * cas ; une marque perdue laisse « Mon avatar » offert au lobby.
     *
     * @return int le nombre de sièges dont l'avatar a basculé
     */
    public function applyPendingAvatars(Room $room): int
    {
        $candidates = Player::query()
            ->whereBelongsTo($room)
            ->holdingSeat()
            ->whereNotNull('user_id')
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_int($id) && Cache::has(self::pendingKey($id)))
            ->values()
            ->all();

        if ($candidates === []) {
            return 0;
        }

        return DB::transaction(function () use ($room, $candidates): int {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== RoomStatus::Lobby) {
                return 0;
            }

            $switched = 0;

            foreach ($candidates as $playerId) {
                Cache::forget(self::pendingKey($playerId));

                $seat = Player::query()->whereKey($playerId)->whereBelongsTo($locked)->lockForUpdate()->first();
                $account = $seat?->user_id === null ? null : User::query()->whereKey($seat->user_id)->whereNull('anonymized_at')->first();

                if ($seat !== null && $account !== null && $this->switchAvatar($locked, $seat, $account)) {
                    $switched++;
                }
            }

            return $switched;
        });
    }

    /** La clé de la marque d'avatar en attente d'un siège. */
    public static function pendingKey(int $playerId): string
    {
        return self::PENDING_AVATAR_PREFIX.$playerId;
    }

    /**
     * Au lobby, la bascule tout de suite ; en partie ou au podium, la marque,
     * posée après la validation, pour la durée de vie du salon.
     */
    private function avatarAfterClaim(Room $lockedRoom, Player $lockedSeat, User $account): void
    {
        if ($lockedRoom->status === RoomStatus::Lobby) {
            $this->switchAvatar($lockedRoom, $lockedSeat, $account);

            return;
        }

        $key = self::pendingKey($lockedSeat->id);
        $until = RoomExpiry::archiveDeadlineFrom(Date::now()->toImmutable());

        DB::afterCommit(static fn () => Cache::put($key, true, $until));
    }

    /**
     * La règle D55 rejouée pour un siège rattaché, salon au lobby et
     * verrouillé : l'image téléversée visible du compte, avec un prédéfini de
     * repli libre. Aucune autre bascule — un compte sans image téléversée
     * visible garde l'avatar du siège.
     */
    private function switchAvatar(Room $lockedRoom, Player $lockedSeat, User $account): bool
    {
        if ($lockedSeat->wasKicked() || $lockedSeat->connection_state === PlayerConnectionState::Left) {
            return false;
        }

        $avatar = SeatAvatar::assign($account, $lockedSeat->avatar_preset, TakenAvatars::of($lockedRoom, $lockedSeat));

        if ($avatar->kind === AvatarKind::Preset
            || ($lockedSeat->avatar_kind === $avatar->kind && $lockedSeat->avatar_preset === $avatar->preset)) {
            return false;
        }

        $lockedSeat->forceFill([
            'avatar_kind' => $avatar->kind,
            'avatar_preset' => $avatar->preset,
        ])->save();

        SeatUpdated::dispatch($lockedRoom, null, [
            'seat' => SeatViewPresenter::lobby($lockedSeat, $lockedRoom->host_player_id),
        ]);

        return true;
    }
}
