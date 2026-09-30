<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\LaunchGame;
use App\Actions\Room\UpdateRoomSettings;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\AnswersRoomGesture;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\ValueObjects\Room\LaunchOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * L'hôte lance la partie — `room.launch`, `POST /r/{room}/launch` (spec 50
 * § 12, § 21 ; contrat C6).
 *
 * Pile : `seat.active` (siège du jeton, onglet actif, sinon 403 ou 409
 * `seat_superseded`), puis `throttle:game-write`, puis la liaison du salon.
 * La policy ({@see RoomPolicy::launch()}) est une première garde ;
 * {@see LaunchGame} relit tout sous le verrou du salon.
 *
 * **Réponses**, à destinataire unique, donc dans la langue de la requête
 * (§ 12.5) — jamais un 409 ni un 422 à une visite Inertia :
 * - lancée : 303 vers la page du salon, qui montre l'état de partie ;
 * - `not_host` (hôte changé entre la policy et le verrou) : 403 ;
 * - `not_in_lobby` : 303 vers la page du salon, SANS erreur — le double clic
 *   est idempotent ;
 * - tout autre refus : retour au salon avec l'erreur `room`, plus le rapport
 *   `settingsChanges` en flash pour `settings_outdated`. Sur
 *   `settings_outdated` et `pool_insufficient`, la diffusion anti-rebondie de
 *   l'état du lobby part au salon après la transaction (§ 12.2 L5, § 12.3
 *   O3) : les autres sièges voient les réglages normalisés, et tout le monde
 *   le même blocage ;
 * - **échec technique** (tirage, matérialisation, programmation, base, délai
 *   de verrou) : une ligne part au journal `game` sans donnée personnelle —
 *   la classe de l'exception, son code, son lieu (fichier relatif à la racine
 *   du dépôt, ligne) et la classe de sa cause, jamais son message, qui peut
 *   citer une requête et ses valeurs. Levée DANS la transaction, l'exception
 *   l'annule : le salon reste au lobby et l'hôte reçoit
 *   `room.errors.launch_failed`, jamais une 500 brute en anglais. Ce n'est
 *   pas un refus de règle : {@see RoomRefusal} n'est pas étendu ;
 * - **échec après la validation** : un rappel `afterCommit` (poussée du job
 *   de frontière de la manche 1 sur une file en panne) lève APRÈS le COMMIT,
 *   et l'exception remonte quand même hors de `DB::transaction()`. La partie
 *   est née et le salon est en `playing` : le statut, relu, rend alors la
 *   même réponse qu'un lancement réussi, sans erreur. La manche sans job est
 *   rattrapée par la resynchronisation (`CatchUpGame`, 60 § 4.4, § 17.5), et
 *   la ligne du journal le signale (`roomPlaying`).
 */
class LaunchController extends Controller
{
    use AnswersRoomGesture;

    /** Message d'un échec technique du lancement, rendu à l'hôte (§ 12.5, § 20.3). */
    public const string KEY_LAUNCH_FAILED = 'room.errors.launch_failed';

    /** Libellé de la ligne du journal `game` d'un lancement en échec (§ 12.5). */
    public const string LOG_LAUNCH_FAILED = 'room.launch_failed';

    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, LaunchGame $launch): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le lancement exige le middleware seat.active (spec 50 § 12.1, § 21).');

        Gate::authorize('launch', [$room, $seat]);

        try {
            $outcome = $launch->handle($room, $seat);
        } catch (Throwable $exception) {
            $playing = self::rereadStatus($room) === RoomStatus::Playing;
            self::journalGestureFailure(self::LOG_LAUNCH_FAILED, $exception, ['roomPlaying' => $playing]);

            // Levée après le COMMIT : la partie est née, rien n'a échoué pour
            // l'hôte. Le salon ne peut être en `playing` ici que par une
            // transaction validée — la sienne, ou une concurrente, et c'est
            // alors le double clic idempotent de L4.
            if ($playing) {
                return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
            }

            return self::backToRoom($room, self::KEY_LAUNCH_FAILED);
        }

        if ($outcome->isLaunched()) {
            return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
        }

        return match ($outcome->refusal) {
            RoomRefusal::NotHost => abort(Response::HTTP_FORBIDDEN),
            RoomRefusal::NotInLobby => to_route('room.show', $room, Response::HTTP_SEE_OTHER),
            default => $this->refused($room, $outcome),
        };
    }

    /**
     * Un refus de règle rendu dans la page du salon, erreur `room`.
     */
    private function refused(Room $room, LaunchOutcome $outcome): RedirectResponse
    {
        $refusal = $outcome->refusal
            ?? throw new LogicException('LaunchController : une issue lancée n’est pas un refus.');

        if ($refusal === RoomRefusal::SettingsOutdated) {
            Inertia::flash('settingsChanges', $outcome->changes);
        }

        if ($refusal === RoomRefusal::SettingsOutdated || $refusal === RoomRefusal::PoolInsufficient) {
            UpdateRoomSettings::dispatchLobbyBroadcast($room, Date::now()->toImmutable());
        }

        return self::backToRoom($room, $refusal->messageKey(), $outcome->replace);
    }
}
