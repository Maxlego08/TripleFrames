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
 * leurre n'est pas dans la suite du tirage. Chaque candidat est `published` et
 * `clear` ; la non-répétition du salon, quand elle est active, est conservée à
 * **tous** les rangs, groupes d'affinité compris.
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
 * R3-R4.
 *
 * **Échelle** :
 *
 * | Rang | Ensemble | Profil | Contexte | Mode |
 * |---|---|---|---|---|
 * | R1 | vivier du salon | même `title_locale_mask`, version courante ; même {@see OriginalTitleForm} si le masque vaut 0 | `decoys(s)` | normal |
 * | R2 | catalogue publié (`withThemeIds([])`, `withFramesPerRound(null)`) moins R1 | idem | `decoys(s)` | normal |
 * | R3 | vivier du salon | même forme de titre original | `decoysOriginal(s)`, repris à zéro | dégradé |
 * | R4 | catalogue publié moins R3 | idem | `decoysOriginal(s)` | dégradé |
 *
 * Le mode dégradé (`useOriginalTitle`, pour **tout** le salon) n'est pris que
 * si ni l'affinité ni R1-R2 ne fournissent trois leurres, si le masque de la
 * cible n'est pas à la version courante, ou si les quatre films n'atteignent
 * pas la même locale pour une même locale de rendu (masque périmé) : l'échec
 * tombe du côté visible, jamais du côté silencieux (spec 10 § 3.2). Aucun rang
 * ne lève la non-répétition : après R4, c'est le cas terminal (NULL).
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
    public function __construct(
        private PoolQuery $pool,
        private DisplayTitleResolver $titles,
    ) {}

    /**
     * Les trois leurres de la manche à l'instant théorique `$at`, ou NULL dans
     * le cas terminal (moins de trois leurres après R4).
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

        if ($projection !== null && $projection->hasCurrentTitleMask()) {
            $mask = $projection->title_locale_mask;
            // Sans titre dans aucune locale activée, les quatre chaînes sortent
            // du titre original : sa forme entre dans le profil.
            $profileForm = $mask === 0 ? $form : null;

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

            $normal = $this->normalMode($target, $mask, $profileForm, $roomPool, $prf, $sequenceIndex);

            if ($normal !== null) {
                return new DecoyPick(self::ids($normal), false);
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

        return count($degraded) === DecoyPick::COUNT
            ? new DecoyPick(self::ids($degraded), true)
            : null;
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
     * R1 puis R2, au profil de titre de la cible (masque à la version courante,
     * vérifié par l'appelant) ; NULL quand le mode normal n'est pas tenable
     * (moins de trois leurres, ou quatre films qui n'atteignent pas la même
     * locale).
     *
     * @return list<Movie>|null
     */
    private function normalMode(
        Movie $target,
        int $mask,
        ?OriginalTitleForm $form,
        PoolScope $roomPool,
        SeededPrf $prf,
        int $sequenceIndex,
    ): ?array {
        $decoys = $this->ladder(
            $roomPool,
            $mask,
            $form,
            DrawContext::decoys($sequenceIndex),
            $prf,
            $target,
            false,
        );

        if (count($decoys) !== DecoyPick::COUNT || ! $this->reachSameLocales($target, $decoys)) {
            return null;
        }

        return $decoys;
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

        $forms = [$this->forms($target, $original)];
        $groups = [];

        foreach ($retained as $decoy) {
            $forms[] = $this->forms($decoy, $original);

            if ($decoy->group_id !== null) {
                $groups[$decoy->group_id] = true;
            }
        }

        foreach ($prf->permutation($context, count($rank)) as $index) {
            $candidate = $rank[$index];

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
