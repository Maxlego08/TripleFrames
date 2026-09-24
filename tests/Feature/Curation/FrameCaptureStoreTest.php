<?php

use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Voie capture : spécifiée, désactivée — spec 20 § 5.4, C9
|--------------------------------------------------------------------------
|
| Sans arbitrage de la licéité de l'acte de capture, seule la voie TMDB est
| ouverte au jalon 1. La route `admin.catalog.frames.capture.store` existe
| pour REFUSER : 403 motivé par `admin.frame.capture.disabled`, rendu par
| `FramePolicy::createFromCapture` avant toute résolution de requête. C'est
| la seconde garde, derrière l'absence de bouton. Seul test de ce fichier au
| jalon 1 : la branche qui accepte un fichier arrive avec le lot L20-33.
|
| Le fichier posté n'est pas une image : des octets aléatoires sous un nom
| `.webp`. Aucune image réelle n'entre dans le dépôt, et aucune n'est lue.
|
*/

test('la voie capture désactivée refuse côté serveur', function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();
    Config::set('catalog.curation.capture_enabled', false);

    $movie = Movie::factory()->create();
    $url = route('admin.catalog.frames.capture.store', ['movie' => $movie->id]);

    foreach ([User::factory()->curator()->create(), User::factory()->admin()->create()] as $user) {
        // Un envoi complet, fichier compris : refusé.
        $this->actingAs($user)
            ->post($url, [
                'source' => UploadedFile::fake()->create('capture.webp', 64, 'image/webp'),
                'source_timecode' => '0:12:34',
                'frame_level' => 3,
                'crop_x' => 192,
                'crop_y' => 108,
                'crop_width' => 1536,
                'crop_height' => 864,
            ])
            ->assertForbidden();

        // Un envoi vide : 403 et non 422 — le refus tombe avant toute
        // validation, et il est MOTIVÉ par une clé, jamais par un texte brut.
        $this->actingAs($user)
            ->postJson($url)
            ->assertForbidden()
            ->assertJsonPath('message', FramePolicy::CAPTURE_DISABLED);

        // C'est bien la policy qui motive le refus de la garde.
        $verdict = Gate::forUser($user)->inspect('createFromCapture', [Frame::class, $movie]);

        expect($verdict->denied())->toBeTrue()
            ->and($verdict->message())->toBe(FramePolicy::CAPTURE_DISABLED);
    }

    // Aucune image, aucun octet, aucun traitement.
    expect(Frame::query()->count())->toBe(0)
        ->and(Storage::disk(FrameStoragePrefix::DISK)->allFiles())->toBe([]);

    Queue::assertNothingPushed();

    // La clé existe, et le motif se lit en français.
    expect(__(FramePolicy::CAPTURE_DISABLED, [], 'fr'))->not->toBe(FramePolicy::CAPTURE_DISABLED);
});
