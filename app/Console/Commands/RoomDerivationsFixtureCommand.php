<?php

namespace App\Console\Commands;

use App\Enums\SettingPresetKey;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `room:derivations-fixture` — écrit `tests/Fixtures/room/derivations.json`,
 * le jeu de dérivations partagé entre le serveur et le client (spec 50
 * § 4.3).
 *
 * Le retour immédiat de l'onglet Simple (`resources/js/lib/room-settings.ts`)
 * redit côté client le découpage égal, le barème par défaut,
 * `attemptsPerRound` par défaut et les avertissements, calculés **uniquement**
 * depuis `RoomSettingsBounds::toClient()`. Le serveur reste seul juge ; ce jeu
 * prouve que les deux côtés rendent les mêmes valeurs :
 * `RoomSettingsDerivationParityTest` (Pest) les exige du serveur,
 * `room-settings.test.ts` (Vitest) du client. Une divergence casse l'un des
 * deux. **Aucun test n'écrit le fichier** : un test qui le régénérerait avant
 * de le comparer serait tautologique.
 *
 * La commande n'est lancée **qu'à la main**, après un changement voulu de
 * {@see RoomSettingsBounds}, et son diff se relit dans la revue. Elle n'est
 * enregistrée qu'hors de l'environnement `production` ({@see self::isEnabled()}).
 * `--check` compare sans rien écrire.
 *
 * Forme du fichier, des entiers et des codes seulement :
 *
 * ```
 * { bounds: RoomSettingsBounds::toClient(),
 *   speedBonusMaxPercent: PlatformLimits::toArray()['speedBonusMaxPercent'],
 *   cases: [{ n, d, r, tierDurations, tierPoints, attemptsPerRound, warnings }],
 *   warningCases: [{ revealDuration, speedBonus, tierDurations, tierPoints, warnings }] }
 * ```
 *
 * `speedBonusMaxPercent` (prop `limits` du lobby, jamais dans les bornes)
 * sert au seul avertissement `waiting_pays` (L50-10), qui dépend de `B_max(N)`
 * et de l'interrupteur `speedBonus`.
 *
 * - `cases` : une entrée Simple `(N, D, R)` passée par le chemin de l'hôte
 *   ({@see RoomSettingsEditor::simple()} depuis les défauts, puis
 *   {@see RoomSettingsEditor::toSettings()}), et ce que le serveur en dérive.
 *   Chaque `N` des bornes, chaque reste de `D` modulo `N` depuis son minimum,
 *   les deux côtés des seuils (plafond de `attemptsPerRound`, manche longue),
 *   les bornes de `D`, les durées des presets ; puis les révélations autour du
 *   seuil recommandé.
 * - `warningCases` : des barèmes et des paliers inégaux que l'onglet Simple
 *   ne pose pas (onglet Avancé), passés par {@see RoomSettings::fromInput()},
 *   bonus actif ou coupé, pour que les cinq avertissements soient éprouvés
 *   des deux côtés.
 *
 * Mis en forme comme oxfmt le rendrait (quatre espaces, 80 colonnes) : un
 * fichier qu'oxfmt réécrirait ferait échouer `vp check`.
 */
class RoomDerivationsFixtureCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'room:derivations-fixture {--check : Échoue si le fichier versionné diffère de ce que le serveur rend, sans rien écrire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Écrit tests/Fixtures/room/derivations.json, le jeu de dérivations des réglages partagé avec le client';

    /** Chemin du jeu partagé, relatif à la racine du dépôt. */
    public const string TARGET = 'tests/Fixtures/room/derivations.json';

    /** Largeur de ligne d'oxfmt (`vite.config.ts` › `fmt.printWidth`). */
    private const int PRINT_WIDTH = 80;

    /** Indentation d'oxfmt (`vite.config.ts` › `fmt.tabWidth`). */
    private const string INDENT = '    ';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Jamais en production : la commande écrit sous `tests/`, qui n'y existe
     * pas, et n'a de sens que sur un poste de développement.
     */
    public function isEnabled(): bool
    {
        return $this->laravel->environment('production') !== true;
    }

    public function handle(): int
    {
        $path = base_path(self::TARGET);
        $rendered = self::render();

        if ($this->option('check')) {
            if (File::exists($path) && File::get($path) === $rendered) {
                $this->components->info(self::TARGET.' est à jour.');

                return self::SUCCESS;
            }

            $this->components->error(self::TARGET.' est périmé : relancez `php artisan room:derivations-fixture` et relisez le diff.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $rendered);

        $this->components->info(self::TARGET.' écrit.');

        return self::SUCCESS;
    }

    /**
     * Le document entier, mis en forme, terminé par un saut de ligne.
     */
    public static function render(): string
    {
        return self::member(null, self::document(), 0, true)."\n";
    }

    /**
     * @return array{bounds: array<string, mixed>, speedBonusMaxPercent: array<int, int>, cases: list<array<string, mixed>>, warningCases: list<array<string, mixed>>}
     */
    public static function document(): array
    {
        return [
            'bounds' => RoomSettingsBounds::toClient(),
            'speedBonusMaxPercent' => PlatformLimits::current()->toArray()['speedBonusMaxPercent'],
            'cases' => self::cases(),
            'warningCases' => self::warningCases(),
        ];
    }

    /**
     * Entrées Simple `(N, D, R)` et leurs dérivés, dans l'ordre de `N`, puis de
     * `D`, puis de `R`.
     *
     * @return list<array<string, mixed>>
     */
    private static function cases(): array
    {
        $cases = [];

        for ($frames = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $frames <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $frames++) {
            foreach (self::roundDurationsFor($frames) as $duration) {
                $cases[] = self::simpleCase($frames, $duration, RoomSettingsBounds::DEFAULT_REVEAL_DURATION);
            }
        }

        foreach (self::revealDurations() as $reveal) {
            if ($reveal !== RoomSettingsBounds::DEFAULT_REVEAL_DURATION) {
                $cases[] = self::simpleCase(
                    RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
                    RoomSettingsBounds::DEFAULT_ROUND_DURATION,
                    $reveal,
                );
            }
        }

        return $cases;
    }

    /**
     * Les `D` éprouvés pour un `N` : chaque reste modulo `N` et chaque parité
     * depuis le minimum effectif, les deux côtés du plafond de
     * `attemptsPerRound` et de la manche longue, le défaut, les bornes et les
     * durées des presets.
     *
     * @return list<int>
     */
    private static function roundDurationsFor(int $framesPerRound): array
    {
        $min = RoomSettingsBounds::minRoundDuration($framesPerRound);
        $softCapDuration = RoomSettingsBounds::ATTEMPTS_PER_ROUND_SOFT_CAP
            * RoomSettingsBounds::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT;
        $longRound = RoomSettingsBounds::LONG_ROUND_WARNING_DURATION;

        $durations = [
            ...range($min, $min + $framesPerRound),
            RoomSettingsBounds::DEFAULT_ROUND_DURATION - 1,
            RoomSettingsBounds::DEFAULT_ROUND_DURATION,
            RoomSettingsBounds::DEFAULT_ROUND_DURATION + 1,
            $softCapDuration - RoomSettingsBounds::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT,
            $softCapDuration - 1,
            $softCapDuration,
            $softCapDuration + 1,
            $longRound - 1,
            $longRound,
            $longRound + 1,
            RoomSettingsBounds::MAX_ROUND_DURATION - 1,
            RoomSettingsBounds::MAX_ROUND_DURATION,
        ];

        foreach (SettingPresetKey::cases() as $key) {
            $durations[] = SettingPresetCatalog::settingsFor($key)->roundDuration();
        }

        $durations = array_values(array_unique(array_filter(
            $durations,
            static fn (int $duration): bool => $duration >= $min && $duration <= RoomSettingsBounds::MAX_ROUND_DURATION,
        )));
        sort($durations);

        return $durations;
    }

    /**
     * Les `R` éprouvés : les bornes, le défaut et les deux côtés du seuil
     * recommandé.
     *
     * @return list<int>
     */
    private static function revealDurations(): array
    {
        $reveals = array_values(array_unique([
            RoomSettingsBounds::MIN_REVEAL_DURATION,
            RoomSettingsBounds::RECOMMENDED_MIN_REVEAL_DURATION - 1,
            RoomSettingsBounds::RECOMMENDED_MIN_REVEAL_DURATION,
            RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
            RoomSettingsBounds::MAX_REVEAL_DURATION,
        ]));
        sort($reveals);

        return $reveals;
    }

    /**
     * Une entrée de l'onglet Simple, composée depuis les réglages par défaut
     * comme le fait l'écriture de l'hôte (règle D34 du 23/09).
     *
     * @return array<string, mixed>
     */
    private static function simpleCase(int $framesPerRound, int $roundDuration, int $revealDuration): array
    {
        $composed = RoomSettingsEditor::simple(RoomSettings::defaults(), [
            'framesPerRound' => $framesPerRound,
            RoomSettings::INPUT_ROUND_DURATION => $roundDuration,
            'revealDuration' => $revealDuration,
        ], []);
        $settings = RoomSettingsEditor::toSettings($composed['input']);

        return [
            'n' => $framesPerRound,
            'd' => $roundDuration,
            'r' => $revealDuration,
            'tierDurations' => $settings->tierDurations,
            'tierPoints' => $settings->tierPoints,
            'attemptsPerRound' => $settings->attemptsPerRound,
            'warnings' => $settings->warnings(),
        ];
    }

    /**
     * Barèmes et paliers de l'onglet Avancé : pour les `N` extrêmes et par
     * défaut, un barème par défaut, plat au maximum, croissant, à égalité en
     * fin, à zéro en fin seulement, entièrement à zéro, strictement
     * décroissant mais qui fait payer l'attente — bonus actif, puis le même
     * bonus coupé — ; puis des paliers inégaux qui dépassent la manche longue,
     * et un cas qui lève quatre avertissements.
     *
     * @return list<array<string, mixed>>
     */
    private static function warningCases(): array
    {
        $cases = [];
        $frameCounts = array_values(array_unique([
            RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
            RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
            RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        ]));

        foreach ($frameCounts as $frames) {
            $durations = RoomSettingsBounds::defaultTierDurations($frames, RoomSettingsBounds::DEFAULT_ROUND_DURATION);

            foreach (self::pointPatterns($frames) as $points) {
                $cases[] = self::warningCase(RoomSettingsBounds::DEFAULT_REVEAL_DURATION, $durations, $points);
            }

            $cases[] = self::warningCase(
                RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
                $durations,
                self::waitingPaysPoints($frames),
                speedBonus: false,
            );
        }

        $frames = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
        $shortTiers = array_fill(0, $frames - 1, RoomSettingsBounds::MIN_TIER_DURATION);
        $lastTier = RoomSettingsBounds::LONG_ROUND_WARNING_DURATION + 1 - RoomSettingsBounds::MIN_TIER_DURATION * ($frames - 1);

        $cases[] = self::warningCase(
            RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
            [...$shortTiers, $lastTier],
            RoomSettingsBounds::defaultTierPoints($frames),
        );
        $cases[] = self::warningCase(
            RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
            [...$shortTiers, $lastTier - 1],
            RoomSettingsBounds::defaultTierPoints($frames),
        );
        $cases[] = self::warningCase(
            RoomSettingsBounds::MIN_REVEAL_DURATION,
            RoomSettingsBounds::defaultTierDurations($frames, RoomSettingsBounds::MAX_ROUND_DURATION),
            array_fill(0, $frames, RoomSettingsBounds::MIN_TIER_POINTS),
        );

        return $cases;
    }

    /**
     * @return list<list<int>>
     */
    private static function pointPatterns(int $framesPerRound): array
    {
        $default = RoomSettingsBounds::defaultTierPoints($framesPerRound);
        $allButLast = array_slice($default, 0, -1);

        return [
            $default,
            array_fill(0, $framesPerRound, RoomSettingsBounds::MAX_TIER_POINTS),
            array_reverse($default),
            [...$allButLast, $allButLast[count($allButLast) - 1]],
            [...$allButLast, RoomSettingsBounds::MIN_TIER_POINTS],
            array_fill(0, $framesPerRound, RoomSettingsBounds::MIN_TIER_POINTS),
            self::waitingPaysPoints($framesPerRound),
        ];
    }

    /**
     * Le barème par défaut, son deuxième palier ramené juste sous le premier :
     * strictement décroissant, donc sans `non_decreasing_points`, mais le
     * bonus du deuxième palier fait payer l'attente (`waiting_pays`, spec 80
     * § 3.4).
     *
     * @return list<int>
     */
    private static function waitingPaysPoints(int $framesPerRound): array
    {
        $points = RoomSettingsBounds::defaultTierPoints($framesPerRound);

        return [$points[0], $points[0] - 1, ...array_slice($points, 2)];
    }

    /**
     * @param  list<int>  $tierDurations
     * @param  list<int>  $tierPoints
     * @return array<string, mixed>
     */
    private static function warningCase(int $revealDuration, array $tierDurations, array $tierPoints, bool $speedBonus = RoomSettingsBounds::DEFAULT_SPEED_BONUS): array
    {
        $settings = RoomSettings::fromInput([
            'framesPerRound' => count($tierPoints),
            'tierDurations' => $tierDurations,
            'tierPoints' => $tierPoints,
            'revealDuration' => $revealDuration,
            'speedBonus' => $speedBonus,
        ]);

        return [
            'revealDuration' => $revealDuration,
            'speedBonus' => $settings->speedBonus,
            'tierDurations' => $settings->tierDurations,
            'tierPoints' => $settings->tierPoints,
            'warnings' => $settings->warnings(),
        ];
    }

    /**
     * Une valeur, précédée de sa clé, à la profondeur donnée : sur une ligne
     * si elle y tient, dépliée sinon — la règle d'oxfmt pour le JSON. Un objet
     * autre qu'un intervalle `{ min, max }` est toujours déplié (oxfmt garde
     * déplié un objet écrit déplié), une liste d'objets aussi.
     */
    private static function member(?string $key, mixed $value, int $depth, bool $last): string
    {
        $prefix = str_repeat(self::INDENT, $depth).($key === null ? '' : self::scalar($key).': ');
        $suffix = $last ? '' : ',';
        $inline = self::inline($value);

        if ($inline !== null && strlen($prefix.$inline.$suffix) <= self::PRINT_WIDTH) {
            return $prefix.$inline.$suffix;
        }

        if (! is_array($value)) {
            return $prefix.self::scalar($value).$suffix;
        }

        $isList = array_is_list($value);
        $lines = [];
        $index = 0;
        $count = count($value);

        foreach ($value as $childKey => $child) {
            $index++;
            $lines[] = self::member($isList ? null : (string) $childKey, $child, $depth + 1, $index === $count);
        }

        [$open, $close] = $isList ? ['[', ']'] : ['{', '}'];

        return $prefix.$open."\n".implode("\n", $lines)."\n".str_repeat(self::INDENT, $depth).$close.$suffix;
    }

    /**
     * La forme sur une ligne d'une valeur, ou `null` si elle se déplie
     * toujours.
     */
    private static function inline(mixed $value): ?string
    {
        if (! is_array($value)) {
            return self::scalar($value);
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    return null;
                }
            }

            return '['.implode(', ', array_map(self::scalar(...), $value)).']';
        }

        if (array_keys($value) === ['min', 'max']) {
            return '{ '.self::scalar('min').': '.self::scalar($value['min']).', '
                .self::scalar('max').': '.self::scalar($value['max']).' }';
        }

        return null;
    }

    private static function scalar(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
