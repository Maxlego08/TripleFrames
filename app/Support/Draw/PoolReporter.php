<?php

namespace App\Support\Draw;

use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Settings\RoomSettingsBounds;
use InvalidArgumentException;

/**
 * Le rapport de vivier : la borne croisée 3 (`vivier(thèmes, N) ≥ M`) calculée
 * en données (spec 30 § 4, contrat C2).
 *
 * Répartition des rôles (§ 4.1) : cette classe **calcule** ; la spec 50
 * déclenche, anti-rebondit, diffuse, affiche et rejoue la garde dans la
 * transaction de lancement. Elle ne lit le vivier que par {@see PoolQuery} — le
 * constructeur unique, dont l'instance `scoped` est partagée avec le tirage du
 * même lancement — et sur le {@see PoolScope} reçu, jamais reconstruit : c'est
 * ce qui garantit que la garde et le tirage lisent la même fenêtre de mémoire.
 * Aucune transaction ouverte, aucun verrou, aucune écriture, aucun appel TMDB.
 *
 * Causes et remèdes, dans cet **ordre fixe** (§ 4.3), chaque remède débloquant
 * **à lui seul** — un remède qui ne suffit pas renverrait l'hôte à un second
 * refus, et le message nommant le réglage fautif deviendrait une devinette :
 *
 * | cause            | condition                                             | remèdes                                      |
 * |------------------|-------------------------------------------------------|----------------------------------------------|
 * | `noRepeatMovies` | non-répétition active ET vivier sans elle `≥ M`       | `open_new_room`, puis `disable_no_repeat`    |
 * | `themeKeys`      | thèmes effectifs non vides ET vivier sans thème `≥ M` | `clear_themes`                               |
 * | `framesPerRound` | un `N' < N` atteint `M`                               | `lower_frames_per_round` {value: `N'`}       |
 * | `roundsCount`    | vivier `≥ RoomSettingsBounds::MIN_ROUNDS_COUNT`       | `reduce_rounds_count` {value: vivier}        |
 *
 * Coût : non bloqué, un seul compte ; bloqué, un compte par cause testée plus
 * au plus `N − MIN_FRAMES_PER_ROUND` pour le `N` jouable le plus proche, tous
 * indexés. Aucun littéral de jeu : bornes dans {@see RoomSettingsBounds}.
 *
 * La mesure d'un thème avant sa publication, {@see self::themeWorks()}, est
 * livrée au J1 (L30-11a, D43 du 01/10) ; `themeSelectorVisible()` reste au J2
 * (lot L30-11) : le sélecteur du lobby reste masqué au J1.
 */
final readonly class PoolReporter
{
    public function __construct(private PoolQuery $pool) {}

    /**
     * Le rapport du périmètre pour `M` manches.
     *
     * `M` est pris tel quel, jamais borné ici : le lobby (spec 50 § 2.6) passe
     * les réglages du salon relus fidèlement par le cast (10 § 6.1), qui peuvent
     * porter un `M` devenu hors bornes après un resserrement de
     * `RoomSettingsBounds` ; c'est l'étape L5 du lancement (50 § 12.2) qui les
     * normalise, et le rapport doit se calculer d'ici là.
     *
     * @throws InvalidArgumentException Le périmètre ne porte aucun `N`.
     */
    public function report(PoolScope $scope, int $roundsCount): PoolReport
    {
        $framesPerRound = self::framesPerRoundOf($scope);

        $count = $this->pool->countWorks($scope);
        $themesPruned = $this->pool->themesPruned($scope);

        if ($count >= $roundsCount) {
            return new PoolReport(
                count: $count,
                framesPerRound: $framesPerRound,
                roundsCount: $roundsCount,
                causes: [],
                remedies: [],
                nearestPlayableFramesPerRound: null,
                themesPruned: $themesPruned,
            );
        }

        $causes = [];
        $remedies = [];

        // 1. Non-répétition — jamais dans les viviers catalogue et solo, qui
        //    n'ont pas de salon : la clause y est coupée par construction.
        if ($scope->noRepeatMovies) {
            $withoutNoRepeat = $this->pool->countWorks($scope->withoutNoRepeat());

            if ($withoutNoRepeat >= $roundsCount) {
                $causes[] = PoolFault::NoRepeatMovies;
                // Un nouveau salon a une mémoire vide : même vivier que sans la clause.
                $remedies[] = new PoolRemedy(PoolRemedyKind::OpenNewRoom, null, $withoutNoRepeat);
                $remedies[] = new PoolRemedy(PoolRemedyKind::DisableNoRepeat, null, $withoutNoRepeat);
            }
        }

        // 2. Thèmes — seulement s'ils filtrent encore : un thème dépublié est
        //    élagué à la lecture et n'est jamais accusé.
        if ($this->pool->effectiveThemeIds($scope) !== []) {
            $withoutThemes = $this->pool->countWorks($scope->withThemeIds([]));

            if ($withoutThemes >= $roundsCount) {
                $causes[] = PoolFault::ThemeKeys;
                $remedies[] = new PoolRemedy(PoolRemedyKind::ClearThemes, null, $withoutThemes);
            }
        }

        // 3. N — toujours vers le bas, jamais au-delà de N (§ 4.4).
        $nearest = $this->nearestPlayable($scope, $framesPerRound, $roundsCount);

        if ($nearest !== null) {
            $causes[] = PoolFault::FramesPerRound;
            $remedies[] = new PoolRemedy(PoolRemedyKind::LowerFramesPerRound, $nearest['framesPerRound'], $nearest['count']);
        }

        // 4. M — réduit au vivier courant, remède le plus proche du réglage de
        //    l'hôte ; seulement si ce vivier est lui-même un M admis.
        if ($count >= RoomSettingsBounds::MIN_ROUNDS_COUNT) {
            $causes[] = PoolFault::RoundsCount;
            $remedies[] = new PoolRemedy(PoolRemedyKind::ReduceRoundsCount, $count, $count);
        }

        return new PoolReport(
            count: $count,
            framesPerRound: $framesPerRound,
            roundsCount: $roundsCount,
            causes: $causes,
            remedies: $remedies,
            nearestPlayableFramesPerRound: $nearest['framesPerRound'] ?? null,
            themesPruned: $themesPruned,
        );
    }

    /**
     * Le `N` jouable le plus proche (§ 4.4) : le **plus grand**
     * `N' ∈ [MIN_FRAMES_PER_ROUND, N − 1]` dont le vivier atteint `M`, `null`
     * sinon.
     *
     * Le vivier étant monotone décroissant en `N`, il n'existe jamais de `N' > N`
     * jouable : la proposition est unique et toujours vers le bas. Sert le
     * rapport, le grisage des presets (spec 50) et le `N` imposé d'office en solo
     * (D19 du 23/09, spec 60).
     *
     * @throws InvalidArgumentException Le périmètre ne porte aucun `N`.
     */
    public function nearestPlayableFramesPerRound(PoolScope $scope, int $roundsCount): ?int
    {
        $framesPerRound = self::framesPerRoundOf($scope);

        return $this->nearestPlayable($scope, $framesPerRound, $roundsCount)['framesPerRound'] ?? null;
    }

    /**
     * Supervision « œuvres jouables à `N` » du back-office (spec 20) : le vivier
     * **catalogue**, sans thème ni clause de salon, compté en œuvres, un compte
     * par `N` de `MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND` — sans
     * `GROUP BY`, par le constructeur unique.
     *
     * @return array<int, int> `N` => œuvres
     */
    public function catalogueWorksByFramesPerRound(): array
    {
        $works = [];

        foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
            $works[$framesPerRound] = $this->pool->countWorks(PoolScope::catalogue([], $framesPerRound));
        }

        return $works;
    }

    /**
     * Mesure d'un thème avant sa publication (§ 12.3, L30-11a) : le vivier
     * catalogue restreint à **ce seul thème, publié ou non**, au `N` par défaut,
     * compté en œuvres — `countWorks(PoolScope::themeProbe($themeId))`. Lue par
     * l'écran des thèmes et rejouée par le geste de publication (spec 20 § 9.6).
     */
    public function themeWorks(int $themeId): int
    {
        return $this->pool->countWorks(PoolScope::themeProbe($themeId));
    }

    /**
     * Recherche descendante depuis `N − 1` : le premier `N'` qui atteint `M` est
     * le plus grand ; au plus `N − MIN_FRAMES_PER_ROUND` comptes.
     *
     * @return array{framesPerRound: int, count: int}|null
     */
    private function nearestPlayable(PoolScope $scope, int $framesPerRound, int $roundsCount): ?array
    {
        for ($lower = $framesPerRound - 1; $lower >= RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $lower--) {
            $count = $this->pool->countWorks($scope->withFramesPerRound($lower));

            if ($count >= $roundsCount) {
                return ['framesPerRound' => $lower, 'count' => $count];
            }
        }

        return null;
    }

    /**
     * Le `N` du périmètre : un rapport sans `N` n'a pas de sens — seul le
     * complément des leurres porte un périmètre sans clause de `N`.
     *
     * @throws InvalidArgumentException
     */
    private static function framesPerRoundOf(PoolScope $scope): int
    {
        return $scope->framesPerRound
            ?? throw new InvalidArgumentException('PoolReporter : le périmètre ne porte aucun N (framesPerRound nul).');
    }
}
