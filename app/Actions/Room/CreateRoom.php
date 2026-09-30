<?php

namespace App\Actions\Room;

use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Settings\RoomSettings;
use App\Support\Room\RoomCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * « Créer un salon » crée le salon TOUT DE SUITE, aux réglages par défaut ;
 * tout se règle ensuite dans le lobby (spec 50 § 6.1 et § 6.4).
 *
 * Une transaction :
 * 1. `$now` ;
 * 2. `RoomSettings::defaults()` ([J2] ou la configuration par défaut du
 *    compte, normalisée, § 18.3) ;
 * 3. un salon non persisté : `room_code = room_code_active =
 *    RoomCode::generate()`, `status = lobby` ;
 * 4. {@see WriteRoomSettings}, qui insère la ligne avec sa projection ;
 * 5. {@see TakeSeat} pour le créateur, `repairHost: false` — sans garde de
 *    capacité à franchir, et sans poser l'hôte une première fois ;
 * 6. {@see TransferHost::to()} : **la création pose l'hôte par l'action de
 *    transfert, dans la même transaction** (E10-34bis) — un seul écrivain de
 *    `room.host_player_id`, un seul `host.changed`.
 *
 * Après la validation : `seat.joined`, puis `host.changed` (émissions
 * inoffensives : seul le créateur est au salon).
 *
 * **Course sur le code** : `RoomCode::generate()` LIT l'absence d'un code
 * dans `room_code_active`, sans la réserver ; deux créations concurrentes
 * peuvent tirer le même. Une violation de `room_active_code_uq` à
 * l'insertion relance la transaction entière, code retiré compris, dans la
 * limite de `RoomCode::MAX_ATTEMPTS` ; au-delà, une `RuntimeException`,
 * journalisée par le gestionnaire d'exceptions : l'échec bruyant d'un état
 * impossible (§ 6.3).
 *
 * **Le jeton est frappé par la prise de siège**, au moment d'écrire le siège
 * (C4 I4.1 ; 40 § 2.1, étape 4) — c'est l'un des deux seuls gestes qui en
 * frappent un, avec `room.join`. La requête est donc passée telle quelle.
 */
final readonly class CreateRoom
{
    public function __construct(
        private WriteRoomSettings $writer,
        private TakeSeat $takeSeat,
        private TransferHost $transferHost,
    ) {}

    /**
     * @param  Request  $request  La requête du geste, d'où la prise de siège
     *                            lit et frappe le jeton.
     * @param  string  $nickname  Forme canonique validée.
     * @param  string  $avatarPreset  Clé du catalogue validée.
     * @param  Locale  $locale  Locale effective de la requête.
     * @param  User|null  $user  Compte connecté : au J1, sans effet (C4
     *                           I4.10) ; au J2, sa configuration par défaut.
     *
     * @throws RuntimeException Code de salon introuvable après
     *                          `RoomCode::MAX_ATTEMPTS` collisions.
     */
    public function handle(Request $request, string $nickname, string $avatarPreset, Locale $locale, ?User $user): Room
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): Room => $this->create($request, $nickname, $avatarPreset, $locale));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= RoomCode::MAX_ATTEMPTS) {
                    throw new RuntimeException(sprintf(
                        'CreateRoom : %d tentatives de création, chacune sur un code déjà actif.',
                        RoomCode::MAX_ATTEMPTS,
                    ), previous: $exception);
                }
            }
        }
    }

    /**
     * Les étapes 1 à 6, dans la transaction.
     *
     * @throws ValidationException
     */
    private function create(Request $request, string $nickname, string $avatarPreset, Locale $locale): Room
    {
        $now = Date::now()->toImmutable();
        $settings = RoomSettings::defaults();
        $code = RoomCode::generate();

        $room = new Room;
        $room->room_code = $code;
        $room->room_code_active = $code;
        $room->status = RoomStatus::Lobby;

        $this->writer->handle($room, $settings, $now);

        $seat = $this->takeSeat->handle($room, $request, $nickname, $avatarPreset, $locale, repairHost: false);

        if (! $seat instanceof Player) {
            throw new LogicException(sprintf(
                'CreateRoom : la prise de siège du créateur a été refusée (%s) dans un salon qui vient de naître.',
                $seat->value,
            ));
        }

        $this->transferHost->to($room, $seat, $now);

        return $room;
    }
}
