<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `account` : Fortify, profil, comptes liés, avatars
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/account.php` — mêmes clés, mêmes `:placeholder`.
    |
    */

    'fields' => [
        'current_password' => 'Mot de passe actuel',
        'email' => 'Adresse e-mail',
        'email_placeholder' => 'adresse@exemple.fr',
        'name' => 'Nom',
        'name_placeholder' => 'Nom complet',
        'new_password' => 'Nouveau mot de passe',
        'password' => 'Mot de passe',
        'password_confirmation' => 'Confirmer le mot de passe',
        'password_hide' => 'Masquer le mot de passe',
        'password_show' => 'Afficher le mot de passe',
    ],

    'settings' => [
        'description' => 'Gérez votre profil et les réglages de votre compte',
        'heading' => 'Réglages',
        'nav' => [
            'appearance' => 'Apparence',
            'profile' => 'Profil',
            'security' => 'Sécurité',
        ],
        'title' => 'Réglages',
    ],

    'login' => [
        'description' => 'Saisissez votre adresse e-mail et votre mot de passe pour vous connecter',
        'forgot' => 'Mot de passe oublié ?',
        'heading' => 'Connexion à votre compte',
        'no_account' => 'Pas encore de compte ?',
        'remember' => 'Se souvenir de moi',
        'sign_up' => 'Créer un compte',
        'submit' => 'Se connecter',
        'title' => 'Connexion',
    ],

    'register' => [
        'description' => 'Saisissez vos informations pour créer votre compte',
        'has_account' => 'Vous avez déjà un compte ?',
        'heading' => 'Créer un compte',
        'sign_in' => 'Se connecter',
        'submit' => 'Créer le compte',
        'title' => 'Inscription',
    ],

    'forgot_password' => [
        'description' => 'Saisissez votre adresse e-mail pour recevoir un lien de réinitialisation',
        'heading' => 'Mot de passe oublié',
        'return_prefix' => 'Ou revenez à la',
        'return_link' => 'connexion',
        'submit' => 'Envoyer le lien de réinitialisation',
        'title' => 'Mot de passe oublié',
    ],

    'reset_password' => [
        'description' => 'Saisissez votre nouveau mot de passe ci-dessous',
        'heading' => 'Réinitialiser le mot de passe',
        'submit' => 'Réinitialiser le mot de passe',
        'title' => 'Réinitialisation du mot de passe',
    ],

    'confirm_password' => [
        'description' => 'Cette zone de l’application est protégée. Confirmez votre mot de passe pour continuer.',
        'heading' => 'Confirmer le mot de passe',
        'passkey' => [
            'loading' => 'Confirmation…',
            'separator' => 'Ou confirmez avec votre mot de passe',
            'submit' => 'Confirmer avec une passkey',
        ],
        'submit' => 'Confirmer le mot de passe',
        'title' => 'Confirmation du mot de passe',
    ],

    'verify_email' => [
        'description' => 'Vérifiez votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer.',
        'heading' => 'Vérification de l’adresse e-mail',
        'log_out' => 'Se déconnecter',
        'sent' => 'Un nouveau lien de vérification a été envoyé à l’adresse e-mail fournie lors de votre inscription.',
        'submit' => 'Renvoyer l’e-mail de vérification',
        'title' => 'Vérification de l’adresse e-mail',
    ],

    'two_factor' => [
        'challenge' => [
            'code' => [
                'description' => 'Saisissez le code fourni par votre application d’authentification.',
                'heading' => 'Code d’authentification',
                'toggle' => 'vous connecter avec un code de récupération',
            ],
            'recovery' => [
                'description' => 'Confirmez l’accès à votre compte en saisissant l’un de vos codes de récupération.',
                'heading' => 'Code de récupération',
                'placeholder' => 'Saisissez un code de récupération',
                'toggle' => 'vous connecter avec un code d’authentification',
            ],
            'submit' => 'Continuer',
            'title' => 'Authentification à deux facteurs',
            'toggle_prefix' => 'ou vous pouvez',
        ],
        'errors' => [
            'qr_code' => 'Le QR code n’a pas pu être chargé.',
            'recovery_codes' => 'Les codes de récupération n’ont pas pu être chargés.',
            'setup_key' => 'La clé de configuration n’a pas pu être chargée.',
        ],
        'manage' => [
            'description' => 'Gérez l’authentification à deux facteurs de votre compte',
            'disable' => 'Désactiver la 2FA',
            'disabled_hint' => 'Une fois l’authentification à deux facteurs activée, un code sécurisé vous sera demandé à la connexion. Ce code provient d’une application compatible TOTP installée sur votre téléphone.',
            'enable' => 'Activer la 2FA',
            'enabled_hint' => 'Un code sécurisé et aléatoire vous sera demandé à la connexion ; vous le trouverez dans l’application compatible TOTP installée sur votre téléphone.',
            'heading' => 'Authentification à deux facteurs',
            'setup' => 'Poursuivre la configuration',
        ],
        'setup' => [
            'code_placeholder' => 'Saisissez le code à 6 chiffres',
            'confirm' => 'Confirmer',
            'done' => [
                'description' => 'L’authentification à deux facteurs est active. Scannez le QR code ou saisissez la clé de configuration dans votre application d’authentification.',
                'heading' => 'Authentification à deux facteurs activée',
                'submit' => 'Fermer',
            ],
            'manual_entry' => 'ou saisissez la clé de configuration à la main',
            'scan' => [
                'description' => 'Pour terminer l’activation, scannez le QR code ou saisissez la clé de configuration dans votre application d’authentification',
                'heading' => 'Activer l’authentification à deux facteurs',
                'submit' => 'Continuer',
            ],
            'verify' => [
                'description' => 'Saisissez le code à 6 chiffres de votre application d’authentification',
                'heading' => 'Vérifier le code d’authentification',
                'submit' => 'Continuer',
            ],
        ],
        'recovery_codes' => [
            'description' => 'Les codes de récupération vous permettent de retrouver l’accès à votre compte si vous perdez votre appareil 2FA.',
            'heading' => 'Codes de récupération',
            'hide' => 'Masquer les codes de récupération',
            'loading' => 'Chargement des codes de récupération',
            'regenerate' => 'Régénérer les codes',
            'single_use' => 'Chaque code de récupération ne sert qu’une fois et disparaît après usage.',
            'view' => 'Afficher les codes de récupération',
        ],
    ],

    'passkeys' => [
        'added' => 'Ajoutée :date',
        'cancel' => 'Annuler',
        'description' => 'Gérez vos passkeys pour une connexion sans mot de passe',
        'empty' => 'Aucune passkey',
        'empty_hint' => 'Ajoutez une passkey pour vous connecter sans mot de passe',
        'heading' => 'Passkeys',
        'last_used' => 'Dernière utilisation :date',
        'name' => 'Nom de la passkey',
        'name_default' => ':browser sur :os',
        'name_hint' => 'Un nom vous aidera à reconnaître cette passkey plus tard.',
        'name_placeholder' => 'ex. MacBook Pro, iPhone',
        'register' => 'Ajouter une passkey',
        'registering' => 'Enregistrement…',
        'remove' => 'Supprimer la passkey',
        'remove_confirm' => 'Voulez-vous vraiment supprimer la passkey « :name » ?',
        'removing' => 'Suppression…',
        'submit' => 'Enregistrer la passkey',
        'unsupported' => 'Les passkeys ne sont pas prises en charge par ce navigateur.',
        'verify' => [
            'loading' => 'Authentification…',
            'separator' => 'Ou continuez avec votre adresse e-mail',
            'submit' => 'Se connecter avec une passkey',
        ],
    ],

    'profile' => [
        'description' => 'Modifiez votre nom et votre adresse e-mail',
        'heading' => 'Profil',
        'submit' => 'Enregistrer',
        'title' => 'Réglages du profil',
        'verification_sent' => 'Un nouveau lien de vérification a été envoyé à votre adresse e-mail.',
        'verify_link' => 'Cliquez ici pour renvoyer l’e-mail de vérification.',
        'unverified' => 'Votre adresse e-mail n’est pas vérifiée.',
    ],

    'security' => [
        'description' => 'Utilisez un mot de passe long et aléatoire pour protéger votre compte',
        'heading' => 'Modifier le mot de passe',
        'submit' => 'Enregistrer',
        'title' => 'Réglages de sécurité',
    ],

    'appearance' => [
        'dark' => 'Sombre',
        'description' => 'Modifiez l’apparence de l’interface pour votre compte',
        'heading' => 'Réglages d’apparence',
        'light' => 'Clair',
        'system' => 'Système',
        'title' => 'Réglages d’apparence',
    ],

    'delete_account' => [
        'cancel' => 'Annuler',
        'confirm' => 'Supprimer le compte',
        'description' => 'Anonymisez votre compte et supprimez vos données personnelles',
        'dialog_description' => 'Votre compte sera anonymisé : adresse e-mail, mot de passe, comptes liés, configurations sauvegardées et photo sont supprimés, et votre nom est remplacé par un libellé neutre. Les parties déjà jouées restent, sans votre nom, dans l’historique des autres joueurs jusqu’à leur effacement au bout de :months mois.',
        'dialog_heading' => 'Voulez-vous vraiment supprimer votre compte ?',
        'heading' => 'Supprimer le compte',
        'submit' => 'Supprimer le compte',
        'warning' => 'Attention',
        'warning_hint' => 'Procédez avec prudence : cette action est irréversible.',
    ],

];
