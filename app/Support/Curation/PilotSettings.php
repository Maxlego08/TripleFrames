<?php

namespace App\Support\Curation;

use Illuminate\Support\Facades\Config;

/**
 * Les réglages du lot pilote — `catalog.curation.pilot.*`, spec 20 § 10.3 et
 * § 10.4 (D10 et D11 du 23/09), lus en UN endroit.
 *
 * Fixés avant le lot et jamais modifiés pendant : ils vivent en configuration
 * versionnée, jamais dans l'environnement, et `CurationConfigTest` garde leurs
 * bornes croisées — `size` est la somme des deux compositions. Aucune n'est
 * une valeur de jeu : aucune n'atteint une partie.
 *
 * Les voies sont celles de la file de curation ({@see CurationQueue::ENTRIES}) :
 * `discover` (`is_import_exception` faux) et `exception` (vrai).
 */
final readonly class PilotSettings
{
    public const int SECONDS_PER_HOUR = 3_600;

    /**
     * @param  array{discover: int, exception: int}  $composition  films terminés par voie
     * @param  array{discover: int, exception: int}  $firstRank  rang de départ par voie, à partir de 1
     */
    public function __construct(
        public array $composition,
        public int $size,
        public array $firstRank,
        public int $disqualifyHours,
        public int $j1TargetFilms,
        public int $reserveHours,
        public int $volumeCap,
        public int $weeklyCurationHours,
        public int $horizonWeeks,
    ) {}

    /** Les réglages en vigueur. */
    public static function current(): self
    {
        $int = static fn (string $key): int => Config::integer('catalog.curation.pilot.'.$key);

        return new self(
            composition: [
                CurationQueue::ENTRY_DISCOVER => $int('composition.'.CurationQueue::ENTRY_DISCOVER),
                CurationQueue::ENTRY_EXCEPTION => $int('composition.'.CurationQueue::ENTRY_EXCEPTION),
            ],
            size: $int('size'),
            firstRank: [
                CurationQueue::ENTRY_DISCOVER => $int('first_rank.'.CurationQueue::ENTRY_DISCOVER),
                CurationQueue::ENTRY_EXCEPTION => $int('first_rank.'.CurationQueue::ENTRY_EXCEPTION),
            ],
            disqualifyHours: $int('disqualify_hours'),
            j1TargetFilms: $int('j1_target_films'),
            reserveHours: $int('reserve_hours'),
            volumeCap: $int('volume_cap'),
            weeklyCurationHours: $int('weekly_curation_hours'),
            horizonWeeks: $int('horizon_weeks'),
        );
    }

    /**
     * Les heures de curation déclarées : `weekly_curation_hours ×
     * horizon_weeks`. Zéro tant que l'une des deux n'est pas déclarée.
     */
    public function declaredHours(): int
    {
        return max(0, $this->weeklyCurationHours) * max(0, $this->horizonWeeks);
    }

    /** Les heures hebdomadaires sont-elles déclarées ? Sans elles, aucune projection en semaines. */
    public function weeklyHoursDeclared(): bool
    {
        return $this->weeklyCurationHours > 0;
    }

    /** Le seuil de disqualification, en secondes de temps actif. */
    public function disqualifySeconds(): int
    {
        return $this->disqualifyHours * self::SECONDS_PER_HOUR;
    }

    /** La réserve de curation, en secondes. */
    public function reserveSeconds(): int
    {
        return $this->reserveHours * self::SECONDS_PER_HOUR;
    }

    /** Les films du jalon 1 qui restent à curer après le pilote (40 au défaut). */
    public function remainingAfterPilot(): int
    {
        return max(0, $this->j1TargetFilms - $this->size);
    }
}
