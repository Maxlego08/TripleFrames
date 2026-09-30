<?php

use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Support\Eloquent\AppendOnlyBuilder;

/**
 * L'immuabilité de `admin_action` et de `frame_review` est OUTILLÉE, jamais confiée
 * à un déclencheur SQL — et elle doit tenir « aussi loin de toute requête HTTP :
 * une passe de curation, une commande d'exploitation ou un test ».
 *
 * Les gardes d'événement (`updating` sur {@see AdminAction}, `saving` sur
 * {@see FrameReview}) ne couvrent que l'écriture d'une INSTANCE : aucun événement
 * de modèle n'est émis par une mise à jour de masse. C'est cette seconde moitié que
 * {@see AppendOnlyBuilder} ferme, et que ce fichier vérifie.
 */
it('refuses a mass update on the administration journal', function () {
    $action = AdminAction::factory()->create();

    expect(fn () => AdminAction::query()->where('id', $action->id)->update(['actor_name' => 'Compte supprimé']))
        ->toThrow(LogicException::class);

    // `actor_name` est nommément exclue de l'anonymisation (§ 8.3) : la valeur
    // d'origine doit être intacte après le refus.
    expect($action->fresh()?->actor_name)->toBe($action->actor_name);
});

it('refuses a mass update on a frame review', function () {
    $review = FrameReview::factory()->create();

    expect(fn () => FrameReview::query()->where('id', $review->id)->update(['grid_version' => 99]))
        ->toThrow(LogicException::class);

    expect($review->fresh()?->grid_version)->toBe($review->grid_version);
});

it('still inserts on both append-only tables', function () {
    // La garde ferme la mise à jour, jamais l'ajout : sans cette assertion, un refus
    // trop large passerait inaperçu jusqu'au premier geste d'administration réel.
    expect(AdminAction::factory()->create()->exists)->toBeTrue();
    expect(FrameReview::factory()->create()->exists)->toBeTrue();
});
