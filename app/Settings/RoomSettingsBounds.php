<?php

namespace App\Settings;

use App\Enums\InputDifficulty;

/**
 * Bornes et défauts des réglages de salon — un seul endroit, côté serveur.
 *
 * Consommé par le validateur de {@see RoomSettings} ET partagé en prop Inertia,
 * pour que les curseurs du client affichent exactement les mêmes min/max. Aucune
 * de ces valeurs n'est jamais recopiée dans une migration ni dans un FormRequest :
 * une seconde source de vérité qu'aucune migration additive ne pourrait
 * resynchroniser (règle 2).
 *
 * La liste est CLOSE : chacun des seize champs de {@see RoomSettings} a son défaut
 * déclaré ici. Un champ sans défaut rendrait `RoomSettings::normalize()` incapable
 * de traiter le cas « champ ajouté = défaut », et `speedBonus` comme
 * `noRepeatMovies`, qui n'ont aucune colonne de projection pour les rattraper,
 * partiraient indéfinis sur tout salon créé sans preset.
 *
 * Les bornes CROISÉES vivent ici aussi, parce qu'un FormRequest champ par champ
 * laisserait passer 10 s × 5 images :
 *   1. `D ≥ 5 s × N`  ({@see self::minRoundDuration()})
 *   2. chaque `dᵢ ≥ 5 s` et `Σ dᵢ = D` dans les bornes de `D`
 *   4. `R` face au préchargement ({@see self::RECOMMENDED_MIN_REVEAL_DURATION}, avertissement)
 *   5. avertissements cumulables ({@see self::LONG_ROUND_WARNING_DURATION})
 * La borne croisée 3 (`pool(thèmes, N) ≥ M`) n'est pas ici : elle dépend du
 * catalogue, que le value object ne voit pas, et reste une garde de lancement dédiée.
 */
final readonly class RoomSettingsBounds
{
    /** `M` — nombre de manches. */
    public const int MIN_ROUNDS_COUNT = 3;

    public const int MAX_ROUNDS_COUNT = 30;

    public const int DEFAULT_ROUNDS_COUNT = 10;

    /** `N` — nombre d'images par manche. Jamais un enum : un entier nu. */
    public const int MIN_FRAMES_PER_ROUND = 2;

    public const int MAX_FRAMES_PER_ROUND = 5;

    public const int DEFAULT_FRAMES_PER_ROUND = 3;

    /** `D` — durée d'une manche, en secondes. Dérivée : `D = Σ dᵢ`, jamais un champ. */
    public const int MIN_ROUND_DURATION = 10;

    public const int MAX_ROUND_DURATION = 120;

    public const int DEFAULT_ROUND_DURATION = 30;

    /** `dᵢ` — durée d'un palier, en secondes. Borne croisée 1 et 2. */
    public const int MIN_TIER_DURATION = 5;

    /** `R` — durée de la révélation, en secondes. */
    public const int MIN_REVEAL_DURATION = 3;

    public const int MAX_REVEAL_DURATION = 20;

    public const int DEFAULT_REVEAL_DURATION = 8;

    /** Borne croisée 4 : 3 s reste légal, 5 s sont recommandés (avertissement). */
    public const int RECOMMENDED_MIN_REVEAL_DURATION = 5;

    /** Valeur d'un palier, en points. */
    public const int MIN_TIER_POINTS = 0;

    public const int MAX_TIER_POINTS = 1000;

    /** Unité du barème par défaut : valeur du palier `i` = `(N − i + 1) × 100`. */
    public const int TIER_POINTS_UNIT = 100;

    /** Capacité du salon. La borne haute est le plafond de plateforme. */
    public const int MIN_CAPACITY = 2;

    /** Cadence maximale de la saisie libre. */
    public const int MIN_ATTEMPTS_PER_SECOND = 1;

    public const int MAX_ATTEMPTS_PER_SECOND = 5;

    public const int DEFAULT_ATTEMPTS_PER_SECOND = 1;

    /** Plafond absolu de tentatives en texte libre, par manche. */
    public const int MIN_ATTEMPTS_PER_ROUND = 5;

    public const int MAX_ATTEMPTS_PER_ROUND = 50;

    /** Défaut : `ceil(D / 2 s)`, plafonné à 30. */
    public const int ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT = 2;

    public const int ATTEMPTS_PER_ROUND_SOFT_CAP = 30;

    /**
     * Longueur maximale d'une réponse, en caractères.
     *
     * La borne haute est aussi la longueur de toute colonne de forme normalisée
     * (`string(200)`, § 1.3) : aucune chaîne saisissable n'est jamais tronquée.
     */
    public const int MIN_ANSWER_LENGTH = 20;

    public const int MAX_ANSWER_LENGTH = 200;

    public const int DEFAULT_ANSWER_LENGTH = 100;

    /**
     * Délai de grâce de déconnexion, en secondes — réglage d'HÔTE.
     *
     * Jamais la fenêtre de frontière de palier (`PlatformLimits::tierGraceMs()`,
     * 300 ms) : quatre ordres de grandeur les séparent, et un développeur qui
     * lirait ce réglage dans le calcul du palier donnerait à l'hôte le contrôle
     * du barème.
     */
    public const int MIN_DISCONNECT_GRACE_SECONDS = 15;

    public const int MAX_DISCONNECT_GRACE_SECONDS = 180;

    public const int DEFAULT_DISCONNECT_GRACE_SECONDS = 60;

    /** Borne croisée 5 : au-delà, le joueur verrouillé attend (avertissement). */
    public const int LONG_ROUND_WARNING_DURATION = 60;

    public const bool DEFAULT_ALLOW_LATE_JOIN = false;

    public const bool DEFAULT_SPEED_BONUS = true;

    public const bool DEFAULT_NO_REPEAT_MOVIES = true;

    public const bool DEFAULT_ADVANCED = false;

    public const InputDifficulty DEFAULT_INPUT_DIFFICULTY = InputDifficulty::Normal;

    /**
     * Défaut de `themeIds` : liste vide, c'est-à-dire tout le catalogue.
     *
     * C'est la branche la plus fréquente du lobby, et celle du jalon 1 où le
     * sélecteur de thèmes est masqué tant que le vivier est sous son seuil.
     *
     * @return list<int>
     */
    public static function defaultThemeIds(): array
    {
        return [];
    }

    public static function minCapacity(): int
    {
        return self::MIN_CAPACITY;
    }

    public static function maxCapacity(): int
    {
        return PlatformLimits::roomSeats();
    }

    public static function defaultCapacity(): int
    {
        return PlatformLimits::roomSeats();
    }

    /**
     * Durée minimale de `D` pour un `N` donné — borne croisée 1, `D ≥ 5 s × N`.
     *
     * Sans elle, 10 s × 5 images = 2 s par palier : avec une tolérance de ±300 ms,
     * les quatre bascules couvrent 24 % de la manche et le palier attribué devient
     * du hasard réseau.
     */
    public static function minRoundDuration(int $framesPerRound): int
    {
        return max(
            self::MIN_ROUND_DURATION,
            self::MIN_TIER_DURATION * self::clampFramesPerRound($framesPerRound),
        );
    }

    /**
     * Durée maximale d'un palier pour un `N` donné : les `N − 1` autres paliers
     * doivent encore tenir leurs 5 secondes sous le plafond de `D`.
     */
    public static function maxTierDuration(int $framesPerRound): int
    {
        return self::MAX_ROUND_DURATION
            - self::MIN_TIER_DURATION * (self::clampFramesPerRound($framesPerRound) - 1);
    }

    /**
     * Paliers de durée égale, en secondes entières, le DERNIER absorbant le reste.
     *
     * 40 s / 3 → 13 / 13 / 14. C'est le découpage de l'onglet Simple, et celui que
     * la réégalisation rejoue quand `N` change.
     *
     * @return list<int>
     */
    public static function defaultTierDurations(int $framesPerRound, int $roundDuration): array
    {
        $count = self::clampFramesPerRound($framesPerRound);
        $duration = self::clamp(
            $roundDuration,
            self::minRoundDuration($count),
            self::MAX_ROUND_DURATION,
        );

        $base = intdiv($duration, $count);
        $durations = array_fill(0, $count - 1, $base);
        $durations[] = $duration - $base * ($count - 1);

        return $durations;
    }

    /**
     * Barème par défaut : valeur du palier `i` = `(N − i + 1) × 100`.
     *
     * @return list<int>
     */
    public static function defaultTierPoints(int $framesPerRound): array
    {
        $count = self::clampFramesPerRound($framesPerRound);
        $points = [];

        for ($index = 1; $index <= $count; $index++) {
            $points[] = ($count - $index + 1) * self::TIER_POINTS_UNIT;
        }

        return $points;
    }

    /**
     * Défaut de `attemptsPerRound` : `ceil(D / 2 s)`, plafonné à 30, puis borné.
     *
     * Un plafond fixe serait mort à `D = 10 s` (la cadence en autorise 10) et
     * punitif à `D = 120 s`.
     */
    public static function defaultAttemptsPerRound(int $roundDuration): int
    {
        $attempts = (int) ceil($roundDuration / self::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT);

        return self::clamp(
            min($attempts, self::ATTEMPTS_PER_ROUND_SOFT_CAP),
            self::MIN_ATTEMPTS_PER_ROUND,
            self::MAX_ATTEMPTS_PER_ROUND,
        );
    }

    /**
     * Bornes exposées au client, en données — jamais en chaînes pré-formatées.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public static function toArray(int $framesPerRound = self::DEFAULT_FRAMES_PER_ROUND): array
    {
        $count = self::clampFramesPerRound($framesPerRound);

        return [
            'roundsCount' => ['min' => self::MIN_ROUNDS_COUNT, 'max' => self::MAX_ROUNDS_COUNT],
            'framesPerRound' => ['min' => self::MIN_FRAMES_PER_ROUND, 'max' => self::MAX_FRAMES_PER_ROUND],
            'roundDuration' => ['min' => self::minRoundDuration($count), 'max' => self::MAX_ROUND_DURATION],
            'tierDuration' => ['min' => self::MIN_TIER_DURATION, 'max' => self::maxTierDuration($count)],
            'tierPoints' => ['min' => self::MIN_TIER_POINTS, 'max' => self::MAX_TIER_POINTS],
            'revealDuration' => ['min' => self::MIN_REVEAL_DURATION, 'max' => self::MAX_REVEAL_DURATION],
            'capacity' => ['min' => self::minCapacity(), 'max' => self::maxCapacity()],
            'attemptsPerSecond' => ['min' => self::MIN_ATTEMPTS_PER_SECOND, 'max' => self::MAX_ATTEMPTS_PER_SECOND],
            'attemptsPerRound' => ['min' => self::MIN_ATTEMPTS_PER_ROUND, 'max' => self::MAX_ATTEMPTS_PER_ROUND],
            'maxAnswerLength' => ['min' => self::MIN_ANSWER_LENGTH, 'max' => self::MAX_ANSWER_LENGTH],
            'disconnectGraceSeconds' => ['min' => self::MIN_DISCONNECT_GRACE_SECONDS, 'max' => self::MAX_DISCONNECT_GRACE_SECONDS],
        ];
    }

    /**
     * Écrêtage d'un entier dans un intervalle fermé — le geste de la normalisation
     * « borne resserrée = écrêtage ».
     */
    public static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    public static function clampFramesPerRound(int $framesPerRound): int
    {
        return self::clamp($framesPerRound, self::MIN_FRAMES_PER_ROUND, self::MAX_FRAMES_PER_ROUND);
    }
}
