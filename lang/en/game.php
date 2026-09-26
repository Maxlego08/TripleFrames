<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `game` : manche, palier, saisie, révélation, podium
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. `frame.alt` y figure parce que la spec
    | 05 l’impose nommément (§ Erreurs, validation et messages à destinataire
    | unique) : l’attribut `alt` d’une image de jeu est **neutre et traduit**,
    | il ne décrit jamais le contenu de l’image — le décrire donnerait la
    | réponse à un lecteur d’écran comme à un curieux du DOM.
    |
    | `frame.*` et `a11y.*` sont rédigés par la spec 90 (§ 6.4 et § 6.5,
    | lot L90-6a) : états du cadre d’image, et annonces de manche lues par
    | l’annonceur, aux seuils relatifs de la spec 90 § 7.4. Les autres
    | préfixes appartiennent aux specs 60, 70 et 80.
    |
    */

    'frame' => [
        'alt' => 'Frame :index of :total in the current round',
        'loading' => 'Loading frame…',
        'unavailable' => 'Frame unavailable.',
    ],

    'a11y' => [
        'choices_shown' => 'The answer choices are now shown.',
        'halfway' => 'Halfway through the round.',
        'last_quarter' => 'Last quarter of the round.',
        'round_ended' => 'Round over.',
        'seconds_left' => ':count second left.|:count seconds left.',
        'tier_opened' => 'New frame, :index of :total.',
    ],

    'errors' => [
        'seat_superseded' => 'This tab is no longer in control: you are playing in another tab. Reload the page to take over here.',
    ],

];
