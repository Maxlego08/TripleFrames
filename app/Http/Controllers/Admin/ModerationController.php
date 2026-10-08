<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Room\ReportSeatNickname;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ReportTarget;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Player;
use App\Models\Report;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Moderation\NicknameModerationState;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Modération » des pseudos — ligne 35 de la matrice, spec 20
 * § 11.5 ; règle : spec 40 § 13.3 (D66 du 07/10). Administrateur seul.
 *
 * Une ligne par siège masqué — banni compris, un bannissement posant le
 * masquage —, plus récents en tête : pseudo (montré à l'administrateur
 * seul), salon, compte rattaché éventuel, instant du masquage,
 * `reports_count` de la dernière ligne `nickname.masked`, état « banni » lu
 * dans le journal, signaleurs du siège avec le nombre de leurs signalements
 * dans la fenêtre de rétention de `report` (pour repérer un signaleur
 * abusif, jamais nommé aux joueurs). En tête, les **formes à ajouter à la
 * liste noire** : sièges bannis dont le pseudo n'est pas encore anonymisé,
 * forme repliée à recopier dans `resources/moderation/nicknames/banned.txt`
 * au commit suivant.
 *
 * Les avatars se modèrent sur l'écran « Avatars » (ligne 45), jamais ici.
 */
class ModerationController extends Controller
{
    /** Sièges masqués par page. */
    public const int PER_PAGE = 25;

    public function index(): Response
    {
        $seats = Player::query()
            ->whereNotNull('nickname_masked_at')
            ->with(['room:id,room_code', 'user:id,name'])
            ->orderByDesc('nickname_masked_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<int> $ids */
        $ids = array_values(array_map(static fn (Player $seat): int => $seat->id, $seats->items()));
        $banned = NicknameModerationState::bannedAmong($ids);

        return Inertia::render('admin/moderation/index', [
            'seats' => AdminCatalogPresenter::paginated(
                $seats,
                fn (Player $seat): array => self::row($seat, in_array($seat->id, $banned, true)),
            ),
            'blocklist' => self::blocklistForms(),
            'counts' => [
                'masked' => Player::query()->whereNotNull('nickname_masked_at')->count(),
            ],
        ]);
    }

    /**
     * Une ligne de l'écran.
     *
     * @return array{id: int, public_id: string, nickname: string|null, room_code: string|null, account: array{id: int, name: string}|null, masked_at: string|null, reports_count: int|null, current_reports: int, banned: bool, reporters: list<array{id: int, nickname: string|null, reports: int}>}
     */
    private static function row(Player $seat, bool $banned): array
    {
        $masking = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Player->value)
            ->where('subject_id', $seat->id)
            ->where('action', AdminActionType::NicknameMasked->value)
            ->orderByDesc('id')
            ->first(['id', 'reports_count']);

        $user = $seat->user;

        return [
            'id' => $seat->id,
            'public_id' => $seat->public_id,
            'nickname' => $seat->nickname,
            'room_code' => $seat->room?->room_code,
            'account' => $user === null ? null : ['id' => $user->id, 'name' => $user->name],
            'masked_at' => $seat->nickname_masked_at?->toIso8601String(),
            'reports_count' => $masking?->reports_count,
            'current_reports' => ReportSeatNickname::reportersSince($seat),
            'banned' => $banned,
            'reporters' => self::reporters($seat),
        ];
    }

    /**
     * Les sièges qui ont signalé ce pseudo, chacun avec le nombre TOTAL de
     * ses signalements de pseudo encore conservés (fenêtre de rétention de
     * `report`, 12 mois) : un signaleur qui signale tout le salon se repère.
     *
     * @return list<array{id: int, nickname: string|null, reports: int}>
     */
    private static function reporters(Player $seat): array
    {
        $reporterIds = Report::query()
            ->where('target_type', ReportTarget::Nickname->value)
            ->where('target_player_id', $seat->id)
            ->pluck('reporter_player_id')
            ->all();

        if ($reporterIds === []) {
            return [];
        }

        $counts = Report::query()
            ->where('target_type', ReportTarget::Nickname->value)
            ->whereIn('reporter_player_id', $reporterIds)
            ->selectRaw('reporter_player_id, count(*) as reports')
            ->groupBy('reporter_player_id')
            ->pluck('reports', 'reporter_player_id');

        $rows = [];

        foreach (Player::query()->whereIn('id', $reporterIds)->orderBy('id')->get(['id', 'nickname']) as $reporter) {
            $count = $counts->get($reporter->id);

            $rows[] = [
                'id' => $reporter->id,
                'nickname' => $reporter->nickname,
                'reports' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        return $rows;
    }

    /**
     * Les formes à recopier dans la liste noire versionnée : sièges bannis
     * dont le pseudo n'est pas encore anonymisé, forme repliée
     * (`nickname_normalized`) — jamais écrite au journal.
     *
     * @return list<array{id: int, nickname: string, form: string}>
     */
    private static function blocklistForms(): array
    {
        $candidates = AdminAction::query()
            ->where('subject_type', AdminActionSubject::Player->value)
            ->where('action', AdminActionType::NicknameBanned->value)
            ->distinct()
            ->pluck('subject_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->values()
            ->all();

        /** @var list<int> $candidates */
        $banned = NicknameModerationState::bannedAmong($candidates);

        if ($banned === []) {
            return [];
        }

        $forms = [];

        $seats = Player::query()
            ->whereIn('id', $banned)
            ->whereNotNull('nickname')
            ->whereNotNull('nickname_normalized')
            ->orderBy('id')
            ->get(['id', 'nickname', 'nickname_normalized']);

        foreach ($seats as $seat) {
            $forms[] = [
                'id' => $seat->id,
                'nickname' => (string) $seat->nickname,
                'form' => (string) $seat->nickname_normalized,
            ];
        }

        return $forms;
    }
}
