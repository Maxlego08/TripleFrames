<?php

namespace Database\Factories;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Answers\AnswerRules;
use App\Support\Draw\SeededPrf;
use App\Support\Scoring\ScoringRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Game} — la partie ET la règle appliquée, figée au
 * lancement (§ 7.2).
 *
 * Le journal opposable d'une partie tient dans `settings_snapshot` +
 * `settings_version`, `scoring_version` / `validation_version`, `tier_grace_ms` /
 * `preload_lead_ms` et `draw_seed`. **Une fabrique qui en laisserait une seule au
 * hasard rendrait tout rejeu faux**, donc les sept sont posées ensemble et
 * proviennent de leur domicile unique :
 *
 * - `settings_snapshot` de {@see RoomSettings}, jamais écrit à la main — le cast
 *   refuse durement tout ce qui n'est pas une instance, et `settings_version`
 *   suit la provenance de l'objet ;
 * - `tier_grace_ms` et `preload_lead_ms` de {@see PlatformLimits}, **constantes
 *   serveur** et jamais des réglages d'hôte : les loger dans le value object les
 *   rendrait réglables par la voie du JSON (§ 6.1) ;
 * - `draw_seed` de {@see SeededPrf::generateSeed()}, **CSPRNG**, seule source de
 *   la graine (spec 30 § 5.1), jamais `uniqid()` ni un dérivé d'horodatage — la
 *   règle de fabrication de la graine est au même rang normatif que
 *   l'interdiction des ENUM natifs (§ 7.2) ;
 * - `draw_pool_size` de `M` + {@see PlatformLimits::drawSubstituteMargin()}, un
 *   vivier qui remplit juste la réserve du tirage : aucune copie locale de la
 *   marge (spec 30, lot L30-5) ;
 * - `validation_version` de {@see AnswerRules::VERSION}, la version de la règle
 *   de validation que la partie applique (spec 70 § 12) ; aucune copie locale ;
 * - `scoring_version` de {@see ScoringRules::VERSION}, la version de la règle de
 *   score (spec 80 § 6.1) ; aucune copie locale.
 *
 * `room_id` est nul en solo, et `mode` est figé à la création : aucun chemin ne le
 * mute (§ 7.10). {@see self::solo()} pose les deux ensemble.
 *
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Une partie multijoueur en cours, sur les réglages par défaut du site.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge([
            'room_id' => Room::factory(),
            'mode' => GameMode::Multiplayer,
            'status' => GameStatus::Running,
        ], self::rule(RoomSettings::defaults()), [
            'rounds_completed' => 0,
            'draw_seed' => SeededPrf::generateSeed(),
            'started_at' => now(),
            'paused_at' => null,
            'total_paused_ms' => 0,
            'ended_at' => null,
        ]);
    }

    /**
     * Partie solo : **aucun salon**, donc aucune ligne `seen_frame` et aucun
     * classement final (`game_player.final_rank` reste nul).
     */
    public function solo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'room_id' => null,
            'mode' => GameMode::Solo,
        ]);
    }

    /**
     * Partie rattachée à un salon existant.
     */
    public function forRoom(Room $room): static
    {
        return $this->state(fn (array $attributes): array => [
            'room_id' => $room->id,
            'mode' => GameMode::Multiplayer,
        ]);
    }

    /**
     * Réglages figés imposés, avec leur projection typée et les deux constantes
     * serveur recalculées — jamais l'un sans les autres.
     */
    public function withSettings(RoomSettings $settings): static
    {
        return $this->state(fn (array $attributes): array => self::rule($settings));
    }

    /**
     * Partie menée à son terme : `rounds_completed` vaut alors `M`, et `ended_at`
     * est la **colonne pilote unique** de la fenêtre de 12 mois (§ 11.1).
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes): array {
            $roundsCount = $attributes['rounds_count'] ?? 0;

            return [
                'status' => GameStatus::Completed,
                'rounds_completed' => is_int($roundsCount) ? $roundsCount : 0,
                'ended_at' => now(),
            ];
        });
    }

    /**
     * Partie en pause — c'est la PARTIE qui se met en pause, jamais l'horloge
     * d'une manche, dont `started_at + starts_at_offset_ms` deviendrait faux.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Paused,
            'paused_at' => now(),
        ]);
    }

    /**
     * Partie interrompue à la manche `k` sur `M`.
     */
    public function interrupted(int $roundsCompleted = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Interrupted,
            'rounds_completed' => $roundsCompleted,
            'ended_at' => now(),
        ]);
    }

    /**
     * La règle appliquée : la projection typée des réglages, la charge utile figée
     * et les quatre versions. Point d'écriture unique, pour que `make()` comme
     * `create()` produisent une partie rejouable.
     *
     * @return array<string, mixed>
     */
    private static function rule(RoomSettings $settings): array
    {
        return [
            'input_difficulty' => $settings->inputDifficulty,
            'rounds_count' => $settings->roundsCount,
            'frames_per_round' => $settings->framesPerRound,
            'draw_pool_size' => $settings->roundsCount + PlatformLimits::drawSubstituteMargin(),
            'tier_grace_ms' => PlatformLimits::tierGraceMs(),
            'preload_lead_ms' => PlatformLimits::preloadLeadMs(),
            'settings_version' => $settings->sourceVersion,
            'settings_snapshot' => $settings,
            'scoring_version' => ScoringRules::VERSION,
            'validation_version' => AnswerRules::VERSION,
        ];
    }
}
