<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\TakeSeat;
use App\Avatars\AvatarPresetCatalog;
use App\Enums\JoinRefusal;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\PresentsSeatForm;
use App\Http\Requests\Room\JoinRoomRequest;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'entrée libre dans un salon, par son code ou son lien (spec 50 § 7).
 *
 * **Le code ou le lien suffit**, dans la limite des sièges : l'hôte ne valide
 * pas chaque arrivée (§ 7.1). Deux routes, hors de `game.appearance` : la
 * page d'entrée suit l'apparence du visiteur, et un GET ne frappe jamais de
 * jeton (C4 I4.1).
 *
 * - `room.entry`, `GET /r/{room}/join` : le formulaire `room/join`
 *   (`PublicLayout`), ou 303 vers `room.show` pour un salon archivé ou un
 *   porteur de siège.
 * - `room.join`, `POST /r/{room}/join` : la prise de siège ({@see TakeSeat}),
 *   puis 303 vers `room.show`.
 */
class RoomEntryController extends Controller
{
    use PresentsSeatForm;

    /** Expulsé : aucun formulaire, `room.join.kicked`. */
    private const string ENTRY_KICKED = 'kicked';

    /** Effectif présent ≥ capacité : aucun formulaire, `room.join.full`. */
    private const string ENTRY_FULL = 'full';

    /**
     * Partie en cours, retardataires admis (§ 15) : formulaire et
     * `room.join.late_join`, le siège entrant à la manche suivante, sans
     * aucun point.
     */
    private const string ENTRY_LATE_JOIN = 'late_join';

    /**
     * Partie en cours dans tout autre cas — retardataires fermés, plus aucune
     * manche à démarrer, podium affiché : formulaire et
     * `room.join.in_progress`, le siège attendant la partie suivante.
     */
    private const string ENTRY_IN_PROGRESS = 'in_progress';

    /** Lobby : formulaire seul. */
    private const string ENTRY_OPEN = 'open';

    /**
     * Props : `room: { code }`, `entry`, `avatars: { options, taken,
     * suggested }` et `nickname: { min, max }` (§ 7.2). `taken` = les avatars
     * des sièges tenus, jamais un pseudo ni un `public_id` : un visiteur sans
     * siège ne voit pas qui est dans le salon.
     *
     * `entry` est INDICATIF : la prise de siège le recalcule sous verrou.
     */
    public function show(Request $request, Room $room, PlayerTokenManager $tokens): InertiaResponse|RedirectResponse
    {
        if ($room->status === RoomStatus::Archived || $tokens->seatIn($request, $room) !== null) {
            return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
        }

        $holding = Player::query()
            ->whereBelongsTo($room)
            ->holdingSeat()
            ->pluck('avatar_preset');

        $taken = array_flip(array_filter($holding->all(), is_string(...)));

        return Inertia::render('room/join', [
            'room' => ['code' => $room->room_code],
            'entry' => $this->entry($request, $room, $tokens, $holding->count()),
            'avatars' => $this->avatarProps(
                $tokens->current($request),
                array_values(array_filter(AvatarPresetCatalog::keys(), static fn (string $key): bool => isset($taken[$key]))),
            ),
            'nickname' => $this->nicknameProps(),
        ]);
    }

    /**
     * Refus : `Archived` → 303 vers `room.show`, sans erreur, qui rend
     * « salon expiré » ; `Kicked` et `Full` → retour au formulaire d'entrée,
     * erreur `room` traduite dans la langue de la requête (§ 7.3). Un pseudo
     * pris revient en erreur de champ (`ValidationException`).
     *
     * @throws ValidationException
     */
    public function store(JoinRoomRequest $request, Room $room, TakeSeat $takeSeat): RedirectResponse
    {
        $outcome = $takeSeat->handle(
            $room,
            $request,
            $request->nickname(),
            $request->avatarPreset(),
            $this->effectiveLocale(),
        );

        $messageKey = $outcome instanceof JoinRefusal ? $outcome->messageKey() : null;

        if ($messageKey === null) {
            return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
        }

        $message = __($messageKey);

        return back(Response::HTTP_SEE_OTHER, fallback: route('room.entry', $room))
            ->withErrors(['room' => is_string($message) ? $message : $messageKey]);
    }

    /**
     * L'état de la page d'entrée, dans l'ordre de priorité du § 7.2 :
     * expulsé, complet, retardataires admis, partie en cours, lobby.
     */
    private function entry(Request $request, Room $room, PlayerTokenManager $tokens, int $headcount): string
    {
        if ($tokens->wasKickedFrom($request, $room)) {
            return self::ENTRY_KICKED;
        }

        if ($headcount >= $room->capacity) {
            return self::ENTRY_FULL;
        }

        if ($room->status !== RoomStatus::Playing) {
            return self::ENTRY_OPEN;
        }

        return self::lateJoinOpen($room) ? self::ENTRY_LATE_JOIN : self::ENTRY_IN_PROGRESS;
    }

    /**
     * `late_join` (§ 7.2, § 15.2) : salon ouvert aux retardataires (projection
     * `allow_late_join`), partie en cours — la dernière du salon, `ended_at`
     * NULL — et une manche numérotée `pending` qui reste à démarrer,
     * remplaçante comprise ({@see Round::lateJoinableAt()}). Lecture simple,
     * sans verrou : l'état est indicatif, la prise de siège le recalcule sous
     * verrou.
     */
    private static function lateJoinOpen(Room $room): bool
    {
        if (! $room->allow_late_join) {
            return false;
        }

        $game = Game::query()
            ->where('room_id', $room->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($game === null || $game->ended_at !== null) {
            return false;
        }

        return Round::query()
            ->where('game_id', $game->id)
            ->lateJoinableAt(Date::now()->toImmutable())
            ->exists();
    }
}
