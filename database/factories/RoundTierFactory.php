<?php

namespace Database\Factories;

use App\Enums\FrameLevel;
use App\Enums\RoundIncidentReason;
use App\Models\Frame;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * Fabrique de test de {@see RoundTier} — le palier matérialisé : une image, un
 * instant, une durée, une valeur (§ 7.4).
 *
 * **Les quatre colonnes de service sont NULL par défaut**, et c'est la propriété
 * la plus facile à casser ici. En jeu, elles ont deux écrivains uniques (spec 60
 * § 6.1, contrat C8 § 2, E10-47) : le jeton est frappé *à l'ouverture du palier
 * précédent* — à la programmation de la manche pour le palier 1 — avec
 * `served_frame_id` et `substitution_reason`, par `MintTierServeToken` ;
 * `served_at` est écrit plus tard, *à l'ouverture du palier* (`Tᵢ`, instant
 * théorique), par `OpenTier`. Jamais par la route de service d'image, qui est en
 * lecture seule sans exception. Un défaut de fabrique qui les remplirait rendrait
 * vert le test nommé du § 7.4 (« après `N` requêtes d'image anticipées et aucune
 * frontière franchie, `served_at` est nul ») sur un montage qui le viole. Elles
 * ne sont donc écrites que par {@see self::served()} et {@see self::substituted()},
 * qui posent l'état d'un palier après SES DEUX écrivains ; un test qui a besoin
 * d'un palier frappé mais non ouvert le fait frapper par `MintTierServeToken`
 * elle-même.
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
     * Palier FRAPPÉ PUIS OUVERT : les colonnes de service dans l'état où les
     * laissent leurs deux écrivains — le jeton et la variante servie par
     * `MintTierServeToken` (au palier précédent, ou à la programmation pour le
     * palier 1), `served_at` par `OpenTier` à `Tᵢ` —, chacune exactement une
     * fois (E10-47). `served_at` vaut ici « maintenant », jamais l'instant
     * théorique : un test qui en dépend le pose lui-même.
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
     * Niveau NOMINAL du palier `i` pour un `N` donné, lu dans
     * {@see FrameLevelCoverage::nominal()} — seul endroit du dépôt où la
     * répartition `N` → niveaux est écrite (spec 30 § 2).
     *
     * Le repli de niveau, lui, n'est PAS reproduit ici : il dépend du catalogue,
     * et il se passe explicitement par l'argument de {@see self::atTier()}.
     *
     * @throws InvalidArgumentException `N` hors bornes, ou palier hors de `1..N` :
     *                                  mieux vaut un échec de fixture qu'un niveau inventé.
     */
    private static function nominalLevel(int $tierIndex, int $framesPerRound): FrameLevel
    {
        return FrameLevelCoverage::nominal($framesPerRound)[$tierIndex - 1]
            ?? throw new InvalidArgumentException(sprintf(
                'Le palier %d n’existe pas à frames_per_round = %d.',
                $tierIndex,
                $framesPerRound,
            ));
    }
}
