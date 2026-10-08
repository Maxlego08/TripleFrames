<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Account\PlayHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * « Mes parties » — `history.index` et `history.show` (spec 40 § 13.4,
 * L40-13, D66 du 07/10, n° 29 à 31).
 *
 * Lecture seule, sur le seul lecteur {@see PlayHistory}. Le détail d'une
 * partie est adressé par `game.public_id`, jamais par l'`id` ; la garde de
 * propriété `GamePolicy::viewHistory` répond 404 à toute partie qui n'est
 * pas dans l'historique du compte. Le numéro de page est lu avec
 * indulgence : une valeur absente ou fausse rend la première page.
 */
class PlayHistoryController extends Controller
{
    public function index(Request $request, PlayHistory $history): Response
    {
        $user = self::user($request);
        $page = $history->page($user, $request->integer('page', 1));

        return Inertia::render('settings/history', [
            'summary' => $history->summary($user),
            'games' => array_values($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, Game $game, PlayHistory $history): Response
    {
        $user = self::user($request);

        Gate::forUser($user)->authorize('viewHistory', $game);

        $seat = $history->seatFor($user, $game);

        if (! $seat instanceof GamePlayer) {
            abort(404);
        }

        return Inertia::render('settings/history-show', [
            ...$history->detail($game, $seat),
            'windowMonths' => PlatformLimits::historyWindowMonths(),
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('« Mes parties » exige le middleware auth.');
        }

        return $user;
    }
}
