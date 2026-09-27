<?php

namespace App\Http\Controllers\Room\Concerns;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Support\Game\GameJournal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ce que partagent les réponses des deux gestes d'hôte qui ouvrent ou
 * referment une partie — le lancement et « Rejouer » (spec 50 § 12.5, § 13 ;
 * contrat C6) : le retour à la page du salon avec un message traduit, la
 * relecture du statut après un échec, et la ligne du journal `game` d'un
 * échec technique.
 *
 * **Aucune réponse 409 ni 422 à une visite Inertia** (§ 12.5) : un refus de
 * règle et un échec technique reviennent par une redirection 303, l'erreur
 * sous `room`, dans la langue de la requête.
 */
trait AnswersRoomGesture
{
    /**
     * Retour à la page du salon, d'où part le geste — jamais l'accueil, même
     * sans en-tête `Referer` —, avec le message traduit sous `room`.
     *
     * @param  array<string, int>  $replace
     */
    private static function backToRoom(Room $room, string $messageKey, array $replace = []): RedirectResponse
    {
        $message = __($messageKey, $replace);

        return back(Response::HTTP_SEE_OTHER, fallback: route('room.show', $room))
            ->withErrors(['room' => is_string($message) ? $message : $messageKey]);
    }

    /**
     * Le statut du salon, relu hors de la transaction terminée. Une lecture
     * en échec (base perdue) vaut `null` : l'hôte lit alors l'échec traduit
     * et peut recommencer, chaque geste étant idempotent.
     */
    private static function rereadStatus(Room $room): ?RoomStatus
    {
        try {
            $status = Room::query()->whereKey($room->id)->value('status');

            return $status instanceof RoomStatus ? $status : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * La ligne du journal `game` d'un geste en échec technique : la classe,
     * le code et le lieu de l'exception (fichier relatif à la racine du
     * dépôt, séparateurs `/`), la classe de sa cause, puis l'état relu que
     * nomme l'appelant. Ni message ni trace : ils peuvent citer une requête
     * et ses valeurs, pseudo gelé compris. `report()` n'est pas appelé : le
     * journal par défaut garderait le message. Écrire le journal n'échoue
     * jamais la réponse.
     *
     * @param  array<string, bool>  $state
     */
    private static function journalGestureFailure(string $label, Throwable $exception, array $state): void
    {
        try {
            Log::channel(GameJournal::CHANNEL)->error($label, [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'file' => str_replace('\\', '/', Str::after($exception->getFile(), base_path().DIRECTORY_SEPARATOR)),
                'line' => $exception->getLine(),
                'previous' => $exception->getPrevious() === null ? null : $exception->getPrevious()::class,
                ...$state,
            ]);
        } catch (Throwable) {
            // Le journal ne décide rien : la réponse traduite part quand même.
        }
    }
}
