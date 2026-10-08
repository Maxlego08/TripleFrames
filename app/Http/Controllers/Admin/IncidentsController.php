<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Curation\IncidentReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Films jamais trouvés et incidents (spec 20 § 12.1, ligne 29, L20-29) :
 * agrégat par film, sans aucune identité de joueur. Chaque ligne mène à la
 * fiche du film ; la réaction est un geste de curation ordinaire.
 *
 * L'agrégat est calculé en entier (quelques centaines de films au plus) puis
 * découpé en pages, au format `{ data, meta }` des autres listes du
 * back-office — jamais le tableau `links` d'un paginateur, dont les libellés
 * sont en anglais.
 */
final class IncidentsController extends Controller
{
    public const int PER_PAGE = 50;

    public function index(Request $request, IncidentReport $report): Response
    {
        $rows = $report->rows();
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($lastPage, max(1, $request->integer('page', 1)));
        $offset = ($page - 1) * self::PER_PAGE;
        $slice = array_slice($rows, $offset, self::PER_PAGE);

        $data = [];

        foreach ($slice as $row) {
            $data[] = [
                'movie' => [
                    'id' => $row['movie']->id,
                    'title_original' => $row['movie']->title_original,
                    'release_year' => $row['movie']->release_year,
                    'availability' => $row['movie']->availability->value,
                ],
                'completed' => $row['completed'],
                'never_found' => $row['never_found'],
                'cancelled' => $row['cancelled'],
                'substituted' => $row['substituted'],
            ];
        }

        return Inertia::render('admin/incidents/index', [
            'window_days' => IncidentReport::windowDays(),
            'reasons' => IncidentReport::reasons(),
            'incidents' => [
                'data' => $data,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'from' => $data === [] ? null : $offset + 1,
                    'to' => $data === [] ? null : $offset + count($data),
                ],
            ],
        ]);
    }
}
