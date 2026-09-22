<?php

namespace Database\Factories;

use App\Enums\FrameLevel;
use App\Enums\RoundIncidentReason;
use App\Models\Frame;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundTier} — le palier matérialisé : une image, un
 * instant, une durée, une valeur (§ 7.4).
 *
 * **`serve_token` est NULL tant que le palier n'est pas ouvert**, et c'est la
 * propriété la plus facile à casser ici. Le jeton est frappé *à l'ouverture du
 * palier*, dans la même transaction que `served_frame_id` et `served_at`, par la
 * transition serveur et par elle seule — jamais par la route de service d'image,
 * qui est en lecture seule sans exception. Un défaut de fabrique qui le
 * remplirait rendrait vert le test nommé du § 7.4 (« après `N` requêtes d'image
 * anticipées et aucune frontière franchie, `served_at` est nul ») sur un montage
 * qui le viole. Les quatre colonnes ne sont donc écrites que par
 * {@see self::served()} et {@see self::substituted()}.
 *
 * **Aucune colonne `opened_at` n'existe** : l'instant d'ouverture se calcule par
 * `round.started_at + starts_at_offset_ms`, de sorte qu'un job de frontière en
 * retard décale un affichage et jamais un score. `starts_at_offset_ms`,
 * `duration_ms` et `points` sont donc figés au lancement et proviennent du même
 * {@see RoomSettings} que `round.duration_ms` — c'est ce qui tient l'invariant
 * `SUM(duration_ms) = round.duration_ms` quand on matérialise les `N` paliers.
 *
 * `frame_id` reste **nul** par défaut : la colonne est nullable par conception
 * (`nullOnDelete`, seul endroit du domaine où une perte catalogue est tolérée), et
 * une fabrique qui tirerait une `Frame` par palier entraînerait tout le
 * catalogue — film, projection, revue et octets réels sur disque — pour un test
 * qui n'en veut pas. {@see self::forFrame()} l'attache quand c'est le sujet.
 *
 * @extends Factory<RoundTier>
 */
class RoundTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Le premier palier d'une manche à `N = 3` sur les défauts du site : 10 s,
     * 300 points, ouvert à `t = 0`, **non encore servi**.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $settings = RoomSettings::defaults();

        return array_merge([
            'round_id' => Round::factory(),
            'frame_id' => null,
        ], self::tier(1, null, $settings), [
            'serve_token' => null,
            'served_frame_id' => null,
            'served_at' => null,
            'substitution_reason' => null,
        ]);
    }

    /**
     * Palier `i` : rang, niveau nominal, instant d'ouverture, durée et valeur,
     * tous recalculés ensemble depuis les réglages.
     *
     * Passer explicitement un {@see FrameLevel} est le cas du **repli de niveau**,
     * où le niveau servi n'est pas le niveau nominal.
     */
    public function atTier(int $tierIndex, ?FrameLevel $frameLevel = null, ?RoomSettings $settings = null): static
    {
        return $this->state(fn (array $attributes): array => self::tier($tierIndex, $frameLevel, $settings));
    }

    /**
     * Variante tirée et figée au lancement — `frame_level` est dénormalisé depuis
     * elle, ce qui garde le journal lisible si la frame disparaît.
     */
    public function forFrame(Frame $frame): static
    {
        return $this->state(fn (array $attributes): array => [
            'frame_id' => $frame->id,
            'frame_level' => $frame->frame_level,
        ]);
    }

    /**
     * Palier OUVERT : les quatre colonnes de service écrites ensemble, comme la
     * transition serveur les écrit — exactement une fois.
     *
     * `serve_token` est un `bin2hex(random_bytes(16))` lié à la **manche** et non à
     * la frame : deux manches portant la même image produisent deux jetons
     * différents, donc aucune paire (identifiant → titre) apprise en solo n'est
     * réutilisable ailleurs (§ 7.10).
     *
     * Sans `Frame`, seuls le jeton et l'instant sont posés : c'est le palier ouvert
     * dont on ne veut pas fabriquer l'image.
     */
    public function served(?Frame $frame = null): static
    {
        return $this->state(function (array $attributes) use ($frame): array {
            $state = [
                'serve_token' => bin2hex(random_bytes(16)),
                'served_at' => now(),
            ];

            if ($frame !== null) {
                $state['frame_id'] = $frame->id;
                $state['frame_level'] = $frame->frame_level;
                $state['served_frame_id'] = $frame->id;
            }

            return $state;
        });
    }

    /**
     * Substitution : la variante affichée n'est pas celle qui avait été tirée.
     * `substitution_reason` n'est non nulle que dans ce cas, et c'est
     * `served_frame_id` — jamais `frame_id` — que `seen_frame` enregistre.
     */
    public function substituted(
        Frame $servedFrame,
        RoundIncidentReason $reason = RoundIncidentReason::FrameUnavailable,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'serve_token' => bin2hex(random_bytes(16)),
            'served_frame_id' => $servedFrame->id,
            'served_at' => now(),
            'substitution_reason' => $reason,
        ]);
    }

    /**
     * Les cinq colonnes figées d'un palier, calculées ensemble.
     *
     * @return array<string, mixed>
     */
    private static function tier(int $tierIndex, ?FrameLevel $frameLevel, ?RoomSettings $settings): array
    {
        $settings ??= RoomSettings::defaults();
        $durations = $settings->tierDurations;
        $points = $settings->tierPoints;

        return [
            'tier_index' => $tierIndex,
            'frame_level' => $frameLevel ?? self::nominalLevel($tierIndex, $settings->framesPerRound),
            'starts_at_offset_ms' => $settings->tierStartOffsetMs($tierIndex),
            'duration_ms' => ($durations[$tierIndex - 1] ?? 0) * 1000,
            'points' => $points[$tierIndex - 1] ?? 0,
        ];
    }

    /**
     * Niveau NOMINAL du palier `i` pour un `N` donné.
     *
     * > **Duplication nommée, et temporaire.** L'échantillonnage des niveaux —
     * > `N=2 → 1,5` · `N=3 → 1,3,5` · `N=4 → 1,2,4,5` · `N=5 → 1..5` — n'a qu'un
     * > seul domicile légitime, `App\ValueObjects\Catalog\FrameLevelCoverage`, que
     * > `30-themes-vivier-et-tirage-des-variantes.md` possède et qui n'existe pas
     * > encore. La fabrique en porte une copie **minimale** pour que son défaut ne
     * > soit pas arbitraire ; elle appellera le value object le jour où il existe.
     * > Le repli de niveau, lui, n'est PAS reproduit ici : il dépend du catalogue,
     * > et il se passe explicitement par l'argument de {@see self::atTier()}.
     */
    private static function nominalLevel(int $tierIndex, int $framesPerRound): FrameLevel
    {
        $levels = match ($framesPerRound) {
            2 => [FrameLevel::Level1, FrameLevel::Level5],
            4 => [FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level4, FrameLevel::Level5],
            5 => [
                FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level3,
                FrameLevel::Level4, FrameLevel::Level5,
            ],
            default => [FrameLevel::Level1, FrameLevel::Level3, FrameLevel::Level5],
        };

        return $levels[$tierIndex - 1] ?? FrameLevel::Level1;
    }
}
