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
            'avatar' => 'Avatar',
            'history' => 'Mes parties',
            'linked' => 'Comptes liés',
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

    // Les deux cases de consentement d'une création de compte et de la
    // ré-acceptation (spec 40 § 13.1) : distinctes, jamais pré-cochées.
    'consent' => [
        'terms' => 'J’accepte les conditions générales d’utilisation',
        'terms_link' => 'Lire les conditions générales',
        'age' => 'Je déclare avoir au moins 15 ans',
        'errors' => [
            'terms_required' => 'Vous devez accepter les conditions générales pour continuer.',
            'age_required' => 'Vous devez avoir au moins 15 ans pour avoir un compte.',
        ],
    ],

    // L'interstitiel de ré-acceptation des CGU (spec 40 § 13.1).
    'terms_update' => [
        'title' => 'Conditions générales',
        'description' => 'Les conditions générales d’utilisation ont changé. Acceptez la nouvelle version pour retrouver les fonctions de votre compte.',
        'description_first' => 'Acceptez les conditions générales d’utilisation pour accéder aux fonctions de votre compte.',
        'play_note' => 'Vous pouvez continuer à jouer sans les accepter : seules les fonctions du compte sont suspendues.',
        'submit' => 'Accepter et continuer',
        'play' => 'Retour à l’accueil',
        'logout' => 'Se déconnecter',
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
            'privileged_required' => 'La double authentification est obligatoire pour un compte curateur ou administrateur : elle ne peut pas être désactivée.',
            'privileged_rotate' => 'Un compte curateur ou administrateur ne remplace pas lui-même son application d’authentification confirmée : un autre administrateur doit d’abord lui retirer son rôle.',
            'setup_key' => 'La clé de configuration n’a pas pu être chargée.',
        ],
        'manage' => [
            'description' => 'Gérez l’authentification à deux facteurs de votre compte',
            'disable' => 'Désactiver la 2FA',
            'disabled_hint' => 'Une fois l’authentification à deux facteurs activée, un code sécurisé vous sera demandé à la connexion. Ce code provient d’une application compatible TOTP installée sur votre téléphone.',
            'enable' => 'Activer la 2FA',
            'enabled_hint' => 'Un code sécurisé et aléatoire vous sera demandé à la connexion ; vous le trouverez dans l’application compatible TOTP installée sur votre téléphone.',
            'heading' => 'Authentification à deux facteurs',
            'privileged_hint' => 'Votre rôle exige la double authentification : elle reste active. Une passkey vous dispense du code à la connexion, jamais de son enrôlement.',
            'setup' => 'Poursuivre la configuration',
        ],
        'setup' => [
            'code_label' => 'Code d’authentification',
            'code_placeholder' => 'Saisissez le code à 6 chiffres',
            'confirm' => 'Confirmer',
            'copy_key' => 'Copier la clé de configuration',
            'done' => [
                'description' => 'L’authentification à deux facteurs est active. Scannez le QR code ou saisissez la clé de configuration dans votre application d’authentification.',
                'heading' => 'Authentification à deux facteurs activée',
                'submit' => 'Fermer',
            ],
            'key_label' => 'Clé de configuration',
            'key_copied' => 'Clé de configuration copiée',
            'manual_entry' => 'ou saisissez la clé de configuration à la main',
            'qr_label' => 'QR code à scanner avec votre application d’authentification',
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
        'errors' => [
            'last_method' => 'Cette passkey est votre dernière méthode de connexion : définissez d’abord un mot de passe, liez un fournisseur ou ajoutez une autre passkey.',
        ],
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
        'remove_named' => 'Supprimer la passkey « :name »',
        'removing' => 'Suppression…',
        'submit' => 'Enregistrer la passkey',
        'unsupported' => 'Les passkeys ne sont pas prises en charge par ce navigateur.',
        'verify' => [
            'loading' => 'Authentification…',
            'separator' => 'Ou continuez avec votre adresse e-mail',
            'submit' => 'Se connecter avec une passkey',
        ],
    ],

    // L'avatar du compte (spec 40 § 11, D49 du 01/10).
    'avatar' => [
        'title' => 'Avatar',
        'heading' => 'Avatar',
        'description' => 'Choisissez l’avatar que les autres joueurs voient à côté de votre pseudo',
        'current' => 'Avatar actuel',
        'choice_legend' => 'Avatar affiché',
        'use_upload' => 'Utiliser mon image',
        'use_provider' => 'Ma photo :provider',
        'use_preset' => 'Utiliser un avatar prédéfini',
        'presets' => 'Avatars prédéfinis',
        'save' => 'Enregistrer',
        'saved' => 'Avatar enregistré.',
        'upload_heading' => 'Mon image',
        'upload_description' => 'JPEG, PNG ou WebP. Recadrez votre image au carré : elle sera affichée en petit, en rond.',
        'choose_file' => 'Choisir une image',
        'replace_file' => 'Remplacer mon image',
        'crop_heading' => 'Recadrer',
        'crop_help' => 'Faites glisser l’image pour la placer, puis réglez le zoom.',
        'zoom' => 'Zoom',
        'upload' => 'Téléverser',
        'uploading' => 'Envoi en cours…',
        'uploaded' => 'Image téléversée.',
        'cancel' => 'Annuler',
        'delete' => 'Supprimer mon image',
        'delete_confirm' => 'Supprimer votre image ? Votre avatar redevient un avatar prédéfini.',
        'deleted' => 'Image supprimée.',
        'preview_alt' => 'Aperçu du recadrage',
        'hidden_notice' => 'Votre image a été masquée après des signalements, ou retirée par un administrateur. Elle n’est plus montrée aux autres joueurs et vous ne pouvez pas en téléverser une nouvelle tant qu’un administrateur n’a pas levé cette mesure.',
        'rules' => 'Pas d’image choquante, violente, sexuelle ou qui imite une autre personne : une image signalée par deux joueurs est masquée.',
        'errors' => [
            'format' => 'Ce format d’image n’est pas accepté : utilisez JPEG, PNG ou WebP.',
            'animated' => 'Les images animées ne sont pas acceptées.',
            'unreadable' => 'Cette image est illisible.',
            'dimensions' => 'Cette image est trop grande.',
            'too_heavy' => 'Cette image ne peut pas être allégée assez : essayez une image plus simple.',
            'blocked' => 'Le téléversement est bloqué tant qu’un administrateur n’a pas levé le masquage de votre image.',
            'unavailable' => 'Votre image n’est pas disponible.',
            'read_failed' => 'Impossible de lire ce fichier dans le navigateur.',
        ],
    ],

    // Connexion par Discord et Google (spec 40 § 12, D51 du 01/10).
    // `:provider` : le nom du fournisseur, `common.provider.*`.
    'oauth' => [
        'continue_with' => 'Continuer avec :provider',
        'confirm_with' => 'Confirmer avec :provider',
        'separator' => 'ou',
        'finish' => [
            'title' => 'Finaliser l’inscription',
            'heading' => 'Bienvenue !',
            'description' => 'Votre compte :provider est presque prêt. Choisissez votre nom et acceptez les conditions.',
            'email' => 'Adresse reçue de :provider : :email',
            'no_email' => ':provider n’a transmis aucune adresse e-mail : vous pourrez en ajouter une dans votre profil.',
            'name' => 'Nom du compte',
            'submit' => 'Créer mon compte',
        ],
        'errors' => [
            'failed' => 'La connexion avec ce fournisseur n’a pas abouti. Réessayez.',
            'two_factor_link' => 'Un compte protégé par la double authentification utilise cette adresse. Connectez-vous avec votre mot de passe, puis liez ce fournisseur depuis vos réglages.',
            'email_taken' => 'Un compte utilise déjà cette adresse. Connectez-vous avec lui, puis liez ce fournisseur depuis vos réglages.',
            'linked_elsewhere' => 'Ce compte est déjà lié à un autre compte TripleFrames.',
            'provider_taken' => 'Un autre compte de ce fournisseur est déjà lié à votre compte.',
            'confirm_mismatch' => 'Ce compte de fournisseur n’est pas lié au vôtre.',
            'last_method' => 'C’est votre dernière méthode de connexion : définissez d’abord un mot de passe ou liez un autre fournisseur.',
            'pending_expired' => 'L’inscription a expiré. Recommencez la connexion.',
        ],
    ],

    // Les comptes liés (spec 40 § 12.5).
    'linked' => [
        'title' => 'Comptes liés',
        'heading' => 'Comptes liés',
        'description' => 'Connectez-vous avec Discord ou Google en plus de votre mot de passe.',
        'linked_on' => 'Lié le :date',
        'not_linked' => 'Non lié',
        'link' => 'Lier',
        'unlink' => 'Délier',
        'unlink_title' => 'Délier :provider ?',
        'unlink_body' => 'Vous ne pourrez plus vous connecter avec ce compte :provider. Une photo copiée depuis ce compte est supprimée.',
        'code_label' => 'Code de double authentification',
        'code_help' => 'Le code de votre application, ou un code de récupération.',
        'cancel' => 'Annuler',
        'linked' => 'Compte lié.',
        'unlinked' => 'Compte délié.',
        'none' => 'Aucun fournisseur de connexion n’est disponible pour le moment.',
        'errors' => [
            'not_linked' => 'Ce fournisseur n’est pas lié à votre compte.',
            'code' => 'Code de double authentification manquant ou incorrect.',
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
        'set_heading' => 'Définir un mot de passe',
        'set_description' => 'Votre compte n’a pas encore de mot de passe : définissez-en un pour vous connecter aussi sans Discord ni Google.',
        'description' => 'Utilisez un mot de passe long et aléatoire pour protéger votre compte',
        'heading' => 'Modifier le mot de passe',
        'submit' => 'Enregistrer',
        'title' => 'Réglages de sécurité',
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

    'history' => [
        'title' => 'Mes parties',
        'description' => 'Vos parties des :months derniers mois. Au-delà, elles sont effacées.',
        'oldest' => 'Plus ancienne partie conservée : :date.',
        'export' => 'Exporter mes données',
        'empty_value' => '—',
        'counters' => [
            'heading' => 'Vos compteurs',
            'scope' => 'Seules les parties multijoueur comptent ; les parties en solo sont listées sans être comptées.',
            'games_played' => 'Parties jouées (:months mois)',
            'correct_answers' => 'Bonnes réponses (:months mois)',
            'best_score' => 'Meilleur score (:months mois)',
            'best_score_detail' => 'Le :date, :rounds manches de :frames images',
            'best_score_link' => 'Voir cette partie',
            'best_score_none' => 'Aucune partie avec des points pour l’instant.',
            'success_rate' => 'Taux de réussite (:months mois)',
            'success_rate_below' => 'Le taux s’affiche à partir de :min manches jouées (:played pour l’instant).',
            'success_rate_detail' => ':correct bonnes réponses sur :played manches jouées',
        ],
        'list' => [
            'heading' => 'Parties',
            'empty' => 'Aucune partie jouée ces :months derniers mois.',
            'solo' => 'Solo',
            'room' => 'Salon :code',
            'rounds' => ':completed manches sur :total',
            'interrupted' => 'Interrompue à la manche :completed sur :total',
            'frames' => ':count images par manche',
            'score' => 'Score',
            'rank' => 'Rang',
            'correct' => 'Bonnes réponses',
            'details' => 'Voir le détail',
            'details_label' => 'Voir le détail de la partie du :date',
        ],
        'pagination' => [
            'label' => 'Pages de mes parties',
            'previous' => 'Parties plus récentes',
            'next' => 'Parties plus anciennes',
            'status' => 'Page :page sur :last',
        ],
        'show' => [
            'title' => 'Partie du :date',
            'back' => 'Retour à mes parties',
            'rounds_heading' => 'Les films de la partie',
            'round' => 'Manche :number',
            'found' => 'Trouvé',
            'missed' => 'Non trouvé',
            'absent' => 'Manche non jouée',
            'points' => ':count point|:count points',
            'original_title' => 'Titre original',
            'scoreless' => 'Partie sans score : aucun point n’était en jeu.',
            'empty' => 'Aucune manche close dans cette partie.',
        ],
    ],

];
