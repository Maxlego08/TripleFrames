<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `legal` : habillage des pages légales et attribution TMDB
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/legal.php` — mêmes clés, mêmes `:placeholder`.
    | Le corps des pages vit dans `resources/views/legal/` (spec 90 § 4.2).
    |
    */

    'contact' => [
        'heading' => 'Contact',
        'description' => 'Pour toute question sur ces pages, écrivez à :email.',
        'unavailable' => 'Aucune adresse de contact n’est encore publiée.',
    ],

    'french_only' => 'Ces textes ne sont disponibles qu’en français.',

    'footer' => [
        'label' => 'Informations légales',
        'notice' => 'Mentions légales',
        'privacy' => 'Politique de confidentialité',
        'report' => 'Signaler un contenu',
        'sheet_description' => 'Mentions légales, conditions d’utilisation, confidentialité, signalement d’un contenu et attribution TMDB.',
        'terms' => 'Conditions générales d’utilisation',
    ],

    'new_tab' => '(s’ouvre dans un nouvel onglet)',

    'back_to_top' => 'Revenir en haut',

    'notice' => [
        'description' => 'Les informations essentielles sur l’éditeur, l’hébergement et les contenus du site.',
        'title' => 'Mentions légales',
    ],

    'privacy' => [
        'description' => 'Ce que TripleFrames conserve, pourquoi et pendant combien de temps.',
        'title' => 'Politique de confidentialité',
    ],

    'provisional' => 'Texte provisoire, sans valeur contractuelle.',

    'report' => [
        'description' => 'Comment signaler une image ou un film et demander son retrait.',
        'title' => 'Signaler un contenu',
    ],

    'terms' => [
        'description' => 'Les règles essentielles pour jouer et utiliser TripleFrames.',
        'title' => 'Conditions générales d’utilisation',
    ],

    'toc' => [
        'label' => 'Sommaire du document',
        'title' => 'Sommaire',
    ],

    'terms_notice' => 'En continuant, vous acceptez les conditions générales d’utilisation.',

    'tmdb' => [
        'attribution' => 'Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB.',
        'logo_alt' => 'Logo de TMDB',
    ],

    'updated_at' => 'Dernière mise à jour le :date',

];
