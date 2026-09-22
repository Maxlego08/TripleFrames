<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `legal` : habillage des pages légales et attribution TMDB
    |--------------------------------------------------------------------------
    |
    | Ce domaine ne porte que l’**habillage** : libellés de pied de page,
    | titres de navigation, date de mise à jour, bloc de contact et
    | avertissement de disponibilité. Le **corps** des trois pages légales est
    | rédigé en français seulement (décision 4), vit en partiels de vue
    | `resources/views/legal/{page}.fr.blade.php` — hors des dictionnaires — et
    | est rendu dans un conteneur `lang="fr"` quelle que soit la locale du
    | visiteur.
    |
    */

    'contact' => [
        'heading' => 'Contact',
        'description' => 'For any question about these pages, write to :email.',
    ],

    'french_only' => 'These pages are only available in French.',

    'footer' => [
        'notice' => 'Legal notice',
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of use',
    ],

    'notice' => [
        'title' => 'Legal notice',
    ],

    'privacy' => [
        'title' => 'Privacy policy',
    ],

    'terms' => [
        'title' => 'Terms of use',
    ],

    'tmdb' => [
        'attribution' => 'This product uses the TMDB API but is not endorsed or certified by TMDB.',
    ],

    'updated_at' => 'Last updated on :date',

];
