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

    'appearance' => [
        'dark' => 'Sombre',
        'label' => 'Apparence',
        'light' => 'Clair',
        'system' => 'Système',
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
        'back_home' => 'Retour à l’accueil',
        'forbidden' => [
            'title' => 'Accès refusé',
            'description' => 'Vous n’avez pas l’autorisation d’ouvrir cette page.',
        ],
        'not_found' => [
            'title' => 'Page introuvable',
            'description' => 'Cette page n’existe pas ou n’existe plus. Vérifiez l’adresse, ou le code du salon si vous rejoigniez une partie.',
        ],
        'page_expired' => [
            'title' => 'Page expirée',
            'description' => 'La page a expiré faute d’activité récente. Recommencez votre dernière action.',
        ],
        'too_many_requests' => [
            'title' => 'Trop de demandes',
            'description' => 'Trop de demandes en peu de temps. Patientez quelques instants avant de réessayer.',
        ],
        'server_error' => [
            'title' => 'Erreur du serveur',
            'description' => 'Une erreur inattendue est survenue de notre côté. Réessayez dans un instant.',
        ],
        'service_unavailable' => [
            'title' => 'Service indisponible',
            'description' => 'Le site est momentanément indisponible, sans doute le temps d’une mise à jour. Réessayez un peu plus tard.',
        ],
    ],

    'language' => [
        'change' => 'Changer de langue',
        'current' => 'Langue actuelle : :language',
        'label' => 'Langue',
    ],

    // Avatars (spec 40 § 6.7). `alt` n'est lu que loin d'un pseudo affiché :
    // à côté d'un pseudo, l'image est décorative (I5.9). Un libellé de
    // prédéfini n'est que le nom accessible d'une option du sélecteur ; ses
    // clés sont celles d'`AvatarPresetCatalog`, stables quand le pack change.
    'avatar' => [
        'alt' => [
            'initials' => 'Initiales du joueur',
            'preset' => 'Avatar du joueur',
            'provider' => 'Photo de profil du joueur',
        ],
        'picker' => [
            'label' => 'Choisissez un avatar',
            'taken' => 'déjà choisi dans ce salon',
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

    'home' => [
        'deploy' => 'Déployer',
        'description' => 'Nous vous suggérons de commencer par ceci.',
        'documentation' => 'Lire la documentation',
        'intro' => 'Laravel possède un écosystème d’une richesse remarquable.',
        'title' => 'Pour commencer',
        'tutorials' => 'Regarder les tutoriels vidéo sur Laracasts',
    ],

];
