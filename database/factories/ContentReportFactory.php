<?php

namespace Database\Factories;

use App\Enums\ContentReportReason;
use App\Enums\ContentReportResolution;
use App\Enums\ContentReportStatus;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\Player;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de {@see ContentReport} (D63 du 07/10).
 *
 * Par défaut : un signalement OUVERT du film entier, par un siège d'invité,
 * motif `wrong_movie`. `target_key` suit toujours la cible : les états
 * {@see self::forMovie()} et {@see self::forFrame()} l'écrivent avec elle.
 *
 * @extends Factory<ContentReport>
 */
class ContentReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory()->published(),
            'frame_id' => null,
            'target_key' => fn (array $attributes): string => ContentReport::MOVIE_KEY_PREFIX.$attributes['movie_id'],
            'reason' => ContentReportReason::WrongMovie,
            'comment' => null,
            'reporter_user_id' => null,
            'reporter_player_id' => Player::factory(),
            'status' => ContentReportStatus::Open,
        ];
    }

    /** Signalement du film entier. */
    public function forMovie(Movie $movie): static
    {
        return $this->state(fn (array $attributes): array => [
            'movie_id' => $movie->id,
            'frame_id' => null,
            'target_key' => ContentReport::targetKeyFor($movie, null),
        ]);
    }

    /** Signalement d'une image, rattaché à son film. */
    public function forFrame(Frame $frame): static
    {
        return $this->state(fn (array $attributes): array => [
            'movie_id' => $frame->movie_id,
            'frame_id' => $frame->id,
            'target_key' => ContentReport::FRAME_KEY_PREFIX.$frame->id,
        ]);
    }

    /** Signaleur : un siège d'invité. */
    public function bySeat(Player $seat): static
    {
        return $this->state(fn (array $attributes): array => [
            'reporter_player_id' => $seat->id,
            'reporter_user_id' => null,
        ]);
    }

    /** Signaleur : un compte connecté. */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'reporter_user_id' => $user->id,
            'reporter_player_id' => null,
        ]);
    }

    public function reason(ContentReportReason $reason): static
    {
        return $this->state(fn (array $attributes): array => ['reason' => $reason]);
    }

    /** Ouvert (défaut). */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContentReportStatus::Open,
            'resolution' => null,
            'resolved_at' => null,
            'resolved_by_id' => null,
        ]);
    }

    /** Clos par une dépublication du film. */
    public function resolved(?User $curator = null, ContentReportResolution $resolution = ContentReportResolution::MovieUnpublished): static
    {
        return $this->closed($resolution, $curator);
    }

    /** Ignoré par un curateur. */
    public function dismissed(?User $curator = null): static
    {
        return $this->closed(ContentReportResolution::Dismissed, $curator);
    }

    /** Les quatre colonnes de clôture, toujours ensemble. */
    private function closed(ContentReportResolution $resolution, ?User $curator): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $resolution->status(),
            'resolution' => $resolution,
            'resolved_at' => CarbonImmutable::now(),
            'resolved_by_id' => $curator === null ? User::factory()->curator() : $curator->id,
        ]);
    }
}
