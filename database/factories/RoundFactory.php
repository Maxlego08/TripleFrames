<?php

namespace Database\Factories;

use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Round;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Round} — une manche : un film, `N` paliers, une
 * origine de temps (§ 7.4).
 *
 * **`movie_id` EST la bonne réponse.** La fabrique la pose parce que la colonne est
 * `NOT NULL`, mais elle n'existe qu'en base : ni elle ni les trois leurres ne
 * doivent jamais quitter le serveur avant la révélation, ce que `#[Hidden]` outille
 * sur le modèle et que `ModelSerializationTest` vérifie.
 *
 * `room_id` est **dénormalisée depuis `game` au lancement et jamais modifiée** : la
 * fabrique la relit donc sur la partie qu'elle vient de créer, plutôt que d'en
 * inventer une autre. Un `round.room_id` qui ne serait pas celui de son `game`
 * ferait mentir la non-répétition des films par salon, dont c'est la seule entrée.
 *
 * Les trois `decoy_movie_id_*` restent **nuls** par défaut : ils sont nuls en
 * difficulté `expert`, et les composer coûte trois films de plus. {@see self::withDecoys()}
 * est le seul chemin qui les remplit.
 *
 * `duration_ms` est `D` dénormalisée, et la somme des `round_tier.duration_ms` doit
 * lui être égale (§ 14) : les deux viennent donc du MÊME {@see RoomSettings}, celui
 * des défauts du site, exactement comme {@see RoundTierFactory}.
 *
 * @extends Factory<Round>
 */
class RoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Une manche en attente : aucune origine de temps, aucun palier ouvert.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $settings = RoomSettings::defaults();

        return [
            'game_id' => Game::factory(),
            'room_id' => static function (array $attributes): ?int {
                $roomId = Game::query()->whereKey($attributes['game_id'] ?? null)->value('room_id');

                return is_int($roomId) ? $roomId : null;
            },
            'sequence_index' => 1,
            'round_number' => 1,
            'movie_id' => Movie::factory(),
            'status' => RoundStatus::Pending,
            'started_at' => null,
            'ended_at' => null,
            'reveal_ends_at' => null,
            'duration_ms' => $settings->roundDuration() * 1000,
            'found_count' => 0,
            'decoy_movie_id_1' => null,
            'decoy_movie_id_2' => null,
            'decoy_movie_id_3' => null,
            'choices_use_original_title' => false,
            'cancel_reason' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Manche d'une partie existante, `room_id` recopiée depuis elle.
     */
    public function forGame(Game $game): static
    {
        return $this->state(fn (array $attributes): array => [
            'game_id' => $game->id,
            'room_id' => $game->room_id,
        ]);
    }

    /**
     * Film cible imposé.
     */
    public function forMovie(Movie $movie): static
    {
        return $this->state(fn (array $attributes): array => [
            'movie_id' => $movie->id,
        ]);
    }

    /**
     * Position dans le tirage figé, `1..min(M + 3, |vivier|)`.
     *
     * `round_number` est le numéro AFFICHÉ `1..M` et n'est **pas unique** : une
     * manche annulée et son remplaçant le partagent volontairement. Il vaut par
     * défaut `sequence_index`, ce qui n'est vrai que tant qu'aucune manche n'a été
     * annulée.
     */
    public function atSequence(int $sequenceIndex, ?int $roundNumber = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'sequence_index' => $sequenceIndex,
            'round_number' => $roundNumber ?? $sequenceIndex,
        ]);
    }

    /**
     * Manche en cours : `started_at` est **l'origine unique** du journal, et tout
     * instant de palier s'en déduit par un entier de millisecondes.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RoundStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * Manche arrivée à `D` et en révélation pendant `R`.
     */
    public function revealing(): static
    {
        return $this->state(function (array $attributes): array {
            $durationMs = self::durationMs($attributes);
            $reveal = RoomSettings::defaults()->revealDuration;

            return [
                'status' => RoundStatus::Revealing,
                'started_at' => now()->subMilliseconds($durationMs),
                'ended_at' => now(),
                'reveal_ends_at' => now()->addSeconds($reveal),
            ];
        });
    }

    /**
     * Manche clôturée — le seul filtre de la file de curation « jamais trouvé ».
     */
    public function completed(int $foundCount = 1): static
    {
        return $this->state(function (array $attributes) use ($foundCount): array {
            $durationMs = self::durationMs($attributes);
            $reveal = RoomSettings::defaults()->revealDuration;

            return [
                'status' => RoundStatus::Completed,
                'started_at' => now()->subMilliseconds($durationMs)->subSeconds($reveal),
                'ended_at' => now()->subSeconds($reveal),
                'reveal_ends_at' => now(),
                'found_count' => $foundCount,
            ];
        });
    }

    /**
     * Manche annulée — invariant **L1** : aucun point ne compte, alors même que les
     * lignes `guess` restent en base, immuables. C'est l'état que
     * {@see Guess::counted()} exclut, et `found_count` n'est jamais
     * remis à zéro.
     */
    public function cancelled(RoundIncidentReason $reason = RoundIncidentReason::NoVariantAvailable): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RoundStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
            'ended_at' => now(),
        ]);
    }

    /**
     * Les trois leurres du QCM, tirés et figés au lancement.
     *
     * Ils restent `#[Hidden]` : les composer ici ne les publie pas, et la seule
     * voie de sortie reste la ressource dédiée du QCM, à `T_N`.
     */
    public function withDecoys(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decoy_movie_id_1' => Movie::factory(),
            'decoy_movie_id_2' => Movie::factory(),
            'decoy_movie_id_3' => Movie::factory(),
        ]);
    }

    /**
     * Mode dégradé du QCM : les quatre chaînes sortent des titres originaux parce
     * que le film cible n'a pas de titre dans la locale visée. Décidé **une fois
     * pour tout le salon**, jamais par joueur.
     */
    public function withOriginalTitles(): static
    {
        return $this->state(fn (array $attributes): array => [
            'choices_use_original_title' => true,
        ]);
    }

    /**
     * `D` de cette manche, relue sur les attributs déjà composés.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function durationMs(array $attributes): int
    {
        $durationMs = $attributes['duration_ms'] ?? null;

        return is_int($durationMs) ? $durationMs : RoomSettings::defaults()->roundDuration() * 1000;
    }
}
