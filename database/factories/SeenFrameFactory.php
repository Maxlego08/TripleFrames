<?php

namespace Database\Factories;

use App\Models\Frame;
use App\Models\Room;
use App\Models\SeenFrame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see SeenFrame} — la mémoire d'images d'un salon (§ 7.9),
 * **axe salon seul**.
 *
 * Aucune colonne de joueur, aucun `game_id`, aucun `round_id`, aucun compteur
 * d'occurrences : « la moins récemment vue » n'a besoin que de `last_seen_at`.
 * La fabrique ne peut donc exposer aucun état « par joueur » — il n'y a rien à
 * remplir.
 *
 * `room_id` est **NOT NULL** : une partie solo n'écrit jamais ici. Une fabrique
 * qui accepterait un salon nul fabriquerait la fuite que la barrière 3 du § 7.10
 * ferme.
 *
 * La table n'a **aucune colonne conventionnelle** — `#[WithoutTimestamps]` sur le
 * modèle : `last_seen_at` est donc écrite explicitement ici, jamais par un
 * `useCurrent()` ni par Eloquent.
 *
 * En jeu, la ligne est upsertée **à l'ouverture du palier** et sur
 * `round_tier.served_frame_id`, jamais sur la variante tirée : une manche
 * annulée, une fin anticipée ou une substitution ne marquent jamais une image qui
 * n'a pas été affichée.
 *
 * @extends Factory<SeenFrame>
 */
class SeenFrameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'frame_id' => Frame::factory(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * Mémoire rattachée à un salon existant — le cas de toute vérification de
     * tirage, qui doit lire la mémoire d'UN salon.
     */
    public function forRoom(Room $room): static
    {
        return $this->state(fn (array $attributes): array => [
            'room_id' => $room->id,
        ]);
    }

    /**
     * Image réellement affichée — celle de `round_tier.served_frame_id`, jamais la
     * variante tirée au lancement.
     */
    public function forFrame(Frame $frame): static
    {
        return $this->state(fn (array $attributes): array => [
            'frame_id' => $frame->id,
        ]);
    }

    /**
     * Vue ancienne : c'est l'ordre de `last_seen_at` qui départage les variantes
     * quand aucune n'est neuve pour le salon.
     */
    public function seenDaysAgo(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_seen_at' => now()->subDays($days),
        ]);
    }
}
