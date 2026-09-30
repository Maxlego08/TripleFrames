<?php

namespace App\Actions\Room;

use App\Enums\AvatarKind;
use App\Enums\GamePlayerStatus;
use App\Enums\JoinRefusal;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Rules\ValidNickname;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\SeatPublicId;
use Carbon\CarbonImmutable;
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
 * - S7 : salon en partie → **admission d'un retardataire** (§ 15.2) si le
 *   salon leur est ouvert (`allow_late_join`, lu en projection) et qu'une
 *   manche numérotée reste à démarrer, sinon attente de la partie suivante,
 *   sans participation ; au lobby, rien ;
 * - S8 : si `$repairHost`, hôte sans cible valide → `TransferHost::automatic()` ;
 * - S9 : `last_activity_at`, par mise à jour ciblée ;
 * - S10 : APRÈS la validation, `seat.joined` au salon — `firstRoundNumber`
 *   compris pour un retardataire admis (§ 15.3) — et re-signature du jeton
 *   avec l'avatar choisi (I4.5).
 *
 * **Admission (S7, § 15.2)**, sous l'ordre global : la dernière partie du
 * salon est relue `FOR UPDATE` après le siège (`room → player → game`,
 * comme {@see SeatDeparture::markParticipation()}) — `ended_at` NULL sous ce
 * verrou, sinon attente : un gel concurrent (interruption d'une partie en
 * pause, qui laisse ses manches `pending`) est attendu, jamais lu périmé,
 * et aucune participation ne naît dans une partie figée sans ses agrégats.
 * Puis la **prochaine manche dans l'ordre de jeu** ({@see Round::lateJoinableAt()}) :
 * la candidate est lue `FOR UPDATE` (`game → round`), son prédicat relu sur
 * la ligne verrouillée ; une manche ouverte entre-temps par `OpenTier(1)`
 * est passée, boucle bornée par le nombre de manches. La participation
 * naît `playing`, `first_round_number` = numéro de la manche, identité
 * gelée depuis le siège ; `60` crée sa ligne `round_player` à `T₁` de cette
 * manche, et le service d'image lui refuse la manche en cours (E10-20). Le
 * drainage ne bloque jamais une admission (C17).
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

        // S7 — salon en partie : admission d'un retardataire (§ 15.2), ou
        // attente de la partie suivante, sans participation.
        if ($locked->status === RoomStatus::Playing) {
            $this->admitLateJoiner($locked, $seat, $now);
        }

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
     * S7 — l'admission d'un retardataire (§ 15.2), dans la transaction de la
     * prise de siège, salon verrouillé et siège écrit. Rend la participation
     * née, ou `null` : attente de la partie suivante, aucune ligne
     * `game_player` — retardataires fermés, aucune partie en cours (podium),
     * ou plus aucune manche numérotée à démarrer.
     */
    private function admitLateJoiner(Room $lockedRoom, Player $seat, CarbonImmutable $now): ?GamePlayer
    {
        // Étape 1 — retardataires fermés : attente, sans aucun verrou de plus.
        if (! $lockedRoom->allow_late_join) {
            return null;
        }

        // Étape 1 — partie en cours = dernière partie du salon à `ended_at`
        // NULL, relue sous son verrou (`room → player → game`).
        $game = Game::query()
            ->where('room_id', $lockedRoom->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($game === null || $game->ended_at !== null) {
            return null;
        }

        // Étapes 2 et 3 — la prochaine manche dans l'ordre de jeu, sinon
        // attente.
        $round = self::nextRoundToJoin($game, $now);

        if ($round === null) {
            return null;
        }

        // Étape 4 — la participation : `playing`, entrée à cette manche,
        // pseudo et avatar prédéfini gelés depuis le siège (E10-42) ; les
        // cinq agrégats restent nuls, seul le gel les écrit.
        $participation = new GamePlayer;
        $participation->forceFill([
            'game_id' => $game->id,
            'player_id' => $seat->id,
            'display_nickname' => $seat->nickname,
            'display_avatar_kind' => $seat->avatar_kind,
            'display_avatar_preset' => $seat->avatar_preset,
            'first_round_number' => $round->round_number,
            'status' => GamePlayerStatus::Playing,
        ])->save();

        return $participation;
    }

    /**
     * La prochaine manche où entrer (§ 15.2, étape 2) : la première candidate
     * dans l'ordre de jeu est lue `FOR UPDATE` — une lecture verrouillante
     * lit la dernière version validée, là où une lecture simple lirait
     * l'instantané pris plus tôt dans la transaction (InnoDB, `REPEATABLE
     * READ`) et manquerait une remplaçante numérotée entre-temps —, puis son
     * prédicat est relu sur la ligne verrouillée. Une manche que `OpenTier(1)`
     * a ouverte entre-temps est passée ; la boucle est bornée par le nombre
     * de manches de la partie.
     */
    private static function nextRoundToJoin(Game $game, CarbonImmutable $now): ?Round
    {
        $bound = Round::query()->where('game_id', $game->id)->count();
        $passed = [];

        for ($attempt = 0; $attempt < $bound; $attempt++) {
            $candidate = Round::query()
                ->where('game_id', $game->id)
                ->lateJoinableAt($now)
                ->whereNotIn('id', $passed)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                return null;
            }

            if ($candidate->isLateJoinableAt($now)) {
                return $candidate;
            }

            $passed[] = $candidate->id;
        }

        return null;
    }

    /**
     * `seat.joined` au salon et re-signature du jeton avec l'avatar choisi,
     * APRÈS la validation de la transaction la plus externe — celle de
     * `CreateRoom` à la création. La vue du siège est composée à cet instant,
     * sur l'état validé : l'hôte qu'y pose la création (`TransferHost::to()`,
     * après ce geste) s'y lit donc déjà. Un retardataire admis est vu **en
     * partie** (identité gelée, `firstRoundNumber`, § 15.3) ; un siège qui
     * attend la partie suivante, au lobby. Une transaction annulée n'émet
     * rien et ne re-signe rien.
     */
    private function afterCommit(Room $room, Player $seat, Request $request, PlayerToken $token, string $avatarPreset): void
    {
        DB::afterCommit(function () use ($room, $seat, $request, $token, $avatarPreset): void {
            $hostPlayerId = Room::query()->whereKey($room->id)->value('host_player_id');
            $game = CurrentGame::of($seat);

            SeatJoined::dispatch($room, $game, [
                'seat' => SeatViewPresenter::ofSeat($seat, $game, is_int($hostPlayerId) ? $hostPlayerId : null),
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
