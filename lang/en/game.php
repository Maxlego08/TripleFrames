<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `game` : manche, palier, saisie, révélation, podium
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05, encore vide à une clé près : aucun écran
    | de jeu n’existe dans le dépôt. `frame.alt` y figure parce que la spec 05
    | l’impose nommément (§ Erreurs, validation et messages à destinataire
    | unique) : l’attribut `alt` d’une image de jeu est **neutre et traduit**,
    | il ne décrit jamais le contenu de l’image — le décrire donnerait la
    | réponse à un lecteur d’écran comme à un curieux du DOM.
    |
    */

    'frame' => [
        'alt' => 'Frame :index of :total in the current round',
    ],

];
