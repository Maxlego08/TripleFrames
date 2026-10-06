<?php

use App\Models\User;
use App\Support\I18n\TranslationDomains;

/*
|--------------------------------------------------------------------------
| Props partagées des écrans — spec 90 § 7.1, contrat C16 § 3
|--------------------------------------------------------------------------
|
| `frameFormat` porte le format fixe de la frame servable : deux entiers,
| globaux et identiques pour tous, sans aucune donnée de manche. Ce fichier
| en prouve la FORME ; `FrameGeometryTest` (spec 20) prouve son égalité à la
| source, `FrameGeometry` (R-04 : une preuve par propriété).
|
*/

it("partage le format d'image fixe en deux entiers", function () {
    $this->withoutVite();

    $verified = User::factory()->create();
    $curator = User::factory()->curator()->create();

    // Une page joueur en invité, une page joueur connectée, une page du
    // back-office, et la même page joueur dans l'autre langue : la prop ne
    // dépend ni du visiteur, ni de la page, ni de la locale.
    $pages = [
        'accueil, invité' => fn () => $this->get(route('home')),
        'accueil, invité anglophone' => fn () => $this->withUnencryptedCookie('locale', 'en')->get(route('home')),
        'page légale, invité' => fn () => $this->get(route('legal.terms')),
        'profil, connecté' => fn () => $this->actingAs($verified)->get(route('profile.edit')),
        'back-office, curateur' => fn () => $this->actingAs($curator)->get(route('admin.dashboard')),
    ];

    $formats = [];

    foreach ($pages as $label => $visit) {
        // Singleton : une requête neuve repart d'une sélection vide.
        app()->forgetInstance(TranslationDomains::class);

        $format = $visit()->assertOk()->inertiaProps('frameFormat');

        // Exactement deux clés, deux entiers strictement positifs : aucune
        // autre donnée ne voyage avec le format, et surtout rien d'une
        // manche (règle 3).
        expect($format)->toBeArray()
            ->and(array_keys($format))->toBe(['width', 'height'], $label)
            ->and($format['width'])->toBeInt()->toBeGreaterThan(0)
            ->and($format['height'])->toBeInt()->toBeGreaterThan(0);

        $formats[$label] = $format;
    }

    // Identique sur chaque page, pour chaque visiteur.
    expect(array_unique(array_map('serialize', $formats)))->toHaveCount(1);
});
