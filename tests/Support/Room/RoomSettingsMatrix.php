<?php

namespace Tests\Support\Room;

use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use Closure;
use LogicException;

/**
 * Générateur de la matrice des réglages (spec 100 § 4, contrat C18 § 2.7).
 *
 * La règle 2 veut que les bornes CROISÉES se valident côté serveur : un
 * FormRequest champ par champ laisserait passer 10 s × 5 images. La matrice le
 * prouve combinaison par combinaison, sur le produit complet
 * `N × D × R × barème` plus la famille `advanced` : environ 1 500 cas,
 * déterministes (aucun Faker, aucun tirage) et aux étiquettes stables.
 *
 * **Le générateur ne réimplémente jamais la validation.** Chaque valeur d'axe
 * est dérivée des bornes NOMMÉES de {@see Bounds} — les seuls littéraux sont
 * les décalages ±1 — et porte son verdict ÉCRIT À LA MAIN : « `MAX_ROUND_DURATION
 * + 1` est refusé sous `roundDuration` », « `LONG_ROUND_WARNING_DURATION + 1`
 * avertit `long_round` ». Les frontières croisées (`n̂`, `D ≥ 5 s × N`, plafond
 * d'un palier) s'écrivent elles aussi à la main depuis ces constantes, jamais
 * par `clampFramesPerRound()`, `minRoundDuration()` ni `maxTierDuration()`, que
 * `validate()` appelle : sinon une régression de ces fonctions déplacerait la
 * valeur d'axe ET le verdict du code d'un même pas, et la matrice prouverait
 * qu'une copie du code est égale au code. Ces verdicts supposent l'ordre des
 * bornes livré (`MIN_TIER_DURATION × MAX_FRAMES_PER_ROUND ≤ DEFAULT_ROUND_DURATION ≤
 * LONG_ROUND_WARNING_DURATION < MAX_ROUND_DURATION`, `MIN_REVEAL_DURATION <
 * RECOMMENDED_MIN_REVEAL_DURATION ≤ MAX_REVEAL_DURATION`, `MIN_TIER_POINTS` nul,
 * `MIN_FRAMES_PER_ROUND ≥ 2`) : une borne qui change cet ordre fait échouer la
 * matrice, et c'est voulu — le verdict se relit et se réécrit, il ne se recalcule
 * pas.
 *
 * **Règle d'interaction de deux verdicts** (spec 50 § 4.1), encodée une seule
 * fois, ici :
 * - cumul sans court-circuit : `refusedFields` est l'union des verdicts des axes ;
 * - `N` borné : un `framesPerRound` hors bornes est refusé sous son nom, et les
 *   bornes croisées s'évaluent contre `n̂`, la borne la plus proche — les axes
 *   `D` et barème sont donc construits pour `n̂`, taille de liste comprise ;
 * - les avertissements ne portent que sur une entrée acceptée : `warnings` est
 *   l'union des avertissements des axes si et seulement si rien n'est refusé.
 *
 * @phpstan-type AxisValue array{label: string, input: array<string, mixed>, refused: list<string>, warnings: list<string>}
 */
final class RoomSettingsMatrix
{
    /** Séparateur des fragments d'étiquette : « N3·D14·R5·points:default ». */
    private const string LABEL_SEPARATOR = '·';

    /**
     * Tous les cas, refusés comme acceptés.
     *
     * @return iterable<string, array{RoomSettingsCase}>
     */
    public static function cases(): iterable
    {
        $seen = [];

        foreach (self::allCases() as $case) {
            // Pest indexe un jeu par étiquette : un doublon en écraserait un
            // autre en silence, et la matrice rétrécirait sans que rien ne rougisse.
            if (isset($seen[$case->label])) {
                throw new LogicException("Étiquette de la matrice en double : [{$case->label}].");
            }

            $seen[$case->label] = true;

            yield $case->label => [$case];
        }
    }

    /**
     * Les seuls cas acceptés, avec leurs avertissements attendus.
     *
     * @return iterable<string, array{RoomSettingsCase}>
     */
    public static function acceptedCases(): iterable
    {
        foreach (self::cases() as $label => [$case]) {
            if ($case->accepted()) {
                yield $label => [$case];
            }
        }
    }

    /**
     * Chaque combinaison acceptée, construite par le chemin d'entrée de l'hôte.
     *
     * Chaque valeur est une fermeture LIÉE, résolue par Pest dans le test qui la
     * reçoit (paramètre typé `RoomSettings`) : `fromInput()` lit la capacité dans
     * `PlatformLimits`, donc dans `config()`, alors que Pest résout les jeux de
     * données avant le démarrage de l'application. La fermeture ne doit pas être
     * `static` : Pest la lie au cas de test avant de l'appeler.
     *
     * @return iterable<string, array{Closure(): RoomSettings}>
     */
    public static function accepted(): iterable
    {
        foreach (self::acceptedCases() as $label => [$case]) {
            $input = $case->input;

            yield $label => [fn (): RoomSettings => RoomSettings::fromInput($input)];
        }
    }

    /**
     * @return iterable<int, RoomSettingsCase>
     */
    private static function allCases(): iterable
    {
        foreach (self::framesPerRoundAxis() as [$frames, $clampedFrames]) {
            foreach (self::roundDurationAxis($clampedFrames) as $duration) {
                foreach (self::revealDurationAxis() as $reveal) {
                    foreach (self::tierPointsAxis($clampedFrames) as $points) {
                        yield self::compose([$frames, $duration, $reveal, $points]);
                    }
                }
            }
        }

        yield from self::advancedFamily();
    }

    /**
     * Axe `N`, de `MIN_FRAMES_PER_ROUND − 1` à `MAX_FRAMES_PER_ROUND + 1`.
     *
     * Chaque valeur porte aussi `n̂`, contre lequel les bornes croisées sont
     * évaluées : la borne la plus proche pour une valeur hors bornes, la valeur
     * elle-même sinon.
     *
     * @return list<array{AxisValue, int}>
     */
    private static function framesPerRoundAxis(): array
    {
        $below = Bounds::MIN_FRAMES_PER_ROUND - 1;
        $above = Bounds::MAX_FRAMES_PER_ROUND + 1;

        $axis = [[self::frames($below, refused: ['framesPerRound']), Bounds::MIN_FRAMES_PER_ROUND]];

        for ($frames = Bounds::MIN_FRAMES_PER_ROUND; $frames <= Bounds::MAX_FRAMES_PER_ROUND; $frames++) {
            $axis[] = [self::frames($frames), $frames];
        }

        $axis[] = [self::frames($above, refused: ['framesPerRound']), Bounds::MAX_FRAMES_PER_ROUND];

        return $axis;
    }

    /**
     * Axe `D` (clé d'entrée `roundDuration` de l'onglet Simple), pour `n̂`.
     *
     * Borne croisée 1 (`D ≥ 5 s × N`) et avertissement strict au-delà de
     * `LONG_ROUND_WARNING_DURATION` (borne croisée 5).
     *
     * @return list<AxisValue>
     */
    private static function roundDurationAxis(int $clampedFrames): array
    {
        // Borne croisée 1 réécrite depuis les bornes nommées, jamais lue dans
        // `minRoundDuration()`, que `validate()` appelle.
        $minimum = max(Bounds::MIN_ROUND_DURATION, Bounds::MIN_TIER_DURATION * $clampedFrames);
        $long = Bounds::LONG_ROUND_WARNING_DURATION;
        $maximum = Bounds::MAX_ROUND_DURATION;

        return self::deduplicate([
            self::duration($minimum - 1, refused: [RoomSettings::INPUT_ROUND_DURATION]),
            self::duration($minimum),
            self::duration(Bounds::DEFAULT_ROUND_DURATION),
            self::duration($long),
            self::duration($long + 1, warnings: [RoomSettings::WARNING_LONG_ROUND]),
            self::duration($maximum, warnings: [RoomSettings::WARNING_LONG_ROUND]),
            self::duration($maximum + 1, refused: [RoomSettings::INPUT_ROUND_DURATION]),
        ]);
    }

    /**
     * Axe `R` : bornes simples et avertissement sous la valeur recommandée
     * (borne croisée 4).
     *
     * @return list<AxisValue>
     */
    private static function revealDurationAxis(): array
    {
        $recommended = Bounds::RECOMMENDED_MIN_REVEAL_DURATION;

        return self::deduplicate([
            self::reveal(Bounds::MIN_REVEAL_DURATION - 1, refused: ['revealDuration']),
            self::reveal(Bounds::MIN_REVEAL_DURATION, warnings: [RoomSettings::WARNING_SHORT_REVEAL]),
            self::reveal($recommended - 1, warnings: [RoomSettings::WARNING_SHORT_REVEAL]),
            self::reveal($recommended),
            self::reveal(Bounds::MAX_REVEAL_DURATION),
            self::reveal(Bounds::MAX_REVEAL_DURATION + 1, refused: ['revealDuration']),
        ]);
    }

    /**
     * Axe barème, liste de taille `n̂`.
     *
     * L'égalité compte comme non décroissante : un barème plat (tout au minimum,
     * tout au maximum) avertit `non_decreasing_points`, et tout au minimum
     * avertit en plus `all_tiers_zero` (mode « sans score »).
     *
     * @return list<AxisValue>
     */
    private static function tierPointsAxis(int $clampedFrames): array
    {
        $default = Bounds::defaultTierPoints($clampedFrames);

        $firstBelowMinimum = $default;
        $firstBelowMinimum[0] = Bounds::MIN_TIER_POINTS - 1;

        $firstAboveMaximum = $default;
        $firstAboveMaximum[0] = Bounds::MAX_TIER_POINTS + 1;

        return [
            self::points('default', $default),
            self::points('inverse', array_reverse($default), warnings: [
                RoomSettings::WARNING_NON_DECREASING_POINTS,
            ]),
            self::points('min', array_fill(0, $clampedFrames, Bounds::MIN_TIER_POINTS), warnings: [
                RoomSettings::WARNING_NON_DECREASING_POINTS,
                RoomSettings::WARNING_ALL_TIERS_ZERO,
            ]),
            self::points('max', array_fill(0, $clampedFrames, Bounds::MAX_TIER_POINTS), warnings: [
                RoomSettings::WARNING_NON_DECREASING_POINTS,
            ]),
            self::points('first-below-min', $firstBelowMinimum, refused: ['tierPoints.0']),
            self::points('first-above-max', $firstAboveMaximum, refused: ['tierPoints.0']),
        ];
    }

    /**
     * Famille `advanced` : `tierDurations` posté SANS `roundDuration`, `N` dans
     * ses bornes (borne croisée 2).
     *
     * Elle exerce `validate()` directement : le value object accepte déjà les
     * seize champs, et seul l'éditeur de l'onglet Simple refuse `advanced: true`
     * au jalon 1 (spec 100 § 4, `RoomSettingsEditorTest` de 50). Une liste de
     * mauvaise taille ne produit que `list_size` sous `tierDurations`, sans
     * erreur par palier (spec 50 § 4.1).
     *
     * @return iterable<int, RoomSettingsCase>
     */
    private static function advancedFamily(): iterable
    {
        $family = self::value('advanced', ['advanced' => true]);

        for ($frames = Bounds::MIN_FRAMES_PER_ROUND; $frames <= Bounds::MAX_FRAMES_PER_ROUND; $frames++) {
            $count = self::frames($frames);

            // (α) Paliers égaux sur `D` par défaut, le premier sous le plancher
            // d'un palier : la somme reste dans les bornes de `D`.
            $firstBelowMinimum = Bounds::defaultTierDurations($frames, Bounds::DEFAULT_ROUND_DURATION);
            $firstBelowMinimum[0] = Bounds::MIN_TIER_DURATION - 1;

            // (β) Paliers égaux sur `D` maximal, le dernier allongé d'une
            // seconde : chaque palier est légal, leur somme ne l'est plus.
            $sumAboveMaximum = Bounds::defaultTierDurations($frames, Bounds::MAX_ROUND_DURATION);
            $sumAboveMaximum[count($sumAboveMaximum) - 1]++;

            // (γ) Premier palier au-delà de son plafond, les autres au plancher :
            // la somme vaut alors `MAX_ROUND_DURATION + 1`. Le plafond d'un
            // palier est réécrit depuis les bornes nommées, jamais lu dans
            // `maxTierDuration()`, que `validate()` appelle.
            $firstAboveMaximum = array_fill(0, $frames, Bounds::MIN_TIER_DURATION);
            $firstAboveMaximum[0] = Bounds::MAX_ROUND_DURATION - Bounds::MIN_TIER_DURATION * ($frames - 1) + 1;

            // (δ) Une entrée de trop, toutes au plancher.
            $sizePlusOne = array_fill(0, $frames + 1, Bounds::MIN_TIER_DURATION);

            yield self::compose([$family, $count, self::tiers('first-below-min', $firstBelowMinimum, ['tierDurations.0'])]);
            yield self::compose([$family, $count, self::tiers('sum-above-max', $sumAboveMaximum, ['tierDurations'])]);
            yield self::compose([$family, $count, self::tiers('first-above-max', $firstAboveMaximum, ['tierDurations.0', 'tierDurations'])]);
            yield self::compose([$family, $count, self::tiers('size-plus-one', $sizePlusOne, ['tierDurations'])]);
        }
    }

    /**
     * La règle d'interaction de la spec 50 § 4.1, et elle seule.
     *
     * @param  list<AxisValue>  $values
     */
    private static function compose(array $values): RoomSettingsCase
    {
        $labels = [];
        $input = [];
        $refused = [];
        $warnings = [];

        foreach ($values as $value) {
            $labels[] = $value['label'];
            $input = [...$input, ...$value['input']];
            $refused = [...$refused, ...$value['refused']];
            $warnings = [...$warnings, ...$value['warnings']];
        }

        $refused = array_values(array_unique($refused));

        return new RoomSettingsCase(
            label: implode(self::LABEL_SEPARATOR, $labels),
            input: $input,
            refusedFields: $refused,
            warnings: $refused === [] ? array_values(array_unique($warnings)) : [],
        );
    }

    /**
     * Retire les valeurs répétées d'un axe (« dédupliquées », C18 § 2.7).
     *
     * Deux valeurs égales portent la même étiquette ; si leurs verdicts écrits
     * à la main divergent, c'est le générateur qui se contredit, et il le dit.
     *
     * @param  list<AxisValue>  $values
     * @return list<AxisValue>
     */
    private static function deduplicate(array $values): array
    {
        $kept = [];

        foreach ($values as $value) {
            $previous = $kept[$value['label']] ?? null;

            if ($previous === null) {
                $kept[$value['label']] = $value;

                continue;
            }

            if ($previous['refused'] !== $value['refused'] || $previous['warnings'] !== $value['warnings']) {
                throw new LogicException(
                    "Verdicts contradictoires pour la valeur [{$value['label']}] de la matrice : "
                    .'une borne a changé d\'ordre, relire les verdicts de son axe.',
                );
            }
        }

        return array_values($kept);
    }

    /**
     * @param  list<string>  $refused
     * @return AxisValue
     */
    private static function frames(int $frames, array $refused = []): array
    {
        return self::value('N'.$frames, ['framesPerRound' => $frames], $refused);
    }

    /**
     * @param  list<string>  $refused
     * @param  list<string>  $warnings
     * @return AxisValue
     */
    private static function duration(int $seconds, array $refused = [], array $warnings = []): array
    {
        return self::value('D'.$seconds, [RoomSettings::INPUT_ROUND_DURATION => $seconds], $refused, $warnings);
    }

    /**
     * @param  list<string>  $refused
     * @param  list<string>  $warnings
     * @return AxisValue
     */
    private static function reveal(int $seconds, array $refused = [], array $warnings = []): array
    {
        return self::value('R'.$seconds, ['revealDuration' => $seconds], $refused, $warnings);
    }

    /**
     * @param  list<int>  $points
     * @param  list<string>  $refused
     * @param  list<string>  $warnings
     * @return AxisValue
     */
    private static function points(string $kind, array $points, array $refused = [], array $warnings = []): array
    {
        return self::value('points:'.$kind, ['tierPoints' => $points], $refused, $warnings);
    }

    /**
     * @param  list<int>  $durations
     * @param  list<string>  $refused
     * @return AxisValue
     */
    private static function tiers(string $kind, array $durations, array $refused): array
    {
        return self::value('tiers:'.$kind, ['tierDurations' => $durations], $refused);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $refused
     * @param  list<string>  $warnings
     * @return AxisValue
     */
    private static function value(string $label, array $input, array $refused = [], array $warnings = []): array
    {
        return ['label' => $label, 'input' => $input, 'refused' => $refused, 'warnings' => $warnings];
    }
}
