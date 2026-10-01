<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlayerInspectionRequest;
use App\Models\Player;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Admin\AdminJournal;
use App\Support\Admin\GameInspectionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'annuaire des sièges, invités compris, et la fiche d'un siège — spec 20
 * § 12.2, ligne 42 de la matrice des capacités (D46 du 01/10),
 * administrateur seul.
 *
 * Un siège (`player`) naît à l'entrée dans un salon ou en solo ; son pseudo
 * d'invité est effacé à l'archivage (spec 10 § 11.1) et s'affiche alors
 * « effacé ». La fiche reprend ses parties et, manche par manche, ses
 * réponses justes et fausses, sous la règle 3 ({@see GameInspectionPresenter}).
 *
 * **Deux lectures sensibles** : `players.directory_viewed`, `player.viewed`.
 * La recherche n'est jamais recopiée au journal.
 */
class PlayerInspectionController extends Controller
{
    /**
     * L'annuaire, du siège le plus récent au plus ancien.
     */
    public function index(PlayerInspectionRequest $request, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $players = $this->filtered($request)
            ->with(['room', 'user'])
            ->withCount('gamePlayers')
            ->orderByDesc('id')
            ->paginate(PlayerInspectionRequest::PER_PAGE)
            ->withQueryString();

        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::PlayersDirectoryViewed, null);
        }

        return Inertia::render('admin/players/index', [
            'players' => AdminCatalogPresenter::paginated(
                $players,
                fn (Player $player): array => GameInspectionPresenter::playerRow($player),
            ),
            'filters' => $request->filters(),
            'options' => [
                'mode' => PlayerInspectionRequest::MODES,
            ],
            'counts' => [
                'total' => Player::query()->count(),
                'solo' => Player::query()->whereNull('room_id')->count(),
            ],
        ]);
    }

    /**
     * La fiche d'un siège, liée par `public_id`.
     */
    public function show(Request $request, Player $player, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $detail = GameInspectionPresenter::playerDetail($player);

        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::PlayerViewed, $player->id);
        }

        return Inertia::render('admin/players/show', $detail);
    }

    /**
     * @return Builder<Player>
     */
    private function filtered(PlayerInspectionRequest $request): Builder
    {
        $query = Player::query();

        $search = $request->search();

        if ($search !== null) {
            $query->where(function (Builder $scoped) use ($search): void {
                $scoped->where('nickname', 'like', '%'.$search.'%')
                    ->orWhere('public_id', $search);
            });
        }

        $mode = $request->mode();

        if ($mode === 'solo') {
            $query->whereNull('room_id');
        } elseif ($mode === 'room') {
            $query->whereNotNull('room_id');
        }

        return $query;
    }
}
