<?php

use App\Enums\FrameProcessingState;
use App\Http\Controllers\Admin\FrameReviewQueueController;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| File de revue plafonnée — spec 20 § 7.3, amendé le 05/10 (D57)
|--------------------------------------------------------------------------
|
| Un lot d'images importé met des centaines de films en revue d'un coup :
| chaque liste n'envoie que ses premiers films, et les totaux réels à part.
|
*/

test('la file de revue n\'envoie que ses premiers films et donne les vrais totaux', function (): void {
    $movies = FrameReviewQueueController::MOVIES_PER_LIST + 3;

    Movie::factory()->count($movies)->create()->each(
        fn (Movie $movie) => Frame::factory()->for($movie)->count(2)->create(['processing_state' => FrameProcessingState::Ready]),
    );

    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/review/index')
            ->has('queue.to_review', FrameReviewQueueController::MOVIES_PER_LIST)
            ->where('queue_totals.to_review', ['movies' => $movies, 'frames' => $movies * 2]));
});
