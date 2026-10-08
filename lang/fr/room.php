<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `room` : création, réglages, lobby, presets
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/room.php`. Un preset n’a aucune colonne de
    | libellé : son nom n’existe qu’ici.
    |
    */

    /*
    | Création du salon (spec 50 § 6.2) : le salon naît aux réglages par
    | défaut, tout se règle ensuite dans le lobby.
    */

    'create' => [
        'title' => 'Créer un salon',
        'intro' => 'Choisissez un pseudo : vous réglerez la partie, et votre avatar, dans le salon.',
        'submit' => 'Créer le salon',
    ],

    /*
    | Pseudo d’un siège (aucun avatar à l’entrée, D55 du 02/10), champ commun aux formulaires de création
    | et d’entrée (spec 50 § 6.2 et § 7.2). Les bornes arrivent en props,
    | depuis `NicknameNormalizer`, et le client les formate.
    */

    'identity' => [
        'nickname_label' => 'Pseudo',
        'nickname_hint' => 'Entre :min et :max caractères, unique dans le salon.',
    ],

    /*
    | Entrée dans un salon (spec 50 § 7.2 et § 7.3). `kicked` et `full` sont
    | aussi les messages des refus `JoinRefusal`, construits par
    | `messageKey()` ; `late_join` annonce l'entrée d'un retardataire à la
    | manche suivante (§ 15, L50-9).
    */

    'join' => [
        'title' => 'Rejoindre le salon',
        'intro' => 'Choisissez un pseudo pour entrer : votre avatar se choisit ensuite dans la salle d’attente.',
        'code' => 'Salon',
        'submit' => 'Entrer',
        'kicked' => 'L’hôte vous a retiré de ce salon : vous ne pouvez pas y revenir.',
        'full' => 'Ce salon est complet.',
        'in_progress' => 'Une partie est en cours : vous attendrez dans le salon et jouerez la suivante.',
        'late_join' => 'Une partie est en cours : vous entrerez à la manche suivante, sans aucun point.',
    ],

    /*
    | Page d’entrée du solo `room/solo` (spec 60 § 16.4, lot L60-15 ; préfixe
    | rédigé par 60, écart (l) du § 22 bis) : choix d’un des quatre presets du
    | site, puis pseudo et avatar du premier siège solo. `choose_preset` et
    | `start` servent aussi la relance depuis `game/solo` (L60-16). Aucune
    | unicité du pseudo en solo : l’aide ne la mentionne pas.
    */

    'solo' => [
        'title' => 'Jouer en solo',
        'intro' => 'Entraînez-vous seul sur le catalogue : choisissez un preset et un pseudo.',
        'choose_preset' => 'Choisissez un preset',
        'start' => 'Commencer l’entraînement',
        'nickname_hint' => 'Entre :min et :max caractères.',
    ],

    /*
    | Réglages du salon (spec 50 § 20.1 et § 20.2, lots L50-5 et L50-9) :
    | libellé et aide de chaque champ, `room.settings.<champ>.{label,help}`,
    | pour chaque clé de `SIMPLE_KEYS ∪ ADVANCED_KEYS` — le libellé d’un champ
    | de l’onglet Avancé nomme aussi ce champ dans le rapport de changements
    | (`:attribute`) ; les options de la difficulté de saisie et l’instant des
    | propositions en Normal ; `D` remonté par le client quand `N` augmente ;
    | le rapport de changements, un code par `RoomSettings::CHANGE_*`.
    */

    'settings' => [
        'themeKeys' => [
            'label' => 'Thèmes',
            'help' => 'Un film compte s’il appartient à l’un des thèmes choisis. Aucun thème : tout le catalogue.',
        ],
        'roundsCount' => [
            'label' => 'Nombre de manches',
            'help' => 'Une manche, un film.',
        ],
        'framesPerRound' => [
            'label' => 'Images par manche',
            'help' => 'De la plus cryptique à la plus évidente : plus d’images, plus de paliers.',
        ],
        'roundDuration' => [
            'label' => 'Durée d’une manche',
            'help' => 'Répartie à parts égales entre les images.',
            'raised' => 'Durée portée à :seconds s, le minimum pour ce nombre d’images.',
        ],
        'revealDuration' => [
            'label' => 'Durée de la révélation',
            'help' => 'Pause entre deux manches, le temps de découvrir la réponse.',
        ],
        'inputDifficulty' => [
            'label' => 'Difficulté de saisie',
            'help' => 'Comment les joueurs répondent.',
            'option' => [
                'easy' => 'Facile : propositions dès la première image',
                'normal' => 'Normal : texte libre, puis propositions à la dernière image',
                'expert' => 'Expert : texte libre uniquement',
            ],
            'choices_at' => 'Les propositions apparaissent à :seconds s, soit :percent % de la manche.',
        ],
        'capacity' => [
            'label' => 'Places',
            'help' => 'Nombre maximum de joueurs, jamais sous le nombre de joueurs présents.',
        ],
        'allowLateJoin' => [
            'label' => 'Retardataires',
            'help' => 'Autoriser l’arrivée en cours de partie, à la manche suivante, sans aucun point.',
        ],
        'advanced' => [
            'label' => 'Réglages avancés',
            'help' => 'Détail des paliers, barème et limites.',
        ],
        'tierDurations' => [
            'label' => 'Durée des paliers',
            'help' => 'La manche dure la somme des paliers.',
        ],
        'tierPoints' => [
            'label' => 'Points par palier',
            'help' => 'Points gagnés si la réponse tombe dans ce palier.',
        ],
        'speedBonus' => [
            'label' => 'Bonus de rapidité',
            'help' => 'Un bonus qui décroît à l’intérieur de chaque palier.',
        ],
        'noRepeatMovies' => [
            'label' => 'Pas de film déjà joué',
            'help' => 'Écarte les films joués récemment dans ce salon.',
        ],
        'attemptsPerSecond' => [
            'label' => 'Tentatives par seconde',
            'help' => 'Cadence maximale des réponses en texte libre.',
        ],
        'attemptsPerRound' => [
            'label' => 'Tentatives par manche',
            'help' => 'Nombre maximum de réponses en texte libre dans une manche.',
        ],
        'maxAnswerLength' => [
            'label' => 'Longueur maximale d’une réponse',
            'help' => 'En caractères.',
        ],
        'disconnectGraceSeconds' => [
            'label' => 'Délai avant « parti »',
            'help' => 'Temps laissé à un joueur déconnecté pour revenir.',
        ],
        'advanced_active' => 'Des réglages avancés sont actifs :',
        'tier_label' => 'Image :index',
        'tabs' => [
            'simple' => 'Simple',
            'advanced' => 'Avancé',
        ],
        'advanced_sheet' => [
            'title' => 'Réglages avancés',
            'description' => 'Paliers, barème et limites de saisie.',
        ],
        'change' => [
            'defaulted' => '« :attribute » a pris sa valeur par défaut.',
            'dropped' => '« :attribute » n’existe plus et a été retiré.',
            'clamped' => '« :attribute » a été ramené dans ses limites.',
            'resized' => '« :attribute » a été redécoupé pour le nouveau nombre d’images.',
            'pruned' => '« :attribute » : un thème retiré du site a été enlevé.',
            'coerced' => '« :attribute » était illisible et a repris sa valeur par défaut.',
            'equalized' => '« :attribute » : les paliers ont été remis à durée égale.',
            'reset' => '« :attribute » : le barème personnalisé a été remplacé par le barème par défaut.',
            'raised' => '« :attribute » a été relevé au nombre de joueurs présents.',
            'overwritten' => '« :attribute » : votre réglage avancé a été remplacé.',
        ],
    ],

    /*
    | Avertissements non bloquants (spec 50 § 4.5 et § 20.2, lot L50-5) : un
    | code par `RoomSettings::WARNING_*`, diffusé en données et rendu par
    | chaque client dans sa langue ; `:seconds` reçoit le seuil lu dans
    | `bounds.warningThresholds`.
    */

    'warnings' => [
        'short_reveal' => 'Révélation courte : :seconds s sont recommandées pour laisser le temps de lire la réponse.',
        'long_round' => 'Manche de plus de :seconds s : un joueur qui trouve tôt attendra longtemps.',
        'non_decreasing_points' => 'Un palier tardif rapporte autant ou plus qu’un palier précédent : attendre peut payer.',
        'waiting_pays' => 'Avec le bonus de rapidité, répondre au début d’un palier rapporte plus qu’à la fin du précédent : attendre peut payer.',
        'all_tiers_zero' => 'Aucun palier ne rapporte de point : partie sans score, le classement suivra le départage.',
    ],

    /*
    | Refus d’un geste de salon (spec 50 § 12.5 et § 20.3, contrat C6),
    | construits par `RoomRefusal::messageKey()` et rendus à l’auteur seul.
    | Le refus de drainage emploie `common.maintenance.launch_blocked` :
    | `room.refusal.draining` n’existe pas (R-09).
    */

    'refusal' => [
        'not_host' => 'Seul l’hôte peut faire cela.',
        'room_archived' => 'Ce salon a expiré.',
        'not_in_lobby' => 'Une partie est déjà en cours dans ce salon.',
        'settings_outdated' => 'Les réglages ont été mis à jour : vérifiez-les, puis relancez.',
        'not_enough_players' => 'Il faut au moins :min joueurs connectés pour lancer.',
        'pool_insufficient' => 'Seulement :playable films jouables pour :required manches.',
        'game_not_ended' => 'La partie n’est pas encore terminée.',
    ],

    /*
    | Échec technique d’un lancement ou d’un « Rejouer » (spec 50 § 12.5,
    | § 13) : la transaction est annulée, le salon garde son statut — au
    | lobby, ou sur son podium —, et l’hôte peut recommencer.
    */

    'errors' => [
        'launch_failed' => 'Le lancement a échoué. Réessayez dans un instant.',
    ],

    /*
    | Page du salon (spec 50 § 8.1 et § 20.3, lot L50-4) : code et lien de
    | partage, sièges, lancement, annonces du lobby ; gestes d’hôte et départ
    | (spec 50 § 11.3 et § 11.4, lot L50-6) : retirer, nommer hôte, quitter,
    | leurs confirmations et leurs refus ; titre de la section des réglages
    | (L50-9) ; presets, leur grisage et le rapport de changements (L50-5).
    | Les nombres passent en placeholders, formatés par le client.
    */

    'lobby' => [
        'title' => 'Salon',
        'code_label' => 'Code de la partie',
        'copy_code' => 'Copier',
        'copy_room_code' => 'Copier le code',
        'code_copied' => 'Code copié',
        'show_code' => 'Afficher',
        'hide_code' => 'Masquer',
        'copy_link' => 'Copier le lien',
        'link_copied' => 'Lien copié',
        'share_hint' => 'Partagez ce lien ou ce code avec vos amis.',
        'share' => 'Partager',
        'players' => 'Joueurs (:count sur :capacity)',
        'player_count' => ':count / :capacity joueurs',
        'host_badge' => 'Hôte',
        'you' => 'Vous',
        'seat' => [
            'disconnected' => 'Déconnecté',
            'left' => 'Parti',
            'kicked' => 'Retiré',
        ],
        'host_changed' => ':nickname est maintenant l’hôte.',
        'you_are_host' => 'Vous êtes l’hôte : vous réglez et lancez la partie.',
        'read_only' => 'Seul l’hôte peut modifier les réglages.',
        'settings_updated' => 'L’hôte a modifié les réglages.',
        'waiting_for_host' => 'En attente du lancement par l’hôte.',
        'waiting_next_game' => 'Une partie est en cours : vous jouerez la prochaine.',
        'launch' => 'Lancer la partie',
        'launching' => 'Lancement…',
        'need_players' => 'Il faut au moins :min joueurs connectés.',
        'kick' => 'Retirer du salon',
        'kick_confirm' => 'Retirer :nickname ? Ce joueur ne pourra pas revenir dans ce salon.',
        'cannot_kick_self' => 'Vous ne pouvez pas vous retirer vous-même : quittez le salon.',
        'transfer' => 'Nommer hôte',
        'transfer_confirm' => 'Confier le rôle d’hôte à :nickname ?',
        'transfer_unavailable' => 'Ce joueur n’est pas connecté.',
        'leave' => 'Quitter le salon',
        'leave_confirm' => 'Quitter le salon ? Vous pourrez revenir avec le lien.',
        'settings_title' => 'Réglages',
        'configure' => 'Configurer',
        'screen_waiting' => 'La séance va commencer',
        'presets_title' => 'Presets',
        'changes_title' => 'Réglages ajustés',
        'preset_grayed' => 'Pas assez de films pour ce preset : jouable avec :frames images par manche.',
        'preset_unplayable' => 'Pas assez de films pour ce preset.',
        'avatar_taken' => 'Un autre joueur a déjà cet avatar.',
        // Sélecteur d’avatar du siège, au lobby seulement (D55 du 02/10).
        'avatar' => [
            'title' => 'Votre avatar',
            'hint' => 'Vous pouvez le changer jusqu’au lancement de la partie.',
            'change' => 'Changer d’avatar',
            'done' => 'Terminé',
            'apply' => 'Choisir cet avatar',
            'too_fast' => 'Vous changez d’avatar trop vite : réessayez dans une minute.',
            'open' => 'Choisir mon avatar',
            'previous' => 'Avatar précédent',
            'next' => 'Avatar suivant',
        ],
    ],

    /*
    | Vivier au lobby (spec 50 § 9.2 et § 20.3) : compteur et blocage, rendus
    | pour tous à partir du rapport en données ; une cause par cas de
    | `PoolFault`, un remède par cas de `PoolRemedyKind`, construits côté
    | client par une table. `:value` n’existe que pour les deux remèdes qui
    | proposent une valeur.
    */

    'pool' => [
        'counter' => 'Films jouables : :playable pour :required manches.',
        'blocked' => 'Lancement impossible : pas assez de films jouables avec ces réglages.',
        'no_remedy' => 'Le catalogue ne compte pas encore assez de films pour une partie.',
        'cause' => [
            'themeKeys' => 'Les thèmes choisis restreignent trop le choix.',
            'framesPerRound' => 'Trop peu de films ont assez d’images pour ce nombre d’images par manche.',
            'roundsCount' => 'Il y a plus de manches que de films jouables.',
            'noRepeatMovies' => 'Ce salon a déjà joué la plupart des films disponibles.',
        ],
        'themes_pruned' => 'Un thème choisi n’est plus proposé sur le site : il ne filtre plus rien.',
        'remedy' => [
            'open_new_room' => 'Créer un nouveau salon (:count films jouables avec ces réglages)',
            'disable_no_repeat' => 'Autoriser les films déjà joués (:count films jouables)',
            'clear_themes' => 'Retirer les thèmes (:count films jouables)',
            'lower_frames_per_round' => 'Passer à :value images par manche (:count films jouables)',
            'reduce_rounds_count' => 'Jouer :value manches (:count films jouables)',
        ],
    ],

    /*
    | « Rejouer » (spec 50 § 13 et § 20.3, lot L50-7b) : sur le podium, le
    | geste de l’hôte qui ramène le salon au lobby ; les autres joueurs
    | attendent l’hôte.
    */

    'replay' => [
        'action' => 'Rejouer',
        'waiting' => 'En attente de l’hôte pour rejouer.',
    ],

    /*
    | Rattachement automatique d’un siège invité au compte connecté (spec 40
    | § 13.2, D66 du 07/10) : l’avis rendu une fois dans la page du siège.
    */

    'seat' => [
        'claimed' => 'Cette partie est maintenant rattachée à votre compte.',
    ],

    /*
    | Salon expiré (spec 50 § 16.3, lot L50-8) : la page `game/room-expired`,
    | rendue en 410 par le lien d’un salon archivé. Aucune autre information
    | sur le salon ; deux sorties, un nouveau salon et l’accueil.
    */

    'expired' => [
        'title' => 'Salon expiré',
        'description' => 'Ce salon a été fermé après une période d’inactivité. Créez-en un nouveau pour rejouer.',
        'create' => 'Créer un salon',
        'home' => 'Accueil',
    ],

    'presets' => [
        'classic' => [
            'label' => 'Classique',
            'description' => 'Le réglage de référence, équilibré pour une partie entre amis.',
        ],
        'fast' => [
            'label' => 'Rapide',
            'description' => 'Manches courtes et propositions à choix multiples : une partie expédiée.',
        ],
        'hardcore' => [
            'label' => 'Hardcore',
            'description' => 'Plus d’images, plus de temps, saisie libre de bout en bout.',
        ],
        'discovery' => [
            'label' => 'Découverte',
            'description' => 'Manches longues et révélations généreuses, pour découvrir le catalogue.',
        ],
    ],

];
