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

    'home' => [
        'deploy' => 'Déployer',
        'description' => 'Nous vous suggérons de commencer par ceci.',
        'documentation' => 'Lire la documentation',
        'intro' => 'Laravel possède un écosystème d’une richesse remarquable.',
        'title' => 'Pour commencer',
        'tutorials' => 'Regarder les tutoriels vidéo sur Laracasts',
    ],

];
