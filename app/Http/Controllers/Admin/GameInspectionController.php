<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionType;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GameInspectionRequest;
use App\Models\Game;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Admin\AdminJournal;
use App\Support\Admin\GameInspectionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les parties, en cours et terminées, et la fiche d'une partie — spec 20
 * § 12.2, ligne 36 de la matrice des capacités (D46 du 01/10),
 * administrateur seul.
 *
 * Salon et solo dans la même liste. La fiche montre chaque réponse, juste ou
 * fausse, de chaque participant — jamais rien d'une manche non révélée d'une
 * partie en cours ({@see GameInspectionPresenter}, règle 3).
 *
 * **Deux lectures sensibles** (D41 du 30/09) : `games.directory_viewed` à
 * chaque visite de la liste, `game.viewed` à chaque visite d'une fiche — une
 * ligne par visite qui compte ({@see AdminJournal::countsAsVisit()}). Les
 * filtres ne sont jamais recopiés.
 */
class GameInspectionController extends Controller
{
    /**
     * La liste, pilotée par la query string.
     */
    public function index(GameInspectionRequest $request, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $games = $this->filtered($request)
            ->with('room')
            ->withCount('gamePlayers')
            ->paginate(GameInspectionRequest::PER_PAGE)
            ->withQueryString();

        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::GamesDirectoryViewed, null);
        }

        return Inertia::render('admin/games/index', [
            'games' => AdminCatalogPresenter::paginated(
                $games,
                fn (Game $game): array => GameInspectionPresenter::gameRow($game),
            ),
            'filters' => $request->filters(),
            'options' => [
                'state' => GameInspectionRequest::STATES,
                'mode' => array_column(GameMode::cases(), 'value'),
            ],
            'counts' => [
                'running' => self::running(Game::query())->count(),
                'ended' => Game::query()->whereNotNull('ended_at')->count(),
            ],
        ]);
    }

    /**
     * La fiche d'une partie.
     */
    public function show(Request $request, Game $game, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $detail = GameInspectionPresenter::gameDetail($game);

        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::GameViewed, $game->id);
        }

        return Inertia::render('admin/games/show', $detail);
    }

    /**
     * En cours : les plus anciennes d'abord ; terminées : les plus récentes
     * d'abord. Toujours départagé par `id`.
     *
     * @return Builder<Game>
     */
    private function filtered(GameInspectionRequest $request): Builder
    {
        $query = Game::query();

        if ($request->state() === 'ended') {
            $query->whereNotNull('ended_at')->orderByDesc('ended_at')->orderByDesc('id');
        } else {
            self::running($query)->orderBy('started_at')->orderBy('id');
        }

        $mode = $request->mode();

        if ($mode !== null) {
            $query->where('mode', $mode);
        }

        $roomCode = $request->roomCode();

        if ($roomCode !== null) {
            // Égalité sur `room_code_idx`, jamais un `LIKE` : un code est
            // court et recyclé, une recherche partielle rendrait des salons
            // sans rapport.
            $query->whereHas('room', fn (Builder $room) => $room->where('room_code', $roomCode));
        }

        return $query;
    }

    /**
     * Une partie en cours : `ended_at` nul, `running` ou `paused`.
     *
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    private static function running(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->whereIn('status', [GameStatus::Running, GameStatus::Paused]);
    }
}
