<?php

namespace App\Support\Draw;

use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Settings\PlatformLimits;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Le tirage des films et des variantes, figé au lancement (spec 30 § 6 et § 7,
 * contrat C3).
 *
 * Deux étages :
 *
 * - {@see self::draw()} charge les entrées **en deux requêtes** sur le
 *   {@see PoolScope} reçu — le même que la garde du lancement, jamais
 *   reconstruit (§ 6.1) — et lit la marge dans `PlatformLimits` ;
 * - {@see self::drawFrom()} est une **fonction pure** de (graine, `DrawInput`) :
 *   ni base, ni configuration, ni horloge. Deux appels identiques rendent un
 *   `DrawResult` identique (§ 6.5).
 *
 * L'algorithme (§ 6.3) :
 *
 * 1. les candidats forment des **œuvres** — un film sans groupe, ou un
 *    `movie_group` entier —, ordonnées par leur plus petit `movieId`, membres
 *    triés par `movieId` ; `W` = nombre d'œuvres ; `W < M` lève
 *    {@see PoolTooSmallException} ;
 * 2. `K = min(M + marge, W)` ; les œuvres sont parcourues dans l'ordre de
 *    `permutation(DrawContext::movies(), W)` ;
 * 3. une œuvre à plusieurs membres retient **un seul** film, par
 *    `index(DrawContext::workMember(s), |œuvre|)` : l'exclusion mutuelle d'un
 *    `movie_group` est obtenue par construction, jamais par un rejet ;
 * 4. le masque est **recalculé depuis les variantes chargées**, jamais lu dans
 *    la projection : si `FrameLevelCoverage::sequences(N, masque)` rend `null`
 *    (projection périmée), l'œuvre est **sautée**, journalisée
 *    (`draw.work_skipped`, canal `game`), et le parcours continue ;
 * 5. chaque palier `i` reçoit une variante d'un niveau de sa plage, niveaux
 *    strictement croissants (D45 du 01/10), choisie par
 *    {@see VariantChooser::choose()} dans `DrawContext::variant(s, i)` parmi
 *    les variantes de tous les niveaux encore possibles ;
 * 6. `sequenceIndex = 1..K` dans l'ordre retenu, `roundNumber = s` si `s ≤ M`,
 *    nul pour la réserve ; moins de `M` retenus lève {@see PoolTooSmallException}.
 *
 * **Aucune pondération** (difficulté, notoriété, ancienneté) : le tirage est
 * uniforme sur les œuvres (§ 6.5). Aucun verrou, aucune écriture, aucune
 * transaction ouverte, **aucun appel TMDB** (règle 6) : appelé dans la
 * transaction de lancement, il lit l'instantané qu'elle a fixé. Aucun aléa hors
 * de {@see SeededPrf} (§ 5.5, `DrawBoundaryTest`).
 */
final readonly class GameDrawer
{
    /** Canal du journal d'une œuvre sautée (spec 100 § 10.9). */
    private const string LOG_CHANNEL = 'game';

    /** Libellé du journal d'une œuvre sautée (§ 6.3). */
    private const string LOG_WORK_SKIPPED = 'draw.work_skipped';

    public function __construct(
        private PoolQuery $pool,
        private VariantChooser $variants,
    ) {}

    /**
     * Le tirage du périmètre pour `M` manches, marge lue dans
     * `PlatformLimits::drawSubstituteMargin()`.
     *
     * Deux requêtes (§ 6.2) : les candidats du vivier, puis les variantes
     * jouables de tous les candidats, avec la mémoire du salon quand le
     * périmètre en a un.
     *
     * @throws InvalidArgumentException Le périmètre ne porte aucun `N`.
     * @throws PoolTooSmallException Moins de `M` œuvres tirables.
     */
    public function draw(PoolScope $scope, int $roundsCount, SeededPrf $prf): DrawResult
    {
        if ($scope->framesPerRound === null) {
            throw new InvalidArgumentException('GameDrawer::draw : le périmètre d’un tirage porte toujours un N.');
        }

        $candidates = $this->pool->candidates($scope);

        return $this->drawFrom(new DrawInput(
            candidates: $candidates,
            variantsByMovie: self::loadVariants($candidates, $scope->roomId),
            memorySince: $scope->memorySince,
            framesPerRound: $scope->framesPerRound,
            roundsCount: $roundsCount,
            margin: PlatformLimits::drawSubstituteMargin(),
        ), $prf);
    }

    /**
     * Le tirage, fonction pure de ses entrées (§ 6.3, § 6.5).
     *
     * @throws PoolTooSmallException Moins de `M` œuvres, ou moins de `M` retenues
     *                               après les œuvres sautées.
     */
    public function drawFrom(DrawInput $input, SeededPrf $prf): DrawResult
    {
        $works = self::works($input->candidates);
        $poolSize = count($works);
        $roundsCount = $input->roundsCount;

        if ($poolSize < $roundsCount) {
            throw new PoolTooSmallException($poolSize, $roundsCount);
        }

        $target = min($roundsCount + $input->margin, $poolSize);

        /** @var list<DrawnRound> $rounds */
        $rounds = [];

        foreach ($prf->permutation(DrawContext::movies(), $poolSize) as $position) {
            if (count($rounds) >= $target) {
                break;
            }

            $sequenceIndex = count($rounds) + 1;
            $members = $works[$position];

            $candidate = count($members) === 1
                ? $members[0]
                : $members[$prf->index(DrawContext::workMember($sequenceIndex), count($members))];

            $variants = $input->variantsByMovie[$candidate->movieId] ?? [];
            $levelsMask = FrameLevelCoverage::maskOf(array_map(
                static fn (VariantCandidate $variant): FrameLevel => $variant->frameLevel,
                $variants,
            ));
            $sequences = FrameLevelCoverage::sequences($input->framesPerRound, $levelsMask);

            if ($sequences === null) {
                self::logSkippedWork($candidate, $input->framesPerRound, $levelsMask);

                continue;
            }

            $rounds[] = new DrawnRound(
                sequenceIndex: $sequenceIndex,
                roundNumber: $sequenceIndex <= $roundsCount ? $sequenceIndex : null,
                movieId: $candidate->movieId,
                tiers: $this->tiers($sequenceIndex, $sequences, $variants, $input->memorySince, $prf),
            );
        }

        if (count($rounds) < $roundsCount) {
            throw new PoolTooSmallException(count($rounds), $roundsCount);
        }

        return new DrawResult($rounds, $poolSize, $roundsCount);
    }

    /**
     * Les `N` paliers d'une manche, palier par palier (D45 du 01/10) : le
     * palier `i` choisit parmi les variantes de **tous** les niveaux qu'une
     * séquence encore possible place en position `i`, puis ne garde que les
     * séquences qui passent par le niveau choisi. Les niveaux sont donc
     * strictement croissants et chaque palier reste dans sa plage, sauf repli.
     *
     * @param  non-empty-list<list<FrameLevel>>  $sequences  `FrameLevelCoverage::sequences(N, masque)`.
     * @param  list<VariantCandidate>  $variants  Toutes les variantes du film.
     * @return list<DrawnTier>
     */
    private function tiers(
        int $sequenceIndex,
        array $sequences,
        array $variants,
        ?CarbonImmutable $memorySince,
        SeededPrf $prf,
    ): array {
        $tiers = [];
        $framesPerRound = count($sequences[0]);

        for ($offset = 0; $offset < $framesPerRound; $offset++) {
            $tierIndex = $offset + 1;

            $allowed = array_map(static fn (array $sequence): FrameLevel => $sequence[$offset], $sequences);

            $candidates = array_values(array_filter(
                $variants,
                static fn (VariantCandidate $variant): bool => in_array($variant->frameLevel, $allowed, true),
            ));

            $frameId = $this->variants->choose(
                $candidates,
                $memorySince,
                $prf,
                DrawContext::variant($sequenceIndex, $tierIndex),
            );

            // `sequences` n'a rendu que des niveaux présents dans le masque des
            // variantes chargées : chaque palier en a au moins une (§ 7.3).
            if ($frameId === null) {
                throw new LogicException('GameDrawer : un niveau retenu par le masque n’a aucune variante.');
            }

            $level = self::levelOf($candidates, $frameId);
            $sequences = array_values(array_filter(
                $sequences,
                static fn (array $sequence): bool => $sequence[$offset] === $level,
            ));

            $tiers[] = new DrawnTier($tierIndex, $frameId, $level);
        }

        return $tiers;
    }

    /**
     * Le niveau de la variante retenue parmi les candidates.
     *
     * @param  list<VariantCandidate>  $candidates
     */
    private static function levelOf(array $candidates, int $frameId): FrameLevel
    {
        foreach ($candidates as $candidate) {
            if ($candidate->frameId === $frameId) {
                return $candidate->frameLevel;
            }
        }

        throw new LogicException('GameDrawer : la variante retenue n’est pas parmi les candidates.');
    }

    /**
     * Les œuvres du vivier : une clé par `groupId` (ou par film seul), ordonnées
     * par leur plus petit `movieId`, membres triés par `movieId`.
     *
     * Les candidats arrivant triés par `movieId` (garde de {@see DrawInput}), la
     * première apparition d'une œuvre est son plus petit membre : l'ordre
     * d'insertion suffit, sans tri.
     *
     * @param  list<PoolCandidate>  $candidates
     * @return list<non-empty-list<PoolCandidate>>
     */
    private static function works(array $candidates): array
    {
        /** @var array<string, non-empty-list<PoolCandidate>> $works */
        $works = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->groupId === null ? 'movie:'.$candidate->movieId : 'group:'.$candidate->groupId;
            $works[$key][] = $candidate;
        }

        return array_values($works);
    }

    /**
     * Les variantes jouables de tous les candidats, en une requête (§ 6.2).
     *
     * Prédicat unique de variante jouable (spec 10 § 3.2) par la portée
     * {@see Frame::servable()}, celle du projecteur et de la substitution :
     * `published` ET `ready` ET `game_path IS NOT NULL`, servi par
     * `frame_movie_level_idx`, trié par `(movie_id, frame_level, id)`. Avec
     * un salon, jointure gauche sur `seen_frame` du salon
     * (`seen_frame_room_frame_uq`) pour `lastSeenAt` ; sans salon (solo,
     * catalogue), aucune jointure : aucune mémoire n'est lue.
     *
     * @param  list<PoolCandidate>  $candidates
     * @return array<int, list<VariantCandidate>>
     */
    private static function loadVariants(array $candidates, ?int $roomId): array
    {
        if ($candidates === []) {
            return [];
        }

        $query = Frame::query()
            ->select(['frame.id', 'frame.movie_id', 'frame.frame_level'])
            ->servable()
            ->whereIn('frame.movie_id', array_map(
                static fn (PoolCandidate $candidate): int => $candidate->movieId,
                $candidates,
            ))
            ->orderBy('frame.movie_id')
            ->orderBy('frame.frame_level')
            ->orderBy('frame.id');

        if ($roomId !== null) {
            $query->leftJoin('seen_frame', static function (JoinClause $seen) use ($roomId): void {
                $seen->on('seen_frame.frame_id', '=', 'frame.id')
                    ->where('seen_frame.room_id', '=', $roomId);
            })
                ->addSelect('seen_frame.last_seen_at')
                ->withCasts(['last_seen_at' => 'datetime']);
        }

        $variantsByMovie = [];

        foreach ($query->get() as $frame) {
            // La colonne n'est sélectionnée qu'avec un salon : sans lui, aucune
            // mémoire n'est lue, pas même un attribut absent.
            $lastSeenAt = $roomId === null ? null : $frame->getAttribute('last_seen_at');

            $variantsByMovie[$frame->movie_id][] = new VariantCandidate(
                frameId: $frame->id,
                frameLevel: $frame->frame_level,
                lastSeenAt: $lastSeenAt instanceof CarbonImmutable ? $lastSeenAt : null,
            );
        }

        return $variantsByMovie;
    }

    /**
     * Une œuvre sautée pour projection périmée (§ 6.3) : effet de bord de
     * journal, qui n'entre dans aucun calcul. Aucune donnée de joueur, aucun
     * titre : le film, `N`, le masque projeté et le masque réel.
     */
    private static function logSkippedWork(PoolCandidate $candidate, int $framesPerRound, int $levelsMask): void
    {
        Log::channel(self::LOG_CHANNEL)->warning(self::LOG_WORK_SKIPPED, [
            'movie_id' => $candidate->movieId,
            'frames_per_round' => $framesPerRound,
            'projected_levels_mask' => $candidate->levelsMask,
            'levels_mask' => $levelsMask,
        ]);
    }
}
