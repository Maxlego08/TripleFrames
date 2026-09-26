<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `game` : manche, palier, saisie, révélation, podium
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/game.php` — mêmes clés, mêmes `:placeholder`.
    |
    */

    'frame' => [
        'alt' => 'Image :index sur :total de la manche en cours',
        'loading' => 'Chargement de l’image…',
        'unavailable' => 'Image indisponible.',
    ],

    'a11y' => [
        'choices_shown' => 'Les propositions sont affichées.',
        'halfway' => 'Mi-manche.',
        'last_quarter' => 'Dernier quart de la manche.',
        'round_ended' => 'Manche terminée.',
        'seconds_left' => 'Plus que :count seconde.|Plus que :count secondes.',
        'tier_opened' => 'Nouvelle image, :index sur :total.',
    ],

    'errors' => [
        'seat_superseded' => 'Cet onglet n’a plus la main : vous jouez dans un autre onglet. Rechargez la page pour la reprendre ici.',
    ],

];
