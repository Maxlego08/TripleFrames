<?php

namespace App\ValueObjects\Scoring;

use App\Models\Round;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use InvalidArgumentException;
use LogicException;

/**
 * Le calendrier des paliers d'une manche : `N` fenêtres contiguës qui couvrent
 * `[0, D)` (spec 80 § 4.1, contrat C13 § 2.2).
 *
 * **Deux constructeurs, jamais confondus.**
 * - {@see self::fromRound()} lit les lignes `round_tier` matérialisées au
 *   lancement : c'est la SEULE source du chemin de score, en production comme au
 *   rejeu. Le barème n'y est plus déductible de la position, et un réglage
 *   modifié après le lancement n'y change rien (§ 2.2).
 * - {@see self::fromSettings()} dérive le calendrier d'un {@see RoomSettings} :
 *   fabriques, tests et parité client seulement, jamais sur le chemin de score.
 *
 * Le constructeur refuse tout calendrier incohérent : indices `1..N` contigus,
 * premier décalage nul, chaque décalage égal au précédent plus sa durée, durées
 * strictement positives, valeurs dans `[MIN_TIER_POINTS, MAX_TIER_POINTS]`. Cette
 * dernière garde tient la borne de `guess.points_total` (1 500 au plafond, § 3.2) :
 * une ligne hors barème ferait écrire un score que le schéma n'a pas prévu.
 */
final readonly class TierSchedule
{
    /** Premier rang de palier : la plus cryptique des images. */
    private const int FIRST_TIER_INDEX = 1;

    /** Unité, pas une valeur de jeu : les durées de palier sont réglées en secondes entières. */
    private const int MILLISECONDS_PER_SECOND = 1000;

    /**
     * @param  list<TierWindow>  $tiers  Indices `1..N` contigus, `offset₁ = 0`,
     *                                   `offsetᵢ₊₁ = offsetᵢ + durationᵢ`, durées > 0.
     *
     * @throws InvalidArgumentException Calendrier vide ou incohérent.
     */
    public function __construct(public array $tiers)
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('TierSchedule : une manche compte au moins un palier.');
        }

        $expectedOffsetMs = 0;

        foreach ($tiers as $position => $tier) {
            $expectedIndex = $position + self::FIRST_TIER_INDEX;

            if ($tier->tierIndex !== $expectedIndex) {
                throw new InvalidArgumentException(sprintf(
                    'TierSchedule : palier %d reçu en position %d, indices 1..N contigus attendus.',
                    $tier->tierIndex,
                    $expectedIndex,
                ));
            }

            if ($tier->startsAtOffsetMs !== $expectedOffsetMs) {
                throw new InvalidArgumentException(sprintf(
                    'TierSchedule : le palier %d s’ouvre à %d ms, attendu %d ms.',
                    $tier->tierIndex,
                    $tier->startsAtOffsetMs,
                    $expectedOffsetMs,
                ));
            }

            if ($tier->durationMs <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'TierSchedule : le palier %d dure %d ms, une durée strictement positive est attendue.',
                    $tier->tierIndex,
                    $tier->durationMs,
                ));
            }

            if ($tier->points < RoomSettingsBounds::MIN_TIER_POINTS || $tier->points > RoomSettingsBounds::MAX_TIER_POINTS) {
                throw new InvalidArgumentException(sprintf(
                    'TierSchedule : le palier %d vaut %d points, hors de [%d, %d].',
                    $tier->tierIndex,
                    $tier->points,
                    RoomSettingsBounds::MIN_TIER_POINTS,
                    RoomSettingsBounds::MAX_TIER_POINTS,
                ));
            }

            $expectedOffsetMs += $tier->durationMs;
        }
    }

    /**
     * Les paliers matérialisés de la manche, triés par `tier_index` — seule
     * source du chemin de score.
     *
     * Relus en base à chaque appel, colonnes figées seulement : aucune relation
     * chargée, peut-être périmée, n'y entre.
     *
     * @throws InvalidArgumentException Manche sans palier matérialisé, ou paliers incohérents.
     */
    public static function fromRound(Round $round): self
    {
        $windows = [];

        $tiers = $round->tiers()
            ->orderBy('tier_index')
            ->get(['tier_index', 'starts_at_offset_ms', 'duration_ms', 'points']);

        foreach ($tiers as $tier) {
            $windows[] = TierWindow::fromRoundTier($tier);
        }

        return new self($windows);
    }

    /**
     * Le calendrier que ces réglages matérialiseraient : fabriques, tests et
     * parité client, JAMAIS le chemin de score.
     *
     * Les décalages sont cumulés depuis les durées, en millisecondes entières.
     *
     * @throws InvalidArgumentException Durées et valeurs de palier de tailles différentes.
     */
    public static function fromSettings(RoomSettings $settings): self
    {
        if (count($settings->tierDurations) !== count($settings->tierPoints)) {
            throw new InvalidArgumentException(sprintf(
                'TierSchedule : %d durées pour %d valeurs de palier.',
                count($settings->tierDurations),
                count($settings->tierPoints),
            ));
        }

        $windows = [];
        $offsetMs = 0;

        foreach ($settings->tierDurations as $position => $seconds) {
            $durationMs = $seconds * self::MILLISECONDS_PER_SECOND;

            $windows[] = new TierWindow(
                tierIndex: $position + self::FIRST_TIER_INDEX,
                startsAtOffsetMs: $offsetMs,
                durationMs: $durationMs,
                points: $settings->tierPoints[$position],
            );

            $offsetMs += $durationMs;
        }

        return new self($windows);
    }

    /** `N`, le nombre de paliers. */
    public function count(): int
    {
        return count($this->tiers);
    }

    /** `D`, en millisecondes : la fin du dernier palier. */
    public function durationMs(): int
    {
        $last = $this->tier($this->count());

        return $last->startsAtOffsetMs + $last->durationMs;
    }

    /**
     * @throws InvalidArgumentException Rang hors de `1..N`.
     */
    public function tier(int $tierIndex): TierWindow
    {
        return $this->tiers[$tierIndex - self::FIRST_TIER_INDEX]
            ?? throw new InvalidArgumentException(sprintf(
                'TierSchedule : aucun palier %d dans une manche à %d paliers.',
                $tierIndex,
                $this->count(),
            ));
    }

    /**
     * Le palier dont la fenêtre contient le décalage.
     *
     * @throws InvalidArgumentException Décalage hors de `[0, D)`.
     */
    public function containing(int $offsetMs): TierWindow
    {
        if ($offsetMs < 0 || $offsetMs >= $this->durationMs()) {
            throw new InvalidArgumentException(sprintf(
                'TierSchedule : %d ms hors de [0, %d).',
                $offsetMs,
                $this->durationMs(),
            ));
        }

        foreach ($this->tiers as $tier) {
            if ($tier->contains($offsetMs)) {
                return $tier;
            }
        }

        throw new LogicException('TierSchedule : un calendrier contigu couvre tout [0, D).');
    }
}
