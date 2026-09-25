<?php

namespace App\Support\Curation;

use App\Actions\Curation\RecordCurationHeartbeat;
use App\Actions\Curation\RecropFrame;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ImportSource;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use Carbon\CarbonImmutable;

/**
 * Le tableau du débit de curation — spec 20 § 10.2 et § 10.3 (décision 10,
 * D10 et D11 du 23/09). **Lecture seule, agrégat seulement** : aucun curateur
 * n'y est nommé ni compté à part — mesurer des bénévoles nommés serait un
 * traitement de surveillance, et l'agrégat suffit à la décision. Aucune
 * colonne d'auteur n'est même lue. Films de démonstration exclus.
 *
 * **Terminaison d'un film** = sa PREMIÈRE ligne `movie.published` ou
 * `movie.unpublished` du journal `admin_action`, en ajout seul, datée par son
 * `created_at` et lue par `admin_action_subject_idx (subject_type, subject_id,
 * created_at)` — jamais `availability_changed_at`, qui bouge à chaque
 * changement d'état et recomposerait la fenêtre du pilote après coup. Au sens
 * de la mesure, un film est « publié » si cette première ligne est
 * `movie.published`, « écarté » si elle est `movie.unpublished` : un film
 * écarté puis curé et publié plus tard reste un écarté — un échec du pilote.
 * Les films terminés sont pris dans l'ordre de leur terminaison, l'ordre du
 * journal départageant deux terminaisons de la même seconde.
 *
 * **Mesures**, par population (tous les films terminés ; le lot pilote),
 * ventilées par voie (`is_import_exception` faux = `discover`, vrai =
 * `exception`) : nombre de films, temps actif total, médiane et p90 du temps
 * actif par film PUBLIÉ, médiane et p90 de `crop_seconds` par image créée
 * au plus tard à la terminaison de son film — la passe 2 n'entre pas dans la
 * mesure de la passe 1 —, films écartés et leurs motifs. Médiane et p90 sont
 * calculés en PHP, au rang le plus proche ({@see self::nearestRank()}) :
 * MySQL n'a ni `MEDIAN` ni percentile portable, et aucune requête ici n'a de
 * `GROUP BY`.
 *
 * **Fenêtre du pilote, par voie** (§ 10.3, B9) : les `composition.<voie>`
 * premiers films terminés de la voie, comptés à partir du rang
 * `first_rank.<voie>`. Aucune colonne ne marque le pilote. La fenêtre n'est
 * pleine, et le verdict n'est rendu, que lorsque LES DEUX sous-fenêtres le
 * sont. Une fois pleine, le verdict ne dépend d'aucune écriture postérieure :
 * la terminaison est lue au journal, le temps actif est figé à la terminaison
 * et `crop_seconds` au premier recadrage ({@see RecordCurationHeartbeat},
 * {@see RecropFrame}), et seules comptent les images créées avant la
 * terminaison.
 *
 * @phpstan-type Terminated array{
 *     movie_id: int,
 *     title_original: string,
 *     entry: string,
 *     active_seconds: int,
 *     published: bool,
 *     terminated_at: CarbonImmutable,
 *     termination_id: int,
 *     reason: string|null,
 *     crop_seconds: list<int>,
 * }
 * @phpstan-type Measures array{
 *     films: int,
 *     published: int,
 *     set_aside: int,
 *     active_seconds_total: int,
 *     active_seconds_median: int|null,
 *     active_seconds_p90: int|null,
 *     crop_frames: int,
 *     crop_seconds_median: int|null,
 *     crop_seconds_p90: int|null,
 * }
 */
final readonly class ThroughputReport
{
    /** Clé des mesures des deux voies réunies. */
    public const string TOTAL = 'total';

    public const int MEDIAN_PERCENT = 50;

    public const int P90_PERCENT = 90;

    /** Les deux actions qui terminent un film, lues au journal. */
    public const array TERMINATIONS = [
        AdminActionType::MoviePublished,
        AdminActionType::MovieUnpublished,
    ];

    /** Taille des lots d'identifiants des lectures `whereIn`. */
    private const int CHUNK = 500;

    /**
     * @param  list<Terminated>  $terminated  les films terminés, dans l'ordre de leur terminaison
     */
    private function __construct(
        private array $terminated,
        private PilotSettings $settings,
    ) {}

    /**
     * Mesure le débit sur la base courante, aux réglages en vigueur ou donnés.
     */
    public static function measure(?PilotSettings $settings = null): self
    {
        return new self(self::terminated(), $settings ?? PilotSettings::current());
    }

    /**
     * La valeur de rang `⌈p × n⌉` des valeurs rangées par ordre croissant — le
     * rang le plus proche, sans interpolation : la médiane de 10, 20, 30, 40
     * vaut 20, et leur p90 40. `null` sans valeur.
     *
     * @param  list<int>  $values
     */
    public static function nearestRank(array $values, int $percent): ?int
    {
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        sort($values);

        $rank = intdiv($percent * $count + 99, 100);

        return $values[min(max(1, $rank), $count) - 1];
    }

    /**
     * Les films de la fenêtre du pilote, par voie, dans l'ordre de leur
     * terminaison — une sous-fenêtre incomplète tant que sa voie n'a pas son
     * quota.
     *
     * @return array<string, list<Terminated>>
     */
    public function pilotWindow(): array
    {
        $window = [];

        foreach (CurationQueue::ENTRIES as $entry) {
            $films = array_values(array_filter(
                $this->terminated,
                static fn (array $film): bool => $film['entry'] === $entry,
            ));

            $window[$entry] = array_slice(
                $films,
                max(1, $this->settings->firstRank[$entry]) - 1,
                max(0, $this->settings->composition[$entry]),
            );
        }

        return $window;
    }

    /**
     * L'avancement de chaque sous-fenêtre : films terminés entrés dans la
     * fenêtre, sur le quota de la voie, et le rang de départ.
     *
     * @return array<string, array{terminated: int, quota: int, first_rank: int, full: bool}>
     */
    public function pilotProgress(): array
    {
        $progress = [];

        foreach ($this->pilotWindow() as $entry => $films) {
            $quota = max(0, $this->settings->composition[$entry]);

            $progress[$entry] = [
                'terminated' => count($films),
                'quota' => $quota,
                'first_rank' => max(1, $this->settings->firstRank[$entry]),
                'full' => count($films) >= $quota,
            ];
        }

        return $progress;
    }

    /** La fenêtre est-elle pleine dans ses DEUX voies ? */
    public function pilotFull(): bool
    {
        foreach ($this->pilotProgress() as $progress) {
            if (! $progress['full']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le verdict du pilote, ou `null` tant que l'une des deux voies n'a pas son
     * quota de films terminés.
     */
    public function verdict(): ?PilotVerdict
    {
        if (! $this->pilotFull()) {
            return null;
        }

        $films = $this->pilotFilms();
        $filledAt = null;

        foreach ($films as $film) {
            if ($filledAt === null || $film['terminated_at']->greaterThan($filledAt)) {
                $filledAt = $film['terminated_at'];
            }
        }

        return PilotVerdict::render(
            filledAt: $filledAt ?? CarbonImmutable::createFromTimestamp(0),
            films: count($films),
            failures: count(array_filter($films, static fn (array $film): bool => ! $film['published'])),
            totalActiveSeconds: array_sum(array_column($films, 'active_seconds')),
            p90Seconds: self::nearestRank(self::publishedActiveSeconds($films), self::P90_PERCENT),
            settings: $this->settings,
        );
    }

    /**
     * Les mesures d'une population, ventilées par voie, puis les deux voies
     * réunies.
     *
     * @param  list<Terminated>|null  $films  la population ; tous les films terminés par défaut
     * @return array<string, Measures>
     */
    public function measures(?array $films = null): array
    {
        $films ??= $this->terminated;
        $measures = [];

        foreach (CurationQueue::ENTRIES as $entry) {
            $measures[$entry] = self::measuresOf(array_values(array_filter(
                $films,
                static fn (array $film): bool => $film['entry'] === $entry,
            )));
        }

        $measures[self::TOTAL] = self::measuresOf($films);

        return $measures;
    }

    /**
     * Ce que reçoit l'écran : des nombres, des instants ISO-8601 et les motifs
     * libres des films écartés — aucune chaîne formatée (règle 4), aucun nom
     * de curateur.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $pilot = $this->pilotFilms();
        $verdict = $this->verdict();

        return [
            'thresholds' => [
                'composition' => $this->settings->composition,
                'size' => $this->settings->size,
                'disqualify_seconds' => $this->settings->disqualifySeconds(),
                'j1_target_films' => $this->settings->j1TargetFilms,
                'remaining_after_pilot' => $this->settings->remainingAfterPilot(),
                'reserve_seconds' => $this->settings->reserveSeconds(),
                'volume_cap' => $this->settings->volumeCap,
                'weekly_curation_hours' => $this->settings->weeklyCurationHours,
                'horizon_weeks' => $this->settings->horizonWeeks,
            ],
            'all' => [
                'measures' => $this->measures(),
                'set_aside' => self::setAside($this->terminated),
                // Projection indicative, au p90 de TOUS les films terminés :
                // elle bouge avec la curation, là où le verdict est figé.
                'projection' => PilotVerdict::projection(
                    self::nearestRank(self::publishedActiveSeconds($this->terminated), self::P90_PERCENT),
                    $this->settings,
                ),
            ],
            'pilot' => [
                'progress' => $this->pilotProgress(),
                'full' => $verdict !== null,
                'measures' => $this->measures($pilot),
                'set_aside' => self::setAside($pilot),
            ],
            'verdict' => $verdict?->toArray(),
        ];
    }

    /**
     * Les films de la fenêtre du pilote, deux voies réunies, dans l'ordre de
     * leur terminaison.
     *
     * @return list<Terminated>
     */
    private function pilotFilms(): array
    {
        $films = array_merge(...array_values($this->pilotWindow()));

        usort($films, self::terminationOrder(...));

        return $films;
    }

    /**
     * Les films réels terminés, dans l'ordre de leur terminaison, chacun avec
     * sa ligne de terminaison et les `crop_seconds` de ses images créées au
     * plus tard à cette terminaison.
     *
     * Trois lectures, aucune `GROUP BY` :
     *
     * 1. les films réels ayant au moins une ligne de terminaison, chacun avec
     *    l'identifiant de sa PREMIÈRE — une sous-requête corrélée ordonnée par
     *    `created_at` puis `id`, servie par `admin_action_subject_idx` ;
     * 2. ces lignes, par identifiant : action, instant et motif, jamais leur
     *    auteur ;
     * 3. les images de ces films qui portent un temps de recadrage.
     *
     * @return list<Terminated>
     */
    private static function terminated(): array
    {
        $actions = array_map(
            static fn (AdminActionType $action): string => $action->value,
            self::TERMINATIONS,
        );

        $first = AdminAction::query()
            ->select('admin_action.id')
            ->where('admin_action.subject_type', AdminActionSubject::Movie->value)
            ->whereColumn('admin_action.subject_id', 'movie.id')
            ->whereIn('admin_action.action', $actions)
            ->orderBy('admin_action.created_at')
            ->orderBy('admin_action.id')
            ->limit(1);

        $terminatedIds = AdminAction::query()
            ->select('admin_action.subject_id')
            ->where('admin_action.subject_type', AdminActionSubject::Movie->value)
            ->whereIn('admin_action.action', $actions);

        $rows = Movie::query()
            ->toBase()
            ->select(['movie.id', 'movie.title_original', 'movie.is_import_exception', 'movie.curation_active_seconds'])
            ->selectSub($first, 'termination_id')
            ->where('movie.import_source', '<>', ImportSource::Demo->value)
            ->whereIn('movie.id', $terminatedIds)
            ->get();

        $movies = [];

        foreach ($rows as $row) {
            if ($row->termination_id !== null) {
                $movies[(int) $row->id] = $row;
            }
        }

        $terminations = self::terminationsOf(array_map(
            static fn (object $row): int => (int) $row->termination_id,
            array_values($movies),
        ));
        $crops = self::cropsOf(array_keys($movies));

        $films = [];

        foreach ($movies as $movieId => $row) {
            $termination = $terminations[(int) $row->termination_id] ?? null;

            if ($termination === null || $termination->created_at === null) {
                continue;
            }

            $terminatedAt = $termination->created_at;
            $cropSeconds = [];

            foreach ($crops[$movieId] ?? [] as [$createdAt, $seconds]) {
                if ($createdAt->lessThanOrEqualTo($terminatedAt)) {
                    $cropSeconds[] = $seconds;
                }
            }

            $films[] = [
                'movie_id' => $movieId,
                'title_original' => (string) $row->title_original,
                'entry' => (bool) $row->is_import_exception ? CurationQueue::ENTRY_EXCEPTION : CurationQueue::ENTRY_DISCOVER,
                'active_seconds' => (int) $row->curation_active_seconds,
                'published' => $termination->action === AdminActionType::MoviePublished,
                'terminated_at' => $terminatedAt,
                'termination_id' => $termination->id,
                'reason' => $termination->reason,
                'crop_seconds' => $cropSeconds,
            ];
        }

        usort($films, self::terminationOrder(...));

        return $films;
    }

    /**
     * Les lignes de terminaison, par identifiant — action, instant, motif.
     *
     * @param  list<int>  $ids
     * @return array<int, AdminAction>
     */
    private static function terminationsOf(array $ids): array
    {
        $terminations = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = AdminAction::query()
                ->whereIn('id', $chunk)
                ->get(['id', 'action', 'created_at', 'reason']);

            foreach ($rows as $row) {
                $terminations[$row->id] = $row;
            }
        }

        return $terminations;
    }

    /**
     * Les temps de recadrage des images de ces films, avec leur instant de
     * création, par film.
     *
     * @param  list<int>  $movieIds
     * @return array<int, list<array{CarbonImmutable, int}>>
     */
    private static function cropsOf(array $movieIds): array
    {
        $crops = [];

        foreach (array_chunk($movieIds, self::CHUNK) as $chunk) {
            $frames = Frame::query()
                ->whereIn('movie_id', $chunk)
                ->whereNotNull('crop_seconds')
                ->orderBy('id')
                ->get(['id', 'movie_id', 'crop_seconds', 'created_at']);

            foreach ($frames as $frame) {
                if ($frame->created_at !== null && $frame->crop_seconds !== null) {
                    $crops[$frame->movie_id][] = [$frame->created_at, $frame->crop_seconds];
                }
            }
        }

        return $crops;
    }

    /**
     * Les mesures d'un ensemble de films.
     *
     * @param  list<Terminated>  $films
     * @return Measures
     */
    private static function measuresOf(array $films): array
    {
        $active = self::publishedActiveSeconds($films);
        $crops = array_merge(...array_column($films, 'crop_seconds'));

        return [
            'films' => count($films),
            'published' => count($active),
            'set_aside' => count($films) - count($active),
            'active_seconds_total' => array_sum(array_column($films, 'active_seconds')),
            'active_seconds_median' => self::nearestRank($active, self::MEDIAN_PERCENT),
            'active_seconds_p90' => self::nearestRank($active, self::P90_PERCENT),
            'crop_frames' => count($crops),
            'crop_seconds_median' => self::nearestRank($crops, self::MEDIAN_PERCENT),
            'crop_seconds_p90' => self::nearestRank($crops, self::P90_PERCENT),
        ];
    }

    /**
     * Le temps actif des seuls films publiés — au sens de la mesure.
     *
     * @param  list<Terminated>  $films
     * @return list<int>
     */
    private static function publishedActiveSeconds(array $films): array
    {
        $seconds = [];

        foreach ($films as $film) {
            if ($film['published']) {
                $seconds[] = $film['active_seconds'];
            }
        }

        return $seconds;
    }

    /**
     * Les films écartés et leurs motifs, dans l'ordre de leur terminaison.
     *
     * @param  list<Terminated>  $films
     * @return list<array{movie_id: int, title_original: string, entry: string, reason: string|null, terminated_at: string}>
     */
    private static function setAside(array $films): array
    {
        $rows = [];

        foreach ($films as $film) {
            if (! $film['published']) {
                $rows[] = [
                    'movie_id' => $film['movie_id'],
                    'title_original' => $film['title_original'],
                    'entry' => $film['entry'],
                    'reason' => $film['reason'],
                    'terminated_at' => $film['terminated_at']->toIso8601String(),
                ];
            }
        }

        return $rows;
    }

    /**
     * L'ordre de terminaison : l'instant, puis l'ordre du journal.
     *
     * @param  Terminated  $a
     * @param  Terminated  $b
     */
    private static function terminationOrder(array $a, array $b): int
    {
        return [$a['terminated_at']->getTimestamp(), $a['termination_id']]
            <=> [$b['terminated_at']->getTimestamp(), $b['termination_id']];
    }
}
