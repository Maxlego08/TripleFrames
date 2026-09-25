<?php

namespace App\Support\Curation;

use Carbon\CarbonImmutable;

/**
 * Le verdict du lot pilote — spec 20 § 10.3 et § 10.4, D10 du 23/09 appliquée
 * à la lettre. Une VALEUR, rendue par {@see ThroughputReport} dès que la
 * fenêtre stratifiée du pilote est pleine, et affichée telle quelle avec
 * l'instant où elle s'est remplie : le porteur la recopie, datée, dans le
 * compte rendu du pilote, qui fait foi.
 *
 * Le tableau de D10 :
 *
 * | Condition | Réaction |
 * |---|---|
 * | temps actif total du pilote, films écartés compris, > `disqualify_hours` | outil disqualifié |
 * | `p90 × (j1_target_films − size)` ≤ `reserve_hours` | le J1 reste à `j1_target_films` films |
 * | sinon | le J1 compte `⌊reserve_hours ÷ p90⌋` films |
 * | toujours | cible de volume `min(volume_cap, ⌊heures déclarées ÷ p90⌋)` |
 *
 * « p90 » est le p90 du temps actif par film PUBLIÉ du pilote. La seconde
 * cause de disqualification — une intervention hors du back-office sur le
 * chemin du curateur — n'est pas en base : le porteur la consigne dans le
 * compte rendu, et l'écran le rappelle.
 *
 * Toute l'arithmétique est ENTIÈRE, en secondes : aucune division par zéro,
 * aucun arrondi flottant sur un seuil. Seules les semaines de la projection
 * sont un quotient, et seulement quand les heures hebdomadaires sont
 * déclarées ({@see self::projection()}).
 *
 * @phpstan-type Projection array{
 *     p90_seconds: int|null,
 *     j1: array{films: int|null, target_kept: bool|null, seconds: int|null, weeks: float|null},
 *     volume: array{films: int|null, seconds: int|null, weeks: float|null},
 *     declared_hours: int,
 *     weekly_hours_declared: bool,
 * }
 */
final readonly class PilotVerdict
{
    /**
     * @param  Projection  $projection
     */
    private function __construct(
        public CarbonImmutable $filledAt,
        public int $films,
        public int $failures,
        public int $totalActiveSeconds,
        public int $disqualifySeconds,
        public bool $disqualified,
        public array $projection,
    ) {}

    /**
     * Le verdict d'une fenêtre pleine.
     *
     * @param  CarbonImmutable  $filledAt  la terminaison du dernier film entré dans la fenêtre
     * @param  int  $films  films de la fenêtre, écartés compris
     * @param  int  $failures  films écartés de la fenêtre : chacun compte comme un échec
     * @param  int  $totalActiveSeconds  temps actif total de la fenêtre, films écartés compris
     * @param  int|null  $p90Seconds  p90 du temps actif par film publié ; `null` sans film publié
     */
    public static function render(
        CarbonImmutable $filledAt,
        int $films,
        int $failures,
        int $totalActiveSeconds,
        ?int $p90Seconds,
        PilotSettings $settings,
    ): self {
        return new self(
            filledAt: $filledAt,
            films: $films,
            failures: $failures,
            totalActiveSeconds: $totalActiveSeconds,
            disqualifySeconds: $settings->disqualifySeconds(),
            disqualified: $totalActiveSeconds > $settings->disqualifySeconds(),
            projection: self::projection($p90Seconds, $settings),
        );
    }

    /**
     * Les cibles du jalon 1 et du volume au p90 donné, et leur projection en
     * heures (`cible × p90`) et en semaines (`heures ÷ weekly_curation_hours`)
     * — spec 20 § 10.2 et § 10.4.
     *
     * - Sans p90 (aucun film publié), aucune cible ne se calcule : `null`.
     * - Tant que les heures déclarées sont nulles, la cible de volume est
     *   `null` : l'écran l'affiche au lieu d'une cible.
     * - Tant que les heures hebdomadaires sont nulles, les semaines sont
     *   `null` : l'écran affiche `admin.throughput.hours_undeclared`, jamais un
     *   quotient.
     *
     * @return Projection
     */
    public static function projection(?int $p90Seconds, PilotSettings $settings): array
    {
        $j1Films = null;
        $targetKept = null;

        if ($p90Seconds !== null) {
            $targetKept = $p90Seconds * $settings->remainingAfterPilot() <= $settings->reserveSeconds();

            // Un p90 nul tient toujours dans la réserve : le quotient n'est
            // calculé que pour un p90 strictement positif.
            $j1Films = $targetKept
                ? $settings->j1TargetFilms
                : intdiv($settings->reserveSeconds(), max(1, $p90Seconds));
        }

        $volumeFilms = null;
        $declaredSeconds = $settings->declaredHours() * PilotSettings::SECONDS_PER_HOUR;

        if ($p90Seconds !== null && $declaredSeconds > 0) {
            $volumeFilms = $p90Seconds === 0
                ? $settings->volumeCap
                : min($settings->volumeCap, intdiv($declaredSeconds, $p90Seconds));
        }

        $j1Seconds = self::hours($j1Films, $p90Seconds);
        $volumeSeconds = self::hours($volumeFilms, $p90Seconds);

        return [
            'p90_seconds' => $p90Seconds,
            'j1' => [
                'films' => $j1Films,
                'target_kept' => $targetKept,
                'seconds' => $j1Seconds,
                'weeks' => self::weeks($j1Seconds, $settings),
            ],
            'volume' => [
                'films' => $volumeFilms,
                'seconds' => $volumeSeconds,
                'weeks' => self::weeks($volumeSeconds, $settings),
            ],
            'declared_hours' => $settings->declaredHours(),
            'weekly_hours_declared' => $settings->weeklyHoursDeclared(),
        ];
    }

    /**
     * La valeur envoyée à l'écran : des nombres et un instant ISO-8601, jamais
     * une chaîne formatée (règle 4).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filled_at' => $this->filledAt->toIso8601String(),
            'films' => $this->films,
            'failures' => $this->failures,
            'total_active_seconds' => $this->totalActiveSeconds,
            'disqualify_seconds' => $this->disqualifySeconds,
            'disqualified' => $this->disqualified,
            ...$this->projection,
        ];
    }

    /** Le temps projeté d'une cible, en secondes : `cible × p90`. */
    private static function hours(?int $films, ?int $p90Seconds): ?int
    {
        return $films === null || $p90Seconds === null ? null : $films * $p90Seconds;
    }

    /** Les semaines d'un temps projeté, ou `null` sans heures hebdomadaires déclarées. */
    private static function weeks(?int $seconds, PilotSettings $settings): ?float
    {
        if ($seconds === null || ! $settings->weeklyHoursDeclared()) {
            return null;
        }

        return (float) ($seconds / PilotSettings::SECONDS_PER_HOUR / $settings->weeklyCurationHours);
    }
}
