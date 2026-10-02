<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `legal` : habillage des pages légales et attribution TMDB
    |--------------------------------------------------------------------------
    |
    | Ce domaine ne porte que l’**habillage** : libellés de pied de page,
    | titres, bandeau provisoire, date de mise à jour, bloc de contact,
    | avertissement de disponibilité et mention d’acceptation des CGU. Le
    | **corps** des trois pages légales et de « signaler un contenu » est
    | rédigé en français seulement (décision 4), vit en partiels de vue
    | `resources/views/legal/{page}.fr.blade.php` — hors des dictionnaires — et
    | est rendu dans un conteneur `lang="fr"` quelle que soit la locale du
    | visiteur (spec 90 § 4.2).
    |
    | Aucun texte de ce domaine ne qualifie le service de « non commercial »
    | (principe 12), ne nomme un sous-traitant ni ne promet un délai.
    |
    */

    'contact' => [
        'heading' => 'Contact',
        'description' => 'For any question about these pages, write to :email.',
        'unavailable' => 'No contact address has been published yet.',
    ],

    'french_only' => 'These pages are only available in French.',

    'footer' => [
        'label' => 'Legal information',
        'notice' => 'Legal notice',
        'privacy' => 'Privacy policy',
        'report' => 'Report content',
        'sheet_description' => 'Legal notice, terms of use, privacy, content reporting and TMDB attribution.',
        'terms' => 'Terms of use',
    ],

    'new_tab' => '(opens in a new tab)',

    'back_to_top' => 'Back to top',

    'notice' => [
        'description' => 'Essential information about the publisher, hosting and site content.',
        'title' => 'Legal notice',
    ],

    'privacy' => [
        'description' => 'What TripleFrames keeps, why, and for how long.',
        'title' => 'Privacy policy',
    ],

    'provisional' => 'Provisional text, with no contractual value.',

    'report' => [
        'description' => 'How to report an image or film and request its removal.',
        'title' => 'Report content',
    ],

    'terms' => [
        'description' => 'The essential rules for playing and using TripleFrames.',
        'title' => 'Terms of use',
    ],

    'toc' => [
        'label' => 'Document contents',
        'title' => 'Contents',
    ],

    'terms_notice' => 'By continuing, you accept the terms of use.',

    'tmdb' => [
        'attribution' => 'This product uses the TMDB API but is not endorsed or certified by TMDB.',
        'logo_alt' => 'TMDB logo',
    ],

    'updated_at' => 'Last updated on :date',

];
