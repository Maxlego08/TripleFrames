<?php

namespace App\ValueObjects\Catalog;

use App\Enums\FrameLevel;
use App\Models\MovieProjection;
use App\Settings\RoomSettingsBounds;
use InvalidArgumentException;

/**
 * Couverture de niveaux d'un film : répartition nominale `N` → niveaux et
 * repli de niveau (spec 30 § 2, contrat C1).
 *
 * **Seul endroit du dépôt où la table `N` → niveaux est écrite** (spec 10
 * § 15, E10-67), et la garde `DrawBoundaryTest` le tient. Tirage, fabriques,
 * seeders et back-office la lisent par {@see self::nominal()} ; aucun ne la
 * recopie. C'est une **constante de règle de produit** (`00` § Le jeu en une
 * manche), pas une valeur de jeu réglable : elle ne viole pas la règle 2, pas
 * plus que la définition d'une décennie. `N` reste un réglage de salon, borné
 * par {@see RoomSettingsBounds}, et les bits viennent de {@see FrameLevel::bit()}.
 *
 * Méthodes statiques pures, sans base ni configuration, **sans graine** : le
 * repli est une propriété de la banque du film, pas du tirage. Le rendre
 * aléatoire ferait varier d'une partie à l'autre les niveaux montrés pour un
 * même film, sans que le curateur puisse le prévoir.
 *
 * **Jamais d'écrêtage silencieux** : un `N` hors bornes ou un masque hors de
 * `[0, maskOf(FrameLevel::cases())]` lève `InvalidArgumentException`. Un `N`
 * invalide qui passerait pour un autre fausserait un tirage que la
 * matérialisation existe pour rendre rejouable.
 *
 * Le masque 1-3-5 de {@see MovieProjection::publishableLevelsMask()}
 * n'entre **pas** ici : c'est une garde de transition vers `published`, jamais
 * une condition de jeu (spec 30 § 2.5).
 */
final readonly class FrameLevelCoverage
{
    /**
     * Répartition nominale des niveaux pour `N` images par manche, croissante :
     * le niveau le plus cryptique est au palier 1.
     *
     * @return list<FrameLevel>
     *
     * @throws InvalidArgumentException `N` hors de `[MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`.
     */
    public static function nominal(int $framesPerRound): array
    {
        self::assertFramesPerRound($framesPerRound);

        return match ($framesPerRound) {
            2 => [FrameLevel::Level1, FrameLevel::Level5],
            3 => [FrameLevel::Level1, FrameLevel::Level3, FrameLevel::Level5],
            4 => [FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level4, FrameLevel::Level5],
            5 => [FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level3, FrameLevel::Level4, FrameLevel::Level5],
            // Atteint seulement si les bornes de `N` s'élargissent sans que la
            // table suive : mieux vaut lever que tirer un film sur une
            // répartition inventée.
            default => throw new InvalidArgumentException(sprintf(
                'Aucune répartition nominale n’est écrite pour frames_per_round = %d.',
                $framesPerRound,
            )),
        };
    }

    /**
     * Les `N` niveaux montrés pour un film dont la banque couvre `$levelsMask`,
     * croissants et distincts, ou `null` si le masque couvre moins de `N`
     * niveaux.
     *
     * Algorithme (spec 30 § 2.2) : parcourir en ordre lexicographique les
     * combinaisons de `N` niveaux disponibles, coût `Σᵢ |S[i] − nominal(N)[i]|`,
     * rendre la **première** de coût minimal. Une égalité se départage donc vers
     * le niveau le plus cryptique : le palier le mieux payé reste le plus
     * difficile, et un film à banque maigre n'est jamais plus rentable qu'un
     * film complet.
     *
     * `select(N, m) !== null` ⟺ `popcount(m) ≥ N` ⟺
     * `MovieProjection::supportsFramesPerRound(N)`. L'éligibilité n'est jamais
     * stockée.
     *
     * @return list<FrameLevel>|null
     *
     * @throws InvalidArgumentException `N` ou masque hors bornes.
     */
    public static function select(int $framesPerRound, int $levelsMask): ?array
    {
        $nominal = self::nominal($framesPerRound);
        $available = self::levelsIn($levelsMask);

        if (count($available) < $framesPerRound) {
            return null;
        }

        $selected = null;
        $selectedCost = PHP_INT_MAX;

        foreach (self::combinations($available, $framesPerRound) as $candidate) {
            $cost = 0;

            foreach ($candidate as $index => $level) {
                $cost += abs($level->value - $nominal[$index]->value);
            }

            // Strictement inférieur : à coût égal, la première combinaison
            // lexicographique — la plus cryptique — reste retenue.
            if ($cost < $selectedCost) {
                $selected = $candidate;
                $selectedCost = $cost;
            }
        }

        return $selected;
    }

    /**
     * Vrai si le film est jouable à `N` et que ses niveaux montrés diffèrent de
     * la répartition nominale : il est joué avec repli de niveau.
     *
     * Ne se confond pas avec le signal « incomplet » du back-office (masque
     * 1-3-5 non couvert) : un film `1,2,3,5` est en repli à `N` = 4 sans être
     * incomplet, un film `2,3,4,5` est incomplet sans être en repli à `N` = 4.
     *
     * @throws InvalidArgumentException `N` ou masque hors bornes.
     */
    public static function usesFallback(int $framesPerRound, int $levelsMask): bool
    {
        $selected = self::select($framesPerRound, $levelsMask);

        return $selected !== null && $selected !== self::nominal($framesPerRound);
    }

    /**
     * Masque de bits des niveaux donnés, dans le format de
     * `movie_projection.levels_mask`. Un niveau répété ne compte qu'une fois.
     *
     * @param  iterable<FrameLevel>  $levels
     */
    public static function maskOf(iterable $levels): int
    {
        $mask = 0;

        foreach ($levels as $level) {
            $mask |= $level->bit();
        }

        return $mask;
    }

    /**
     * Niveaux présents dans un masque, croissants.
     *
     * @return list<FrameLevel>
     *
     * @throws InvalidArgumentException Masque hors de `[0, maskOf(FrameLevel::cases())]`.
     */
    public static function levelsIn(int $levelsMask): array
    {
        $fullMask = self::maskOf(FrameLevel::cases());

        if ($levelsMask < 0 || $levelsMask > $fullMask) {
            throw new InvalidArgumentException(sprintf(
                'Le masque de niveaux vaut %d : les bornes sont 0 à %d.',
                $levelsMask,
                $fullMask,
            ));
        }

        $levels = array_values(array_filter(
            FrameLevel::cases(),
            static fn (FrameLevel $level): bool => ($levelsMask & $level->bit()) !== 0,
        ));

        usort($levels, static fn (FrameLevel $a, FrameLevel $b): int => $a->value <=> $b->value);

        return $levels;
    }

    /**
     * @throws InvalidArgumentException `N` hors de `[MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`.
     */
    private static function assertFramesPerRound(int $framesPerRound): void
    {
        if ($framesPerRound < RoomSettingsBounds::MIN_FRAMES_PER_ROUND
            || $framesPerRound > RoomSettingsBounds::MAX_FRAMES_PER_ROUND) {
            throw new InvalidArgumentException(sprintf(
                'frames_per_round vaut %d : les bornes du salon sont %d à %d.',
                $framesPerRound,
                RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            ));
        }
    }

    /**
     * Combinaisons de `$size` éléments de `$levels`, chacune dans l'ordre de
     * `$levels`, énumérées en ordre lexicographique — au plus `C(5, N) ≤ 10`.
     *
     * @param  list<FrameLevel>  $levels  croissants
     * @return list<list<FrameLevel>>
     */
    private static function combinations(array $levels, int $size): array
    {
        if ($size === 0) {
            return [[]];
        }

        $combinations = [];
        $count = count($levels);

        for ($first = 0; $first <= $count - $size; $first++) {
            foreach (self::combinations(array_slice($levels, $first + 1), $size - 1) as $rest) {
                $combinations[] = [$levels[$first], ...$rest];
            }
        }

        return $combinations;
    }
}
