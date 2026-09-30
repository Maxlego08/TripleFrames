<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminJournalRequest;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Admin\AdminJournalPresenter;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Journal » — spec 20 § 2.2, ligne 41 (D41 du 30/09),
 * **administrateur seul** : tout `admin_action`, du plus récent au plus
 * ancien, filtrable par acteur, type d'action, période et sujet.
 *
 * **En lecture, et rien d'autre.** Aucune route n'écrit, ne corrige ni ne
 * supprime une ligne (ligne 37) ; l'écran n'envoie aucun booléen de geste.
 *
 * **Honnêteté sur les index** : le filtre de sujet suit
 * `admin_action_subject_idx (subject_type, subject_id, created_at)`, celui de
 * l'acteur `admin_action_actor_idx (actor_id, created_at)` — les deux chemins
 * qu'ouvrent les liens « Historique » et le choix d'un auteur ; le raccourci
 * `movie` les réunit en deux branches servies chacune par l'index de sujet,
 * celle des images par la clé étrangère `frame.movie_id`. Le filtre par
 * type d'action seul, les deux acteurs réservés (lus sur `actor_name`) et la
 * période seule ne croisent aucun index : un balayage de la table, assumé à
 * l'échelle d'un journal d'administration d'instance unique. Aucun filtre ne
 * lit `details` : colonne JSON affichée, jamais interrogée.
 *
 * Le tri est `created_at` puis `id`, tous deux décroissants : l'ordre des
 * identifiants est l'ordre du journal, et le second critère départage deux
 * lignes écrites dans la même seconde, sans quoi elles s'échangeraient de
 * place d'une page à l'autre.
 *
 * **La visite de cet écran n'écrit aucune ligne** : D41 du 30/09 ne range
 * parmi les lectures sensibles que l'annuaire, la fiche d'un compte et
 * l'écran des accès. Journaliser la lecture du journal est une exigence à
 * adresser à `10` si elle est voulue, jamais un cas ajouté d'ici.
 */
class JournalController extends Controller
{
    /**
     * Le journal, entièrement piloté par la query string.
     */
    public function index(AdminJournalRequest $request): Response
    {
        $page = $this->filtered($request)
            ->paginate(AdminJournalRequest::PER_PAGE)
            ->withQueryString();

        $movieId = $request->movieId();
        // Le raccourci `movie` se présente comme le sujet « film » : le
        // bandeau nomme le film, et précise que ses images sont comprises.
        $subjectType = $movieId !== null ? AdminActionSubject::Movie : $request->subjectType();
        $subjectId = $movieId ?? $request->subjectId();

        // Le sujet du filtre est résolu AVEC ceux de la page, dans les mêmes
        // requêtes : le bandeau et le tableau disent la même chose.
        $subjects = AdminJournalPresenter::resolveSubjects(
            $page->getCollection(),
            $subjectType !== null && $subjectId !== null ? [$subjectType, $subjectId] : null,
        );

        return Inertia::render('admin/journal/index', [
            'lines' => AdminCatalogPresenter::paginated(
                $page,
                fn (AdminAction $line): array => AdminJournalPresenter::line($line, $subjects),
            ),
            'filters' => $request->filters(),
            'subject' => $subjectType !== null && $subjectId !== null
                ? AdminJournalPresenter::subject($subjectType, $subjectId, $subjects)
                : null,
            'options' => [
                'actors' => $this->actorOptions(),
                'reserved_actors' => AdminJournalRequest::RESERVED_ACTORS,
                'actions' => array_column(AdminActionType::cases(), 'value'),
                'subject_types' => array_column(AdminActionSubject::cases(), 'value'),
                'subject_types_with_id' => array_values(array_map(
                    static fn (AdminActionSubject $subject): string => $subject->value,
                    array_filter(
                        AdminActionSubject::cases(),
                        static fn (AdminActionSubject $subject): bool => $subject->hasIdentifier(),
                    ),
                )),
            ],
        ]);
    }

    /**
     * La requête filtrée et triée. Chaque filtre porte sur une colonne typée.
     *
     * @return Builder<AdminAction>
     */
    private function filtered(AdminJournalRequest $request): Builder
    {
        $query = AdminAction::query();

        $actorId = $request->actorId();
        $reserved = $request->reservedActor();

        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        } elseif ($reserved !== null) {
            $query->whereNull('actor_id')->where('actor_name', $reserved);
        }

        $action = $request->actionType();

        if ($action !== null) {
            $query->where('action', $action->value);
        }

        $movieId = $request->movieId();
        $subjectType = $request->subjectType();

        if ($movieId !== null) {
            // L'historique complet d'un film : ses lignes et celles de ses
            // images, par `frame.movie_id` — jamais par le JSON `details`.
            $query->where(function (Builder $query) use ($movieId): void {
                $query
                    ->where(function (Builder $query) use ($movieId): void {
                        $query->where('subject_type', AdminActionSubject::Movie->value)
                            ->where('subject_id', $movieId);
                    })
                    ->orWhere(function (Builder $query) use ($movieId): void {
                        $query->where('subject_type', AdminActionSubject::Frame->value)
                            ->whereIn('subject_id', Frame::query()->select('id')->where('movie_id', $movieId));
                    });
            });
        } elseif ($subjectType !== null) {
            $query->where('subject_type', $subjectType->value);

            $subjectId = $request->subjectId();

            if ($subjectId !== null) {
                $query->where('subject_id', $subjectId);
            }
        }

        $from = $request->from();

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $to = $request->to();

        if ($to !== null) {
            $query->where('created_at', '<', $to->addDay());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Les auteurs qui ont signé au moins une ligne, chacun sous le DERNIER nom
     * réel qu'il a signé — l'instantané du journal, jamais `users.real_name`
     * courant, qu'une anonymisation vide. Deux requêtes, quelle que soit la
     * taille du journal : le dernier identifiant par auteur, par l'index
     * `admin_action_actor_idx`, puis ces lignes-là.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actorOptions(): array
    {
        $lastIds = AdminAction::query()
            ->whereNotNull('actor_id')
            ->toBase()
            ->groupBy('actor_id')
            ->selectRaw('max(id) as last_id')
            ->pluck('last_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        if ($lastIds === []) {
            return [];
        }

        $options = [];

        foreach (AdminAction::query()->whereIn('id', $lastIds)->get(['id', 'actor_id', 'actor_name']) as $line) {
            if ($line->actor_id !== null) {
                $options[] = ['value' => (string) $line->actor_id, 'label' => $line->actor_name];
            }
        }

        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']) ?: strcmp($a['value'], $b['value']));

        return $options;
    }
}
