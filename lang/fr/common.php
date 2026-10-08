<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `common` : navigation, boutons, états génériques
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/common.php` — mêmes clés, mêmes `:placeholder`.
    |
    */

    'action' => [
        'back' => 'Retour',
        'cancel' => 'Annuler',
        'close' => 'Fermer',
        'confirm' => 'Confirmer',
        'continue' => 'Continuer',
        'delete' => 'Supprimer',
        'remove' => 'Retirer',
        'save' => 'Enregistrer',
    ],

    'state' => [
        'error' => 'Une erreur est survenue.',
        'loading' => 'Chargement…',
        'processing' => 'Traitement en cours…',
        'saved' => 'Enregistré.',
    ],

    'nav' => [
        'admin' => 'Administration',
        'dashboard' => 'Tableau de bord',
        'home' => 'Accueil',
        'log_in' => 'Se connecter',
        'log_out' => 'Se déconnecter',
        'menu' => 'Menu de navigation',
        'menu_description' => 'Liens de navigation du site.',
        'platform' => 'Plateforme',
        'register' => 'Créer un compte',
        'settings' => 'Réglages',
        'skip_to_content' => 'Aller au contenu',
        'toggle_sidebar' => 'Replier ou déplier la barre latérale',
    ],

    'maintenance' => [
        'banner' => 'Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement.',
        'launch_blocked' => 'Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard.',
    ],

    'connection' => [
        'offline' => 'Vous êtes hors ligne. Le jeu ne s’interrompt pas pour autant : vérifiez votre connexion.',
        'reconnecting' => 'Connexion perdue, reconnexion en cours… Le jeu ne s’interrompt pas pendant ce temps.',
        'restored' => 'Connexion rétablie.',
    ],

    'error' => [
        // Design final des pages d'erreur (maquette `design-test/html/error-*.html`,
        // 08/10) : titres de la maquette, au vouvoiement du site ; actions.
        'back_home' => 'Retour à l’accueil',
        'retry' => 'Réessayer',
        'reload' => 'Recharger la page',
        'join_game' => 'Rejoindre une partie',
        'other_account' => 'Se connecter avec un autre compte',
        'forbidden' => [
            'title' => 'Accès refusé',
            'description' => 'Vous n’avez pas l’autorisation d’accéder à cette page.',
        ],
        'not_found' => [
            'title' => 'Scène introuvable',
            'description' => 'Cette page n’existe pas ou n’existe plus. Vérifiez l’adresse, ou le code du salon si vous rejoigniez une partie.',
        ],
        'page_expired' => [
            'title' => 'Session expirée',
            'description' => 'La page a expiré faute d’activité récente. Recommencez votre dernière action.',
        ],
        'too_many_requests' => [
            'title' => 'Trop de tentatives',
            'description' => 'Trop de demandes ont été envoyées. Attendez un instant, puis réessayez.',
        ],
        'server_error' => [
            'title' => 'Coupure en régie',
            'description' => 'Une erreur interne empêche la page de s’afficher. Réessayez dans un instant.',
        ],
        'service_unavailable' => [
            'title' => 'Séance en pause',
            'description' => 'Le service est temporairement indisponible, sans doute le temps d’une mise à jour. Revenez dans quelques instants.',
        ],
    ],

    // Sélecteur de langue (spec 90 § 8). `changed` est annoncé par
    // l'annonceur une fois le dictionnaire reçu, donc dans la NOUVELLE langue ;
    // `:language` est le libellé natif de la langue choisie.
    'language' => [
        'change' => 'Changer de langue',
        'changed' => 'Langue changée : :language.',
        'current' => 'Langue actuelle : :language',
        'label' => 'Langue',
    ],

    // Noms des fournisseurs de connexion (spec 40 § 12), noms propres.
    'provider' => [
        'discord' => 'Discord',
        'google' => 'Google',
    ],

    // Avatars (spec 40 § 6.7). `alt` n'est lu que loin d'un pseudo affiché :
    // à côté d'un pseudo, l'image est décorative (I5.9). Un libellé de
    // prédéfini n'est que le nom accessible d'une option du sélecteur ; ses
    // clés sont celles d'`AvatarPresetCatalog`, stables quand le pack change.
    'avatar' => [
        'alt' => [
            'initials' => 'Initiales du joueur',
            'upload' => 'Avatar personnel du joueur',
            'preset' => 'Avatar du joueur',
            'provider' => 'Photo de profil du joueur',
        ],
        'picker' => [
            'label' => 'Choisissez un avatar',
            'account' => 'Mon avatar',
            'taken' => 'déjà choisi dans ce salon',
        ],
        // Signalement de l'image téléversée d'un autre siège (spec 40 § 11.6,
        // D49 du 01/10). `:nickname` : le pseudo du siège visé.
        'report' => [
            'action' => 'Signaler l’avatar',
            'confirm_title' => 'Signaler l’avatar de :nickname ?',
            'confirm_body' => 'Signalez une image choquante ou inappropriée. Après deux signalements, elle est masquée jusqu’à la décision d’un administrateur.',
            'sent' => 'Signalement envoyé. Merci.',
            'not_reportable' => 'Cet avatar ne peut pas être signalé.',
        ],
        'preset' => [
            'preset-01' => 'Ours',
            'preset-02' => 'Poussin',
            'preset-03' => 'Vache',
            'preset-04' => 'Crocodile',
            'preset-05' => 'Chien',
            'preset-06' => 'Canard',
            'preset-07' => 'Éléphant',
            'preset-08' => 'Grenouille',
            'preset-09' => 'Girafe',
            'preset-10' => 'Hippopotame',
            'preset-11' => 'Cheval',
            'preset-12' => 'Singe',
            'preset-13' => 'Élan',
            'preset-14' => 'Hibou',
            'preset-15' => 'Panda',
            'preset-16' => 'Perroquet',
            'preset-17' => 'Manchot',
            'preset-18' => 'Cochon',
            'preset-19' => 'Lapin',
            'preset-20' => 'Rhinocéros',
            'preset-21' => 'Paresseux',
            'preset-22' => 'Morse',
            'preset-23' => 'Baleine',
            'preset-24' => 'Zèbre',
        ],
    ],

    // Joueur (spec 90 § 6.4, sur la règle de 40 § 13.3 ; J2, D66 du 07/10).
    // `masked` (« Joueur :ordinal ») est rédigé avec le rendu masqué
    // (nickname-moderation). `report.*` : signalement du pseudo d'un autre
    // siège, même patron que `avatar.report.*` ; `:nickname` est le pseudo du
    // siège visé. `report.sent` est identique dans tous les cas acceptés.
    // `masked_notice` n'est rendu qu'au siège masqué lui-même.
    'player' => [
        'masked' => 'Joueur :ordinal',
        'masked_notice' => 'Votre pseudo a été signalé et il est masqué pour les autres joueurs. Vous pouvez continuer à jouer normalement.',
        'report' => [
            'action' => 'Signaler le pseudo',
            'confirm_title' => 'Signaler le pseudo de :nickname ?',
            'confirm_body' => 'Signalez un pseudo choquant ou inapproprié. Après deux signalements, il est masqué jusqu’à la décision d’un administrateur.',
            'sent' => 'Signalement envoyé. Merci.',
            'not_reportable' => 'Ce pseudo ne peut pas être signalé.',
        ],
    ],

    // Page de fermeture du service (spec 90 § 11.7, `site:close` de 100 ;
    // J2, D66 du 07/10). Aucun placeholder, aucune date de retour promise
    // (la fermeture est réversible) et aucun engagement juridique : les
    // liens mènent aux pages légales et au signalement, qui restent ouverts.
    'closure' => [
        'title' => 'TripleFrames est fermé',
        'body' => 'Le service est fermé pour le moment : il n’est plus possible de créer ou de rejoindre une partie, ni de créer un compte. Vos données ne sont pas effacées, et vous pouvez toujours vous connecter pour les exporter ou supprimer votre compte.',
        'legal_link' => 'Mentions légales',
        'report_link' => 'Signaler un contenu',
    ],

    // Accueil (spec 90 § 4.7) : le jeu en une phrase et ses trois entrées ;
    // « Rejoindre » demande le code ET le pseudo (D55 du 02/10).
    // Aucun placeholder. Aucun texte ne dit la longueur ni l'alphabet d'un
    // code de salon : ils appartiennent à `RoomCode` (spec 50), et le nombre
    // d'images d'une manche est un réglage, jamais une règle (« quelques »).
    'home' => [
        'create_prompt' => 'Lance ton propre salon.',
        'create_prompt_lead' => 'Pas encore de code ?',
        'create_room' => 'Créer une partie',
        'heading' => 'Trois images. Un film à trouver.',
        'join_heading' => 'Rejoins la partie',
        'join_room' => 'Rejoindre la partie',
        'play_solo' => 'Jouer en solo',
        'room_code_hide' => 'Masquer le code',
        'room_code_help' => 'Le code est affiché sur l’écran de l’hôte.',
        'room_code_invalid' => 'Ce code de salon n’est pas valide. Vérifiez-le, puis réessayez.',
        'room_code_label' => 'Code de la partie',
        'room_code_placeholder' => 'ABCD',
        'room_code_show' => 'Afficher le code',
        'nickname_label' => 'Ton pseudo',
        'nickname_placeholder' => 'Ex. Marty McFly',
        'tagline' => 'Devine le titre avant tes amis. Plus tu trouves tôt, plus tu marques de points.',
    ],

    // Description et Open Graph génériques (spec 90 § 11.5, D66 du 07/10),
    // rendus côté serveur par `app.blade.php` via `App\Support\Http\PageMeta`,
    // les mêmes sur toutes les pages. Aucun placeholder : jamais un code de
    // salon, un titre de film ni un pseudo (règle 3), et aucun nombre
    // d'images (un réglage, jamais une règle).
    'meta' => [
        'description' => 'Un blindtest de films et de dessins animés : quelques images, de la plus cryptique à la plus évidente, et un titre à trouver avant tes amis. Salons privés, sans compte.',
        'title' => 'TripleFrames, devine le film à partir de ses images',
    ],

];
