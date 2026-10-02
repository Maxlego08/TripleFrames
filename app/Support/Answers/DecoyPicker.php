<?php

namespace App\Support\Answers;

use App\Enums\Locale;
use App\Enums\OriginalTitleForm;
use App\Enums\ThemeKind;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Round;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Draw\DrawContext;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolScope;
use App\Support\Draw\SeededPrf;
use App\Support\I18n\DisplayTitleResolver;
use App\ValueObjects\Answers\DecoyPick;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Tirage des trois leurres du QCM d'une manche (spec 70 § 10.2-10.4, contrat
 * C11, D21 du 23/09, D44 du 01/10) — les groupes d'affinité, puis l'échelle
 * R1-R4.
 *
 * **Candidats** : le vivier de {@see PoolScope::forDecoys()}, qui exclut
 * **toujours** la cible, son `movie_group` et les films des manches déjà
 * démarrées de la partie (`started_at <= $at`), et **jamais** ceux des manches
 * futures, programmées comprises : les exclure prouverait qu'un film vu en
 * leurre n'est pas dans la suite du tirage. Jusqu'à R4, chaque candidat est
 * `published` et `clear`, et la non-répétition du salon, quand elle est
 * active, est conservée à **tous** les rangs, groupes d'affinité compris ;
 * seuls les rangs de dernier recours R5-R6 (D53 du 02/10) la lèvent puis
 * puisent dans les films non publiés — amendé le 02/10.
 *
 * **Affinité d'abord** (D44 du 01/10, spec 70 § 10.3 bis) : avant chaque mode,
 * les leurres sont cherchés dans le **catalogue publié entier** (le périmètre
 * de R2/R4), parmi les films apparentés à la cible, groupe par groupe : (A)
 * même `collection_id` ; (B) membres actifs de chaque thème actif de la cible
 * de nature studio ou saga, ou manuel (`rule_value` nul), publié ou non ; (C)
 * même chose pour les thèmes de genre. Dans B et C, du thème le plus
 * spécifique (le moins de candidats éligibles, au moins trois) au plus large,
 * départage par `sort_order` puis `key`. **Un groupe n'est retenu que s'il
 * fournit à lui seul les trois leurres** : jamais « deux Toy Story et un
 * Pixar », où la cible se lirait dans la paire de la saga. Le premier groupe
 * qui suffit l'emporte ; sinon l'échelle ci-dessous, **inchangée** et reprise
 * de zéro. Ordre complet : affinité (normal), R1-R2, affinité (dégradé),
 * R3-R4, puis le dernier recours R5-R6 (normal, puis dégradé) — amendé le
 * 02/10.
 *
 * **Échelle** :
 *
 * | Rang | Ensemble | Profil | Contexte | Mode |
 * |---|---|---|---|---|
 * | R1 | vivier du salon | même `title_locale_mask`, version courante ; même {@see OriginalTitleForm} si le masque vaut 0 | `decoys(s)` | normal |
 * | R2 | catalogue publié (`withThemeIds([])`, `withFramesPerRound(null)`) moins R1 | idem | `decoys(s)` | normal |
 * | R3 | vivier du salon | même forme de titre original | `decoysOriginal(s)`, repris à zéro | dégradé |
 * | R4 | catalogue publié moins R3 | idem | `decoysOriginal(s)` | dégradé |
 * | R5 | catalogue publié **sans non-répétition** ({@see PoolScope::withoutNoRepeat()}), moins les leurres repris | profil normal | `decoysLastResort(s)`, leurres partiels de R1-R2 repris | normal |
 * | R6 | réserve non publiée ({@see PoolScope::asDecoyReserve()}) | idem | `decoysLastResort(s)` | normal |
 * | R5 | idem | forme de titre original | `decoysLastResortOriginal(s)`, leurres partiels de R3-R4 repris | dégradé |
 * | R6 | idem | idem | `decoysLastResortOriginal(s)` | dégradé |
 *
 * Le mode dégradé (`useOriginalTitle`, pour **tout** le salon) n'est pris que
 * si ni l'affinité ni R1-R2 ne fournissent trois leurres, si le masque de la
 * cible n'est pas à la version courante, ou si les quatre films n'atteignent
 * pas la même locale pour une même locale de rendu (masque périmé) : l'échec
 * tombe du côté visible, jamais du côté silencieux (spec 10 § 3.2).
 *
 * **Dernier recours** (D53 du 02/10, spec 70 § 10.3) : seulement quand R4
 * échoue, là où l'on rendait le cas terminal. R5 lève la non-répétition du
 * salon ; R6 puise dans les films `draft` ou `unpublished` (écartés compris),
 * `clear`, jamais `suspended` ni `withdrawn`. Les exclusions de
 * {@see PoolScope::forDecoys()} — cible, son `movie_group`, manches démarrées
 * — tiennent toujours. D'abord au profil normal (masque de la cible à la
 * version courante, mêmes contrôles qu'en R1-R2), puis au titre original. Ces
 * leurres sont **faibles** : un film déjà joué sous non-répétition, ou non
 * publié, ne peut pas être la cible, et un joueur qui connaît le catalogue ou
 * l'historique du salon peut les éliminer — d'où leur place après tous les
 * autres rangs, là seulement où il n'y aurait eu aucun QCM. Après R6, c'est
 * le cas terminal (NULL) — amendé le 02/10.
 *
 * **Reprise et lecture bornée** (revue du 02/10, spec 70 § 10.3) : R5 reprend
 * les leurres que le dernier rang du même profil avait retenus sans atteindre
 * trois (non joués et publiés, donc plus forts que tout leurre de dernier
 * recours) au lieu de les jeter. R5 et R6 sont lus en identifiants seuls
 * ({@see self::rankIds()}) et hydratés par lots de
 * {@see self::LAST_RESORT_BATCH} dans l'ordre de la permutation
 * ({@see self::walkLazily()}) : la réserve non publiée peut compter des
 * milliers de brouillons, et ce tirage tourne sous les verrous d'`OpenTier`.
 *
 * **Tirage dans un rang ou un groupe** : uniforme, sans remise — les candidats,
 * triés par `movie.id`, sont parcourus dans l'ordre de
 * `SeededPrf::forGame($game)->permutation($context, n)`, `n` = taille du rang
 * ou du groupe ; un groupe d'affinité se parcourt sur `decoysAffinity(s)`
 * (normal) ou `decoysAffinityOriginal(s)` (dégradé), chacun repris à zéro. Un
 * candidat est rejeté si son `movie_group` est déjà pris par un leurre
 * retenu, ou si l'une de ses chaînes rendues (par locale activée, dans le mode
 * courant) a, par {@see AnswerKeyNormalizer::normalize()}, la forme de la
 * cible ou d'un leurre retenu dans cette locale : les quatre chaînes sont deux à
 * deux distinctes dans chaque locale, et un clic n'est jamais ambigu.
 *
 * **Déterministe** pour une graine, une `sequence_index` et un état du
 * catalogue à `$at` : `$at` est l'instant THÉORIQUE de composition, jamais
 * l'heure d'exécution d'un job. Aucune écriture, aucun verrou : la composition
 * (`ComposeChoiceSets`) écrit, dans sa propre transaction.
 */
final readonly class DecoyPicker
{
    /**
     * Films hydratés (avec leurs titres) par lecture au dernier recours : un
     * lot suffit presque toujours, les rejets (`movie_group`, collision de
     * formes) étant rares. Taille de lecture, jamais une valeur de jeu : le
     * résultat ne dépend pas d'elle.
     */
    public const int LAST_RESORT_BATCH = 8;

    public function __construct(
        private PoolQuery $pool,
        private DisplayTitleResolver $titles,
    ) {}

    /**
     * Les trois leurres de la manche à l'instant théorique `$at`, ou NULL dans
     * le cas terminal (moins de trois leurres après R6, D53 du 02/10).
     *
     * @throws InvalidArgumentException La manche n'appartient pas à la partie.
     */
    public function pick(Round $round, Game $game, CarbonImmutable $at): ?DecoyPick
    {
        if ($round->game_id !== $game->id) {
            throw new InvalidArgumentException('DecoyPicker : la manche n’appartient pas à la partie passée.');
        }

        $target = Movie::query()->with(['projection', 'titles'])->findOrFail($round->movie_id);
        $form = OriginalTitleForm::of($target);
        $roomPool = PoolScope::forDecoys($round, $at);
        // Le périmètre de R2/R4, celui des groupes d'affinité : thèmes et N
        // levés, non-répétition et exclusions conservées (D21 du 23/09).
        $catalogue = $roomPool->withThemeIds([])->withFramesPerRound(null);
        $prf = SeededPrf::forGame($game);
        $sequenceIndex = $round->sequence_index;
        $themes = $this->affinityThemes($target);
        $projection = $target->projection;
        $mask = $projection !== null && $projection->hasCurrentTitleMask()
            ? $projection->title_locale_mask
            : null;
        // Sans titre dans aucune locale activée, les quatre chaînes sortent du
        // titre original : sa forme entre dans le profil.
        $profileForm = $mask === 0 ? $form : null;
        // Leurres partiels de R1-R2, repris par R5 au profil normal.
        $normalPartial = [];

        if ($mask !== null) {
            $related = $this->affinity(
                $target,
                $this->rank($catalogue, $mask, $profileForm),
                $themes,
                DrawContext::decoysAffinity($sequenceIndex),
                $prf,
                false,
            );

            if ($related !== null) {
                return new DecoyPick(self::ids($related), false);
            }

            $normal = $this->ladder(
                $roomPool,
                $mask,
                $profileForm,
                DrawContext::decoys($sequenceIndex),
                $prf,
                $target,
                false,
            );

            if (count($normal) === DecoyPick::COUNT && $this->reachSameLocales($target, $normal)) {
                return new DecoyPick(self::ids($normal), false);
            }

            // Trois leurres qui n'atteignent pas ensemble la même locale ne se
            // reprennent pas ; d'un tirage partiel, seuls ceux qui atteignent
            // les locales de la cible.
            if (count($normal) < DecoyPick::COUNT) {
                $normalPartial = array_values(array_filter(
                    $normal,
                    fn (Movie $movie): bool => $this->reachSameLocales($target, [$movie]),
                ));
            }
        }

        // Mode dégradé : tirage REPRIS À ZÉRO, sur ses propres contextes — rien
        // de ce que le mode normal avait retenu n'est conservé. L'affinité
        // d'abord, au titre original, puis R3-R4.
        $related = $this->affinity(
            $target,
            $this->rank($catalogue, null, $form),
            $themes,
            DrawContext::decoysAffinityOriginal($sequenceIndex),
            $prf,
            true,
        );

        if ($related !== null) {
            return new DecoyPick(self::ids($related), true);
        }

        $degraded = $this->ladder(
            $roomPool,
            null,
            $form,
            DrawContext::decoysOriginal($sequenceIndex),
            $prf,
            $target,
            true,
        );

        if (count($degraded) === DecoyPick::COUNT) {
            return new DecoyPick(self::ids($degraded), true);
        }

        // Dernier recours (D53 du 02/10) : seulement là où il n'y aurait eu
        // aucun QCM. R5 = catalogue publié sans non-répétition, R6 = réserve
        // non publiée ; exclusions de `forDecoys` conservées aux deux. R5
        // reprend les leurres partiels du même profil (revue du 02/10).
        $published = $catalogue->withoutNoRepeat();
        $reserve = $roomPool->asDecoyReserve();

        if ($mask !== null) {
            $normal = $this->lastResort(
                $published,
                $reserve,
                $mask,
                $profileForm,
                DrawContext::decoysLastResort($sequenceIndex),
                $prf,
                $target,
                $normalPartial,
                false,
            );

            if (count($normal) === DecoyPick::COUNT && $this->reachSameLocales($target, $normal)) {
                return new DecoyPick(self::ids($normal), false);
            }
        }

        $original = $this->lastResort(
            $published,
            $reserve,
            null,
            $form,
            DrawContext::decoysLastResortOriginal($sequenceIndex),
            $prf,
            $target,
            $degraded,
            true,
        );

        return count($original) === DecoyPick::COUNT
            ? new DecoyPick(self::ids($original), true)
            : null;
    }

    /**
     * R5 puis R6 sur un même contexte : le catalogue publié sans
     * non-répétition, moins les leurres repris, puis la réserve non publiée
     * (disjointe du premier par construction). Les leurres repris sont
     * conservés en tête, ceux de R5 en R6, mêmes règles de rejet
     * (`movie_group` pris, collision de forme normalisée par locale). Lecture
     * bornée : identifiants d'abord, films hydratés par lots.
     *
     * @param  int|null  $mask  Masque de locales exigé à la version courante ; NULL = aucun (mode dégradé).
     * @param  OriginalTitleForm|null  $form  Forme de titre original exigée ; NULL = aucune.
     * @param  list<Movie>  $carried  Leurres partiels du dernier rang de même profil (R1-R2 ou R3-R4).
     * @return list<Movie>
     */
    private function lastResort(
        PoolScope $published,
        PoolScope $reserve,
        ?int $mask,
        ?OriginalTitleForm $form,
        DrawContext $context,
        SeededPrf $prf,
        Movie $target,
        array $carried,
        bool $original,
    ): array {
        $retained = $this->walkLazily(
            $this->rankIds($published->excluding(self::ids($carried), []), $mask, $form),
            $context,
            $prf,
            $target,
            $carried,
            $original,
        );

        if (count($retained) === DecoyPick::COUNT) {
            return $retained;
        }

        return $this->walkLazily($this->rankIds($reserve, $mask, $form), $context, $prf, $target, $retained, $original);
    }

    /**
     * Le premier groupe d'affinité qui fournit **à lui seul** trois leurres
     * valides (D44 du 01/10) ; NULL si aucun ne suffit. Chaque groupe est
     * parcouru à zéro, sur le même contexte : aucun leurre d'un groupe écarté
     * n'est conservé.
     *
     * @param  list<Movie>  $candidates  Le catalogue publié au profil du mode, trié par `movie.id`.
     * @param  array{themes: array<int, AffinityTheme>, byMovie: array<int, list<int>>}  $index
     * @return list<Movie>|null
     */
    private function affinity(
        Movie $target,
        array $candidates,
        array $index,
        DrawContext $context,
        SeededPrf $prf,
        bool $original,
    ): ?array {
        // Mode normal : un candidat au masque périmé (il n'atteint pas les
        // locales de la cible) est écarté seul, avant la formation des
        // groupes : il n'entre dans l'effectif d'aucun et ne fait pas tomber
        // un groupe qui garde trois leurres valides.
        if (! $original) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (Movie $movie): bool => $this->reachSameLocales($target, [$movie]),
            ));
        }

        foreach (self::affinityGroups($target, $candidates, $index) as $group) {
            $decoys = $this->walk($group, $context, $prf, $target, [], $original);

            if (count($decoys) !== DecoyPick::COUNT) {
                continue;
            }

            // Mode normal : les quatre films atteignent la même locale (masque
            // périmé), sinon le groupe ne suffit pas.
            if (! $original && ! $this->reachSameLocales($target, $decoys)) {
                continue;
            }

            return $decoys;
        }

        return null;
    }

    /**
     * Les groupes d'affinité dans leur ordre de parcours, chacun sous-liste de
     * `$candidates` (donc trié par `movie.id`) et d'au moins trois films : (A)
     * même saga TMDB ; (B) thèmes studio, saga ou manuels ; (C) thèmes de
     * genre — dans B et C, du moins peuplé au plus peuplé, puis `sort_order`,
     * puis `key` octet par octet (comme {@see PoolQuery}).
     *
     * **Cohérence de groupe** (anti-fuite, spec 70 § 10.3 bis) : un membre
     * n'entre dans un groupe que si aucun groupe plus prioritaire ne lui
     * aurait suffi s'il avait été la cible. Sinon il serait éliminable : un
     * Toy Story parmi des Pixar n'est jamais la cible (la saga l'aurait
     * emporté), et trois propositions qui ont chacune un groupe plus
     * spécifique désigneraient la quatrième.
     *
     * @param  list<Movie>  $candidates
     * @param  array{themes: array<int, AffinityTheme>, byMovie: array<int, list<int>>}  $index
     * @return list<list<Movie>>
     */
    private static function affinityGroups(Movie $target, array $candidates, array $index): array
    {
        $themes = $index['themes'];
        $byMovie = $index['byMovie'];

        /** @var array<int, int> $sagaCount */
        $sagaCount = [];
        /** @var array<int, int> $themeCount */
        $themeCount = [];

        foreach ($candidates as $movie) {
            if ($movie->collection_id !== null) {
                $sagaCount[$movie->collection_id] = ($sagaCount[$movie->collection_id] ?? 0) + 1;
            }

            foreach ($byMovie[$movie->id] ?? [] as $themeId) {
                $themeCount[$themeId] = ($themeCount[$themeId] ?? 0) + 1;
            }
        }

        // Le premier groupe suffisant d'un candidat s'il était la cible : son
        // vivier serait les candidats, lui retiré, la cible ajoutée.
        $best = static function (Movie $movie) use ($target, $themes, $byMovie, $sagaCount, $themeCount): ?array {
            if ($movie->collection_id !== null) {
                $size = ($sagaCount[$movie->collection_id] ?? 1) - 1
                    + ($target->collection_id === $movie->collection_id ? 1 : 0);

                if ($size >= DecoyPick::COUNT) {
                    return self::sagaRank();
                }
            }

            $found = null;

            foreach ($byMovie[$movie->id] ?? [] as $themeId) {
                $theme = $themes[$themeId];
                $size = ($themeCount[$themeId] ?? 1) - 1 + (isset($theme->members[$target->id]) ? 1 : 0);

                if ($size < DecoyPick::COUNT) {
                    continue;
                }

                $rank = self::themeRank($theme, $size);

                if ($found === null || self::compareRanks($rank, $found) < 0) {
                    $found = $rank;
                }
            }

            return $found;
        };

        /** @var list<array{rank: array{0: int, 1: int, 2: int, 3: string}, members: list<Movie>}> $ranked */
        $ranked = [];

        if ($target->collection_id !== null) {
            $saga = array_values(array_filter(
                $candidates,
                static fn (Movie $movie): bool => $movie->collection_id === $target->collection_id,
            ));

            if (count($saga) >= DecoyPick::COUNT) {
                $ranked[] = ['rank' => self::sagaRank(), 'members' => $saga];
            }
        }

        foreach ($byMovie[$target->id] ?? [] as $themeId) {
            $theme = $themes[$themeId];
            $members = array_values(array_filter(
                $candidates,
                static fn (Movie $movie): bool => isset($theme->members[$movie->id]),
            ));

            if (count($members) >= DecoyPick::COUNT) {
                $ranked[] = ['rank' => self::themeRank($theme, count($members)), 'members' => $members];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => self::compareRanks($a['rank'], $b['rank']));

        $groups = [];

        foreach ($ranked as $entry) {
            $rank = $entry['rank'];
            $coherent = array_values(array_filter(
                $entry['members'],
                static function (Movie $movie) use ($best, $rank): bool {
                    $own = $best($movie);

                    return $own === null || self::compareRanks($own, $rank) >= 0;
                },
            ));

            if (count($coherent) >= DecoyPick::COUNT) {
                $groups[] = $coherent;
            }
        }

        return $groups;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private static function sagaRank(): array
    {
        return [0, 0, 0, ''];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private static function themeRank(AffinityTheme $theme, int $size): array
    {
        return [$theme->tier, $size, $theme->sortOrder, $theme->key];
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: string}  $a
     * @param  array{0: int, 1: int, 2: int, 3: string}  $b
     */
    private static function compareRanks(array $a, array $b): int
    {
        return ($a[0] <=> $b[0])
            ?: ($a[1] <=> $b[1])
            ?: ($a[2] <=> $b[2])
            ?: strcmp($a[3], $b[3]);
    }

    /**
     * Les thèmes qui forment un groupe d'affinité, publiés ou non, avec leurs
     * membres actifs — **deux requêtes au plus**, quelle que soit la taille
     * du catalogue : les thèmes de la cible (aucun thème apparentant → pas de
     * seconde lecture), puis toutes les appartenances actives aux thèmes
     * apparentants, que la cohérence de groupe exige. Palier B : studio, saga
     * ou manuel (`rule_value` nul) ; palier C : genre. Décennie, langue et
     * difficulté n'apparentent pas deux films.
     *
     * @return array{themes: array<int, AffinityTheme>, byMovie: array<int, list<int>>}
     */
    private function affinityThemes(Movie $target): array
    {
        /** @var array{themes: array<int, AffinityTheme>, byMovie: array<int, list<int>>} $index */
        $index = ['themes' => [], 'byMovie' => []];

        $targetThemes = DB::table('movie_theme')
            ->join('theme', 'theme.id', '=', 'movie_theme.theme_id')
            ->where('movie_theme.movie_id', $target->id)
            ->where('movie_theme.is_active', true)
            ->get(['theme.theme_kind', 'theme.rule_value'])
            ->all();

        $related = array_filter(
            $targetThemes,
            static fn (stdClass $row): bool => self::tierOf($row) !== null,
        );

        if ($related === []) {
            return $index;
        }

        $rows = DB::table('movie_theme')
            ->join('theme', 'theme.id', '=', 'movie_theme.theme_id')
            ->where('movie_theme.is_active', true)
            ->where(static fn (Builder $query): Builder => $query->whereNull('theme.rule_value')
                ->orWhereIn('theme.theme_kind', [ThemeKind::Studio->value, ThemeKind::Saga->value, ThemeKind::Genre->value]))
            ->orderBy('movie_theme.movie_id')
            ->orderBy('theme.id')
            ->get(['theme.id', 'theme.key', 'theme.theme_kind', 'theme.rule_value', 'theme.sort_order', 'movie_theme.movie_id'])
            ->all();

        /** @var array<int, array{tier: int, key: string, sort: int}> $meta */
        $meta = [];
        /** @var array<int, array<int, true>> $members */
        $members = [];

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $tier = self::tierOf($row);

            if ($tier === null) {
                continue;
            }

            $themeId = (int) $row->id;
            $movieId = (int) $row->movie_id;
            $meta[$themeId] = ['tier' => $tier, 'key' => (string) $row->key, 'sort' => (int) $row->sort_order];
            $members[$themeId][$movieId] = true;
            $index['byMovie'][$movieId][] = $themeId;
        }

        foreach ($meta as $themeId => $theme) {
            $index['themes'][$themeId] = new AffinityTheme($theme['tier'], $theme['key'], $theme['sort'], $members[$themeId] ?? []);
        }

        return $index;
    }

    /** Palier d'un thème : 1 (B : studio, saga ou manuel), 2 (C : genre), NULL s'il n'apparente pas. */
    private static function tierOf(stdClass $row): ?int
    {
        $kind = ThemeKind::tryFrom((string) $row->theme_kind);

        return match (true) {
            $row->rule_value === null, $kind === ThemeKind::Studio, $kind === ThemeKind::Saga => 1,
            $kind === ThemeKind::Genre => 2,
            default => null,
        };
    }

    /**
     * Deux rangs sur un même contexte : le vivier du salon, puis le catalogue
     * publié moins les candidats du premier rang, tous examinés quand on y
     * arrive. Les leurres du premier rang sont conservés.
     *
     * @param  int|null  $mask  Masque de locales exigé à la version courante ; NULL = aucun (mode dégradé).
     * @param  OriginalTitleForm|null  $form  Forme de titre original exigée ; NULL = aucune.
     * @return list<Movie>
     */
    private function ladder(
        PoolScope $roomPool,
        ?int $mask,
        ?OriginalTitleForm $form,
        DrawContext $context,
        SeededPrf $prf,
        Movie $target,
        bool $original,
    ): array {
        $roomRank = $this->rank($roomPool, $mask, $form);
        $retained = $this->walk($roomRank, $context, $prf, $target, [], $original);

        if (count($retained) === DecoyPick::COUNT) {
            return $retained;
        }

        // Catalogue publié : thèmes et N levés, non-répétition et exclusions
        // conservées (D21 du 23/09).
        $catalogue = $roomPool->withThemeIds([])
            ->withFramesPerRound(null)
            ->excluding(self::ids($roomRank), []);

        return $this->walk($this->rank($catalogue, $mask, $form), $context, $prf, $target, $retained, $original);
    }

    /**
     * Les candidats d'un rang, triés par `movie.id` croissant : le vivier du
     * périmètre, filtré par l'égalité indexée `movie_projection_qcm_idx` quand
     * un masque est exigé, puis par la forme du titre original (PCRE, en PHP :
     * aucune colonne ne la porte). Les titres sont chargés d'avance, en une
     * requête : le rendu des formes ne lit rien candidat par candidat (D44 du
     * 01/10, budget de requêtes constant).
     *
     * @return list<Movie>
     */
    private function rank(PoolScope $scope, ?int $mask, ?OriginalTitleForm $form): array
    {
        $query = $this->pool->movies($scope)->with('titles');

        if ($mask !== null) {
            $query->where('movie_projection.title_mask_version', Locale::MASK_VERSION)
                ->where('movie_projection.title_locale_mask', $mask);
        }

        $movies = $query->get()->all();

        if ($form !== null) {
            $movies = array_filter(
                $movies,
                static fn (Movie $movie): bool => OriginalTitleForm::of($movie) === $form,
            );
        }

        return array_values($movies);
    }

    /**
     * Les identifiants d'un rang de dernier recours, triés par `movie.id`
     * croissant — la même liste que {@see self::rank()}, sans hydrater un seul
     * modèle : identifiant et colonnes du titre original seulement, forme
     * classée en PHP par {@see OriginalTitleForm::fromColumns()}.
     *
     * @return list<int>
     */
    private function rankIds(PoolScope $scope, ?int $mask, ?OriginalTitleForm $form): array
    {
        $query = $this->pool->movies($scope);

        if ($mask !== null) {
            $query->where('movie_projection.title_mask_version', Locale::MASK_VERSION)
                ->where('movie_projection.title_locale_mask', $mask);
        }

        $rows = $query->toBase()
            ->select(['movie.id', 'movie.title_original', 'movie.title_original_latin'])
            ->get()
            ->all();

        $ids = [];

        foreach ($rows as $row) {
            /** @var stdClass $row */
            if ($form !== null) {
                $latin = $row->title_original_latin;
                $rowForm = OriginalTitleForm::fromColumns(
                    (string) $row->title_original,
                    $latin === null ? null : (string) $latin,
                );

                if ($rowForm !== $form) {
                    continue;
                }
            }

            $ids[] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * Parcours d'un rang lu en identifiants ({@see self::rankIds()}) dans
     * l'ordre de la permutation du contexte : les films ne sont hydratés, avec
     * leurs titres, que par lots de {@see self::LAST_RESORT_BATCH}, jusqu'au
     * troisième leurre. Même résultat que {@see self::walk()} sur le rang
     * entier ; un film disparu entre les deux lectures est sauté.
     *
     * @param  list<int>  $ids
     * @param  list<Movie>  $retained  Leurres déjà retenus.
     * @return list<Movie>
     */
    private function walkLazily(
        array $ids,
        DrawContext $context,
        SeededPrf $prf,
        Movie $target,
        array $retained,
        bool $original,
    ): array {
        if (count($retained) >= DecoyPick::COUNT || $ids === []) {
            return $retained;
        }

        $order = $prf->permutation($context, count($ids));

        foreach (array_chunk($order, self::LAST_RESORT_BATCH) as $chunk) {
            $batchIds = array_map(static fn (int $index): int => $ids[$index], $chunk);
            $loaded = Movie::query()->with('titles')->whereKey($batchIds)->get()->keyBy('id');
            $batch = [];

            foreach ($batchIds as $id) {
                $movie = $loaded->get($id);

                if ($movie instanceof Movie) {
                    $batch[] = $movie;
                }
            }

            $retained = $this->walkOrdered($batch, $target, $retained, $original);

            if (count($retained) === DecoyPick::COUNT) {
                break;
            }
        }

        return $retained;
    }

    /**
     * Parcours d'un rang dans l'ordre de la permutation du contexte, jusqu'au
     * troisième leurre retenu.
     *
     * @param  list<Movie>  $rank
     * @param  list<Movie>  $retained  Leurres déjà retenus au rang précédent.
     * @return list<Movie>
     */
    private function walk(
        array $rank,
        DrawContext $context,
        SeededPrf $prf,
        Movie $target,
        array $retained,
        bool $original,
    ): array {
        if (count($retained) >= DecoyPick::COUNT || $rank === []) {
            return $retained;
        }

        return $this->walkOrdered(
            array_map(static fn (int $index): Movie => $rank[$index], $prf->permutation($context, count($rank))),
            $target,
            $retained,
            $original,
        );
    }

    /**
     * Examen de candidats déjà rangés dans l'ordre de la permutation, jusqu'au
     * troisième leurre retenu (rejets du § 10.4).
     *
     * @param  list<Movie>  $candidates
     * @param  list<Movie>  $retained
     * @return list<Movie>
     */
    private function walkOrdered(array $candidates, Movie $target, array $retained, bool $original): array
    {
        if (count($retained) >= DecoyPick::COUNT) {
            return $retained;
        }

        $forms = [$this->forms($target, $original)];
        $groups = [];

        foreach ($retained as $decoy) {
            $forms[] = $this->forms($decoy, $original);

            if ($decoy->group_id !== null) {
                $groups[$decoy->group_id] = true;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate->group_id !== null && isset($groups[$candidate->group_id])) {
                continue;
            }

            $candidateForms = $this->forms($candidate, $original);

            if (self::collides($candidateForms, $forms)) {
                continue;
            }

            $retained[] = $candidate;
            $forms[] = $candidateForms;

            if ($candidate->group_id !== null) {
                $groups[$candidate->group_id] = true;
            }

            if (count($retained) === DecoyPick::COUNT) {
                break;
            }
        }

        return $retained;
    }

    /**
     * Les formes normalisées des chaînes rendues d'un film, par locale activée,
     * dans le mode courant : la chaîne de repli en mode normal, le titre
     * original pour toutes les locales en mode dégradé.
     *
     * @return array<string, string>
     */
    private function forms(Movie $movie, bool $original): array
    {
        $forms = [];

        foreach (Locale::cases() as $locale) {
            $text = $original
                ? $this->titles->original($movie)
                : $this->titles->resolve($movie, $locale)->text;

            $forms[$locale->value] = AnswerKeyNormalizer::normalize($text);
        }

        return $forms;
    }

    /**
     * Vrai si, dans une locale au moins, la forme du candidat est déjà prise.
     *
     * @param  array<string, string>  $candidate
     * @param  list<array<string, string>>  $taken
     */
    private static function collides(array $candidate, array $taken): bool
    {
        foreach ($taken as $forms) {
            foreach ($candidate as $locale => $form) {
                if (($forms[$locale] ?? null) === $form) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Vrai si, pour chaque locale de rendu, les quatre films atteignent la même
     * locale de la chaîne de repli — condition de l'homogénéité linguistique
     * (05 § QCM), que le masque garantit tant qu'il n'est pas périmé.
     *
     * @param  list<Movie>  $decoys
     */
    private function reachSameLocales(Movie $target, array $decoys): bool
    {
        foreach (Locale::cases() as $locale) {
            $reached = $this->titles->resolve($target, $locale)->locale;

            foreach ($decoys as $decoy) {
                if ($this->titles->resolve($decoy, $locale)->locale !== $reached) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<Movie>  $movies
     * @return list<int>
     */
    private static function ids(array $movies): array
    {
        return array_map(static fn (Movie $movie): int => $movie->id, $movies);
    }
}
