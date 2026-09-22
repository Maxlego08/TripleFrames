<?php

namespace Database\Factories;

use App\Casts\RoomSettingsCast;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Validation\ValidationException;

/**
 * Fabrique de test de {@see Room} — le SALON, jamais la partie (§ 6.2).
 *
 * Deux pièges propres à cette table, et c'est pour eux que la fabrique existe.
 *
 * 1. **`settings` est casté par {@see RoomSettingsCast}**, dont le
 *    `set()` refuse durement tout ce qui n'est pas une instance de
 *    {@see RoomSettings}. L'objet est donc construit par {@see RoomSettings::defaults()}
 *    ou {@see RoomSettings::fromInput()} — jamais écrit à la main —, seuls
 *    constructeurs qui passent par les bornes de {@see RoomSettingsBounds}, bornes
 *    croisées comprises (règle 2).
 * 2. **Les cinq colonnes de projection ne sont jamais écrites seules** : `capacity`,
 *    `frames_per_round`, `rounds_count`, `input_difficulty` et `allow_late_join`
 *    sont la projection typée du value object. {@see self::projection()} est donc
 *    l'unique point d'écriture de la fabrique, et tout état qui touche aux réglages
 *    y repasse — sinon la fabrique produirait exactement le salon dont la
 *    projection ment sur son value object, que le modèle existe pour empêcher.
 *
 * `host_player_id` est une **référence souple, sans contrainte de FK** (§ 1.5) :
 * elle reste nulle par défaut et ne se remplit que par {@see self::hostedBy()},
 * c'est-à-dire seulement quand le siège visé existe vraiment.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Alphabet non ambigu du `room_code` (§ 6.2) : ni `I`, ni `O`, ni `0`, ni `1`,
     * qu'un joueur recopie de travers depuis un écran partagé.
     */
    public const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Longueur du code court, fixée par `char(6)`. */
    public const int CODE_LENGTH = 6;

    /**
     * Define the model's default state.
     *
     * Un salon de lobby, jamais lancé, code actif, hôte non encore désigné.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = self::code();

        return array_merge([
            'room_code' => $code,
            'room_code_active' => $code,
            'status' => RoomStatus::Lobby,
            'host_player_id' => null,
        ], self::projection(RoomSettings::defaults()), [
            'launched_at' => null,
            'last_activity_at' => now(),
            'archived_at' => null,
        ]);
    }

    /**
     * Réglages complets imposés, avec leur projection typée recalculée.
     */
    public function withSettings(RoomSettings $settings): static
    {
        return $this->state(fn (array $attributes): array => self::projection($settings));
    }

    /**
     * Salon réglé sur un `N` donné, `D` relevée à `5 s × N` si la valeur par défaut
     * ne tient plus la borne croisée 1.
     *
     * @throws ValidationException Si la paire (`N`, `D`) viole une borne.
     */
    public function framesPerRound(int $framesPerRound): static
    {
        return $this->withSettings(RoomSettings::fromInput([
            'framesPerRound' => $framesPerRound,
            RoomSettings::INPUT_ROUND_DURATION => max(
                RoomSettingsBounds::DEFAULT_ROUND_DURATION,
                RoomSettingsBounds::minRoundDuration($framesPerRound),
            ),
        ]));
    }

    /**
     * Salon dont une partie est en cours — `room.status`, jamais `game.status`.
     */
    public function playing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RoomStatus::Playing,
            'launched_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Salon archivé : le créneau d'unicité est libéré, ce qui recycle le code sans
     * index partiel (§ 1.4). L'effacement des identifiants d'invité appartient à
     * l'action d'archivage, jamais à une fabrique — voir
     * {@see PlayerFactory::archivedIdentity()}.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RoomStatus::Archived,
            'room_code_active' => null,
            'launched_at' => now()->subDay(),
            'archived_at' => now(),
        ]);
    }

    /**
     * Désigne l'hôte — le seul chemin par lequel la fabrique remplit la référence
     * souple, la cible existant alors réellement.
     */
    public function hostedBy(Player $player): static
    {
        return $this->state(fn (array $attributes): array => [
            'host_player_id' => $player->id,
        ]);
    }

    /**
     * Le value object ET les six colonnes qui le projettent, toujours ensemble.
     *
     * `settings_version` est redondante avec ce que le cast écrit au `save()`
     * (`RoomSettings::$sourceVersion`) : elle est posée ici pour qu'un `make()`
     * non persisté porte déjà la bonne version.
     *
     * @return array<string, mixed>
     */
    private static function projection(RoomSettings $settings): array
    {
        return [
            'capacity' => $settings->capacity,
            'frames_per_round' => $settings->framesPerRound,
            'rounds_count' => $settings->roundsCount,
            'input_difficulty' => $settings->inputDifficulty,
            'allow_late_join' => $settings->allowLateJoin,
            'settings' => $settings,
            'settings_version' => $settings->sourceVersion,
        ];
    }

    /**
     * Code court tiré sur l'alphabet non ambigu, puis replié par le normaliseur du
     * modèle : la portabilité vient de la donnée, jamais d'une collation (§ 1.4).
     */
    private static function code(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';

        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return Room::normalizeCode($code);
    }
}
