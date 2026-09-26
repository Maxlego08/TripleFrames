<?php

namespace App\Actions\Room;

use App\Enums\AvatarKind;
use App\Enums\JoinRefusal;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatJoined;
use App\Models\Player;
use App\Models\Room;
use App\Rules\ValidNickname;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\SeatPublicId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Prise de siège — la séquence normative S1 à S10 (spec 50 § 7.3), sous la
 * contrainte « `ensure()` après les refus » de `40` (§ 2.1, étape 4).
 *
 * Appelée par `CreateRoom` (`repairHost: false`) et par `room.join`. Une
 * transaction, sous l'ordre de verrouillage global `room → player → game →
 * round → round_player` (E10-51) :
 *
 * - S1 : verrou du salon, puis `$now` ;
 * - S2 : salon archivé → {@see JoinRefusal::Archived} ;
 * - S3 : **reprise avant tout comptage** — le siège du jeton courant dans ce
 *   salon : expulsé → {@see JoinRefusal::Kicked}, jamais une 1062 (D15 du
 *   23/09) ; sinon rendu TEL QUEL, sans aucune écriture en base, ni
 *   revalidation du pseudo (C5 I5.5), ni garde de capacité (10 § 6.2) —
 *   `ensure()` fait seulement glisser le cookie ;
 * - S4 : effectif présent (`holdingSeat()`, jamais l'historique des sièges)
 *   ≥ capacité → {@see JoinRefusal::Full} ;
 * - S5 : forme repliée du pseudo déjà portée par un siège du salon, partis et
 *   expulsés compris → `validation.nickname.taken` sous `nickname` (I5.4) ;
 * - S6 : **`ensure()` seulement alors**, puis l'écriture du siège, en une
 *   seule écriture Eloquent (40 § 2.2) ;
 * - S7 : salon en partie → attente de la partie suivante (l'admission d'un
 *   retardataire, § 15.2, arrive avec le lot L50-9) ; au lobby, rien ;
 * - S8 : si `$repairHost`, hôte sans cible valide → `TransferHost::automatic()` ;
 * - S9 : `last_activity_at`, par mise à jour ciblée ;
 * - S10 : APRÈS la validation, `seat.joined` au salon et re-signature du
 *   jeton avec l'avatar choisi (I4.5).
 *
 * **Le jeton n'est frappé qu'une fois tous les refus écartés** : un visiteur
 * refusé (salon archivé, expulsé, plein, pseudo pris) ne reçoit aucun
 * `Set-Cookie` `player_token` — d'où la requête en paramètre, et non un
 * jeton déjà frappé : l'action lit le jeton courant (`current()`, qui ne
 * frappe rien) et ne frappe qu'au moment d'écrire le siège. Résidu : une
 * collision d'index à l'insertion, postérieure à la frappe par construction,
 * laisse partir le jeton frappé.
 *
 * **Collision résiduelle** : une `UniqueConstraintViolationException` est
 * interceptée et suivie d'une relecture — un siège de ce jeton existe
 * désormais (double envoi) : reprise ; sinon `validation.nickname.taken`.
 * Jamais une 1062 brute (10 § 7.1).
 *
 * Au J1, `player.user_id` n'est jamais écrit (C4 I4.10) : un compte connecté
 * prend un siège comme un invité.
 */
final readonly class TakeSeat
{
    public function __construct(
        private PlayerTokenManager $tokens,
        private TransferHost $transferHost,
    ) {}

    /**
     * @param  Request  $request  La requête du geste : source du jeton courant,
     *                            et du jeton frappé à l'écriture du siège.
     * @param  string|null  $nickname  Forme canonique validée ; nulle seulement
     *                                 quand le jeton tient déjà un siège (reprise).
     * @param  string|null  $avatarPreset  Clé du catalogue validée ; même règle.
     * @param  Locale  $locale  Locale effective de la requête (`SetLocale`).
     * @return Player|JoinRefusal le siège pris ou repris, ou le refus
     *
     * @throws ValidationException Pseudo déjà pris dans ce salon.
     */
    public function handle(
        Room $room,
        Request $request,
        ?string $nickname,
        ?string $avatarPreset,
        Locale $locale,
        bool $repairHost = true,
    ): Player|JoinRefusal {
        try {
            return DB::transaction(fn (): Player|JoinRefusal => $this->seat(
                $room, $request, $nickname, $avatarPreset, $locale, $repairHost,
            ));
        } catch (UniqueConstraintViolationException) {
            $token = $this->tokens->current($request);
            $held = $token === null ? null : $this->heldBy($room, $token);

            if ($held === null) {
                throw self::nicknameTaken();
            }

            return $held->wasKicked() ? JoinRefusal::Kicked : $held;
        }
    }

    /**
     * La séquence S1 à S10, dans la transaction.
     *
     * @throws ValidationException Pseudo déjà pris dans ce salon.
     * @throws LogicException Siège neuf sans pseudo ni avatar validés.
     */
    private function seat(
        Room $room,
        Request $request,
        ?string $nickname,
        ?string $avatarPreset,
        Locale $locale,
        bool $repairHost,
    ): Player|JoinRefusal {
        // S1 — verrou du salon, puis l'instant.
        $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
        $now = Date::now()->toImmutable();

        // S2 — salon archivé.
        if ($locked->status === RoomStatus::Archived) {
            return JoinRefusal::Archived;
        }

        // S3 — reprise avant tout comptage : aucune écriture en base.
        $current = $this->tokens->current($request);
        $held = $current === null ? null : $this->heldBy($locked, $current);

        if ($held !== null) {
            if ($held->wasKicked()) {
                return JoinRefusal::Kicked;
            }

            $this->tokens->ensure($request);

            return $held;
        }

        if ($nickname === null || $avatarPreset === null) {
            throw new LogicException('TakeSeat : un siège neuf exige un pseudo et un avatar validés.');
        }

        // S4 — effectif présent, jamais l'historique des sièges.
        $headcount = Player::query()->whereBelongsTo($locked)->holdingSeat()->count();

        if ($headcount >= $locked->capacity) {
            return JoinRefusal::Full;
        }

        // S5 — unicité du pseudo, partis et expulsés compris.
        $normalized = NicknameNormalizer::normalize($nickname);

        if (Player::query()->whereBelongsTo($locked)->where('nickname_normalized', $normalized)->exists()) {
            throw self::nicknameTaken();
        }

        // S6 — la frappe, seulement maintenant, puis l'écriture du siège.
        $token = $this->tokens->ensure($request);

        $seat = new Player;
        $seat->forceFill([
            'public_id' => SeatPublicId::generate(),
            'room_id' => $locked->id,
            'nickname' => $nickname,
            'nickname_normalized' => $normalized,
            'player_token_hash' => $token->hash(),
            'locale' => $locale,
            'avatar_kind' => AvatarKind::Preset,
            'avatar_preset' => $avatarPreset,
            'joined_at' => $now,
            'last_seen_at' => $now,
            'connection_state' => PlayerConnectionState::Connected,
        ])->save();

        // S7 — salon en partie : le siège attend la partie suivante, sans
        // participation. L'admission d'un retardataire (§ 15.2) est le lot
        // L50-9.

        // S8 — réparation d'une référence d'hôte sans cible valide.
        if ($repairHost && ! TransferHost::hasValidHost($locked)) {
            $this->transferHost->automatic($locked, $now);
        }

        // S9 — source d'activité (§ 16.1), par mise à jour ciblée.
        Room::query()->whereKey($locked->id)->update(['last_activity_at' => $now]);

        // S10 — après la validation.
        $this->afterCommit($locked, $seat, $request, $token, $avatarPreset);

        return $seat;
    }

    /**
     * `seat.joined` au salon et re-signature du jeton avec l'avatar choisi,
     * APRÈS la validation de la transaction la plus externe — celle de
     * `CreateRoom` à la création. La vue du siège est composée à cet instant,
     * sur l'état validé : l'hôte qu'y pose la création (`TransferHost::to()`,
     * après ce geste) s'y lit donc déjà. Une transaction annulée n'émet rien
     * et ne re-signe rien.
     */
    private function afterCommit(Room $room, Player $seat, Request $request, PlayerToken $token, string $avatarPreset): void
    {
        DB::afterCommit(function () use ($room, $seat, $request, $token, $avatarPreset): void {
            $hostPlayerId = Room::query()->whereKey($room->id)->value('host_player_id');

            SeatJoined::dispatch($room, CurrentGame::of($seat), [
                'seat' => SeatViewPresenter::lobby($seat, is_int($hostPlayerId) ? $hostPlayerId : null),
            ]);

            $this->tokens->resign($request, $token->withAvatar($avatarPreset));
        });
    }

    /** Le siège de ce jeton dans ce salon, expulsé compris : c'est S3 qui le refuse. */
    private function heldBy(Room $room, PlayerToken $token): ?Player
    {
        return Player::query()->whereBelongsTo($room)->heldByToken($token)->first();
    }

    /** `validation.nickname.taken` sous `nickname`, dans la langue de la requête. */
    private static function nicknameTaken(): ValidationException
    {
        $message = __(ValidNickname::KEY_TAKEN);

        return ValidationException::withMessages([
            'nickname' => [is_string($message) ? $message : ValidNickname::KEY_TAKEN],
        ]);
    }
}
