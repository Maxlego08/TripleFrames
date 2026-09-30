<?php

namespace App\Support\Draw;

use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Les entrées figées d'un tirage (spec 30 § 6.2, contrat C3) : tout ce que
 * {@see GameDrawer::drawFrom()} lit, et rien d'autre.
 *
 * `drawFrom` est une fonction pure de (graine, `DrawInput`) — ni base, ni
 * configuration, ni horloge (§ 6.5) : la marge est lue dans `PlatformLimits` par
 * {@see GameDrawer::draw()}, jamais par `drawFrom`, et la mémoire du salon est
 * figée ici, dans `memorySince` et les `lastSeenAt` des variantes. C'est ce qui
 * rend le test de rejeu de la spec 10 § 7.2 formulable **à entrées figées**,
 * alors que la curation et la purge changent le vivier d'un jour à l'autre.
 *
 * Gardes du constructeur, jamais d'écrêtage (un tirage faussé en silence est
 * exactement ce que la matérialisation existe pour rendre rejouable) :
 * candidats triés par `movieId` strictement croissant, `N` et `M` dans les
 * bornes de {@see RoomSettingsBounds} (le lancement normalise les réglages avant
 * de tirer), marge dans `[0, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN]` — ce
 * qui garde `K = min(M + marge, W)` dans `round.sequence_index`.
 *
 * Jamais sérialisé (§ 5.5, règle 3).
 */
final readonly class DrawInput
{
    /**
     * @param  list<PoolCandidate>  $candidates  Le vivier, par `movieId` strictement croissant ({@see PoolQuery::candidates()}).
     * @param  array<int, list<VariantCandidate>>  $variantsByMovie  `movie.id` → ses variantes jouables, tous niveaux.
     * @param  CarbonImmutable|null  $memorySince  Borne basse de la mémoire du salon ; nulle sans salon (solo, catalogue).
     * @param  int  $framesPerRound  `N`.
     * @param  int  $roundsCount  `M`.
     * @param  int  $margin  Manches de réserve au-delà de `M`, en œuvres.
     *
     * @throws InvalidArgumentException Une entrée viole un invariant.
     */
    public function __construct(
        public array $candidates,
        public array $variantsByMovie,
        public ?CarbonImmutable $memorySince,
        public int $framesPerRound,
        public int $roundsCount,
        public int $margin,
    ) {
        self::assertBetween(
            'frames_per_round',
            $framesPerRound,
            RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
            RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        );
        self::assertBetween(
            'rounds_count',
            $roundsCount,
            RoomSettingsBounds::MIN_ROUNDS_COUNT,
            RoomSettingsBounds::MAX_ROUNDS_COUNT,
        );
        self::assertBetween('margin', $margin, 0, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN);

        $previous = null;

        foreach ($candidates as $candidate) {
            if ($previous !== null && $candidate->movieId <= $previous) {
                throw new InvalidArgumentException(
                    'DrawInput : les candidats doivent être triés par movieId strictement croissant.',
                );
            }

            $previous = $candidate->movieId;
        }
    }

    /**
     * @throws InvalidArgumentException `$value` hors de `[$min, $max]`.
     */
    private static function assertBetween(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf(
                'DrawInput : %s = %d hors de [%d, %d], jamais écrêté.',
                $name,
                $value,
                $min,
                $max,
            ));
        }
    }
}
