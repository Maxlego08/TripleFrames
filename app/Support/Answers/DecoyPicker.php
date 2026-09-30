<?php

namespace App\Support\Answers;

use App\Enums\Locale;
use App\Enums\OriginalTitleForm;
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
use InvalidArgumentException;

/**
 * Tirage des trois leurres du QCM d'une manche (spec 70 § 10.2-10.4, contrat
 * C11, D21 du 23/09) — l'échelle R1-R4, et elle seule.
 *
 * **Candidats** : le vivier de {@see PoolScope::forDecoys()}, qui exclut
 * **toujours** la cible, son `movie_group` et les films des manches déjà
 * démarrées de la partie (`started_at <= $at`), et **jamais** ceux des manches
 * futures, programmées comprises : les exclure prouverait qu'un film vu en
 * leurre n'est pas dans la suite du tirage. Chaque candidat est `published` et
 * `clear` ; la non-répétition du salon, quand elle est active, est conservée à
 * **tous** les rangs.
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
 * si R1 et R2 ne fournissent pas trois leurres, si le masque de la cible n'est
 * pas à la version courante, ou si les quatre films n'atteignent pas la même
 * locale pour une même locale de rendu (masque périmé) : l'échec tombe du côté
 * visible, jamais du côté silencieux (spec 10 § 3.2). Aucun rang ne lève la
 * non-répétition : après R4, c'est le cas terminal (NULL).
 *
 * **Tirage dans un rang** : uniforme, sans remise — les candidats du rang,
 * triés par `movie.id`, sont parcourus dans l'ordre de
 * `SeededPrf::forGame($game)->permutation($context, n)`, `n` = taille du rang.
 * Un candidat est rejeté si son `movie_group` est déjà pris par un leurre
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
        $prf = SeededPrf::forGame($game);

        $normal = $this->normalMode($target, $form, $roomPool, $prf, $round->sequence_index);

        if ($normal !== null) {
            return new DecoyPick(self::ids($normal), false);
        }

        // Mode dégradé : tirage REPRIS À ZÉRO, sur son propre contexte — rien de
        // ce que R1 et R2 avaient retenu n'est conservé.
        $degraded = $this->ladder(
            $roomPool,
            null,
            $form,
            DrawContext::decoysOriginal($round->sequence_index),
            $prf,
            $target,
            true,
        );

        return count($degraded) === DecoyPick::COUNT
            ? new DecoyPick(self::ids($degraded), true)
            : null;
    }

    /**
     * R1 puis R2, au profil de titre de la cible ; NULL quand le mode normal
     * n'est pas tenable (masque de la cible périmé, moins de trois leurres, ou
     * quatre films qui n'atteignent pas la même locale).
     *
     * @return list<Movie>|null
     */
    private function normalMode(
        Movie $target,
        OriginalTitleForm $form,
        PoolScope $roomPool,
        SeededPrf $prf,
        int $sequenceIndex,
    ): ?array {
        $projection = $target->projection;

        if ($projection === null || ! $projection->hasCurrentTitleMask()) {
            return null;
        }

        $mask = $projection->title_locale_mask;

        $decoys = $this->ladder(
            $roomPool,
            $mask,
            // Sans titre dans aucune locale activée, les quatre chaînes sortent
            // du titre original : sa forme entre dans le profil.
            $mask === 0 ? $form : null,
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
     * aucune colonne ne la porte).
     *
     * @return list<Movie>
     */
    private function rank(PoolScope $scope, ?int $mask, ?OriginalTitleForm $form): array
    {
        $query = $this->pool->movies($scope);

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
