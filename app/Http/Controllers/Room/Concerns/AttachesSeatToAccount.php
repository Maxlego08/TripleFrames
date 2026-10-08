<?php

namespace App\Http\Controllers\Room\Concerns;

use App\Actions\Identity\ClaimSeatForAccount;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Le rattachement automatique au rendu d'une page de siège — `room.show` et
 * `solo.show` (spec 40 § 13.2, D66 du 07/10, n° 23 et n° 24).
 *
 * Le siège de la page, et lui seul, pour un compte connecté ; l'avis traduit
 * `room.seat.claimed` part en flash `game_notice` — jamais `toast` : une page
 * de jeu n'émet aucun toast (spec 90 § 2.3) —, que `GameLayout` rend en texte
 * dans la page (`useFlashNotice`), une seule fois : un rendu suivant ne
 * trouve plus de siège à lier.
 */
trait AttachesSeatToAccount
{
    /** Clé de l'avis d'un rattachement. */
    public const string KEY_SEAT_CLAIMED = 'room.seat.claimed';

    /** Clé du flash d'avis d'une page de jeu, lue par `useFlashNotice`. */
    public const string FLASH_KEY = 'game_notice';

    protected function attachToAccount(Request $request, Player $seat, ClaimSeatForAccount $attach): void
    {
        $user = $request->user();

        if (! $user instanceof User || ! $attach->handle($seat, $user)) {
            return;
        }

        $message = __(self::KEY_SEAT_CLAIMED);

        Inertia::flash(self::FLASH_KEY, [
            'message' => is_string($message) ? $message : self::KEY_SEAT_CLAIMED,
        ]);
    }
}
