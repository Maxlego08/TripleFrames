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

    // Spec 60 § 12.7 et § 13.4 (lot L60-9) : lecture seule d’un onglet
    // supplanté par un autre onglet du même siège (`ReadOnlyNotice`), et
    // expulsion par l’hôte, avant le retour à `room.show`.
    'seat' => [
        'superseded' => 'Vous jouez désormais dans un autre onglet : celui-ci n’affiche plus que la partie. Rechargez la page pour y reprendre la main.',
        'kicked' => 'L’hôte vous a retiré du salon.',
    ],

    // Spec 60 § 5.4 (lot L60-13) : `not_revealing`, code du 409 du geste
    // « manche suivante » de l’hôte hors révélation, rendu par le client.
    // Spec 60 § 16.3 (lot L60-15) : `pool_too_small`, refus du démarrage solo
    // quand aucun nombre d’images par manche n’est jouable pour le preset,
    // rendu par le serveur dans la langue de la requête.
    // Spec 60 § 16.5 (lot L60-16) : `round_not_running`, code du 409 des
    // gestes solo « Voir la réponse » et « Passer la manche » hors manche en
    // cours ou après la réponse du joueur, rendu par le client.
    'errors' => [
        'seat_superseded' => 'Cet onglet n’a plus la main : vous jouez dans un autre onglet. Rechargez la page pour la reprendre ici.',
        'not_revealing' => 'La manche suivante ne peut être avancée que pendant la révélation.',
        'pool_too_small' => 'Le catalogue ne compte pas encore assez de films pour ce preset, même avec moins d’images par manche. Choisissez-en un autre.',
        'round_not_running' => 'Ce geste n’est possible que pendant une manche en cours, avant d’avoir répondu.',
    ],

    // Spec 70 § 17 (lot L70-5) : la saisie en texte libre. `rejected`,
    // `attempts_left` et les messages d’état sont rendus par le client à
    // partir des données de la réponse ; `closed` et `too_fast` sont résolus
    // par le serveur, dans la langue de la requête. Aucun texte de refus ne
    // suggère la proximité (décision 13) : jamais « presque », jamais « pas
    // tout à fait ».
    'answer' => [
        'label' => 'Votre réponse',
        'placeholder' => 'Titre du film',
        'submit' => 'Valider',
        'rejected' => 'Ce n’est pas ça.',
        'attempts_left' => '{0} Plus aucune tentative|{1} :count tentative restante|[2,*] :count tentatives restantes',
        'too_fast' => 'Une tentative à la fois.',
        'closed' => 'La saisie est close pour cette manche.',
        'unreadable' => 'Tapez au moins une lettre ou un chiffre.',
        'exhausted' => 'Vous avez utilisé toutes vos tentatives pour cette manche.',
        'text_exhausted' => 'Plus de tentatives en texte libre : les propositions arrivent avec la dernière image.',
        'locked' => 'Trouvé !',
    ],

    // Spec 70 § 17 (lot L70-9) : le QCM. Essai unique et définitif par siège :
    // `wrong` est rendu par le client à partir de l’état `qcm_wrong` de la
    // réponse ; `invalid` est l’erreur de validation (422) d’une chaîne absente
    // des quatre propositions, résolue par le serveur dans la langue de la
    // requête.
    'choices' => [
        'label' => 'Propositions',
        'wrong' => 'Mauvaise proposition : la saisie est close pour cette manche.',
        'invalid' => 'Cette proposition n’existe pas.',
    ],

    // Spec 80 § 15 (lot L80-6) : valeur du palier, score, classement, podium,
    // récapitulatif et aide du barème. Données, jamais phrases : le client
    // formate les nombres et les durées, et insère `:title` dans un fragment
    // portant son attribut `lang`, jamais en texte brut.

    // `tier_value` : texte de la spec 80 (§ 15.2), forme figée par 05 (D29).
    // Le reste du nœud appartient à la spec 60 (§ 19.4, lot L60-14) : écran
    // de manche — décompte, numéro, chrono, clôture, annulation (sans motif
    // ni titre), salon à un seul siège connecté (`lone_player`, jamais
    // « solo »), retardataire en attente, bande des joueurs et fil « a
    // trouvé ». Nombres et heures formatés par le client, jamais ici.
    'round' => [
        'tier_value' => ':points point en jeu|:points points en jeu',
        'number' => 'Manche :number sur :total',
        'starts_in' => 'La manche commence dans :seconds seconde|La manche commence dans :seconds secondes',
        'time_left' => 'Temps restant : :time',
        'time_up' => 'Fin de la manche : la réponse arrive.',
        'cancelled' => 'Manche annulée à la suite d’un incident : elle ne compte pas.',
        'lone_player' => 'Vous êtes le seul joueur connecté.',
        'waiting_next' => 'Vous entrez dans la partie à la manche :number.',
        'players' => 'Joueurs',
        'players_description' => 'Les sièges du salon. La partie continue pendant ce temps.',
        'found' => 'A trouvé',
        'lock_rank' => 'Rang d’arrivée : :rank',
    ],

    // Spec 60 § 9.5 (lot L60-14) : la révélation (D14 du 23/09). Chaque
    // titre est inséré par le client dans un fragment qui porte son `lang`,
    // jamais interpolé en texte brut (`:title`).
    'reveal' => [
        'heading' => 'La réponse',
        'original_title' => 'Titre original : :title',
        'year' => 'Sortie : :year',
        'images' => 'Images de la manche',
        'finders' => 'Ont trouvé',
        'finder_tier' => 'Image :index',
    ],

    // Spec 60 § 14 (lot L60-14) : la pause, et l’heure de clôture formatée
    // par le client dans le fuseau du joueur.
    'pause' => [
        'title' => 'Partie en pause',
        'description' => 'Plus aucun joueur n’était connecté : la partie reprend dès que l’un d’eux revient.',
        'interrupts_at' => 'Sans retour d’un joueur, elle s’arrêtera à :time.',
    ],

    // Spec 60 § 5.4 (lot L60-14) : le seul pouvoir de l’hôte en partie,
    // pendant la révélation.
    'host' => [
        'next_round' => 'Manche suivante',
    ],

    // Spec 60 § 16 (lot L60-16) : la partie solo. Les deux gestes
    // d’entraînement assisté (D18 du 23/09), la manche suivante pendant la
    // révélation, l’absence de mémoire des films déjà vus (§ 16.7) et le
    // nombre d’images par manche ramené d’office (D19 du 23/09) — `:preset`
    // est le libellé traduit du preset, `:requested` et `:applied` des
    // nombres formatés par le client, toujours au pluriel (N ≥ 2).
    'solo' => [
        'reveal_answer' => 'Voir la réponse',
        'skip_round' => 'Passer la manche',
        'next_round' => 'Manche suivante',
        'no_room_memory' => 'En solo, aucune mémoire ne retient les films déjà vus : l’entraînement puise dans tout le catalogue, et un même film peut revenir.',
        'frames_adjusted' => 'Le catalogue ne permet pas encore le preset :preset à :requested images par manche : cette partie se joue à :applied images par manche.',
    ],

    'score' => [
        'points' => ':count point|:count points',
        'bonus' => 'dont :points de bonus de rapidité',
        'gained' => 'Gagné : :points',
        'total' => 'Total : :points',
    ],

    'leaderboard' => [
        'title' => 'Classement',
        'rank_shared' => 'ex æquo',
        'unranked' => 'Sans rang',
        'column' => [
            'rank' => 'Rang',
            'player' => 'Joueur',
            'score' => 'Points',
            'correct_answers' => 'Bonnes réponses',
            'answer_time' => 'Temps cumulé',
            'round_delta' => 'Cette manche',
        ],
        'status' => [
            'left' => 'A quitté la partie',
            'kicked' => 'Expulsé par l’hôte',
        ],
        // Catégorie de `Intl.PluralRules(locale, { type: 'ordinal' })` ; le
        // suffixe est séparé de `:rank` par U+2060 (WORD JOINER), invisible
        // et insécable, pour que `:rank` reste un paramètre (80 § 15.1). Le
        // français ne rend que `one` et `other` : `two` et `few` existent
        // pour la symétrie des clés.
        'ordinal' => [
            'one' => ":rank\u{2060}er",
            'two' => ":rank\u{2060}e",
            'few' => ":rank\u{2060}e",
            'other' => ":rank\u{2060}e",
        ],
        'correct_answers' => ':count bonne réponse|:count bonnes réponses',
        'late_joiner' => 'Entrée à la manche :round',
        'answer_time' => 'Temps cumulé : :duration',
        'round_delta' => '+:points sur cette manche',
    ],

    'podium' => [
        'title' => 'Podium',
        'completed' => 'Partie terminée · :m manches',
        'interrupted' => 'Partie interrompue à la manche :k sur :m',
        'rounds_played' => ':count manche jouée|:count manches jouées',
        'highlights' => [
            'best_answer' => 'Meilleure réponse : :nickname, « :title » (+:points)',
            'fastest_find' => 'Trouvé le plus vite : « :title », par :nickname en :duration',
            'none' => 'Aucune bonne réponse dans cette partie',
            'unfound' => ':count film que personne n’a trouvé|:count films que personne n’a trouvés',
        ],
        // Lot L80-7 (ajout à la liste de la spec 80 § 15.2) : état d’erreur du
        // podium, dont le bouton relance la resynchronisation (§ 20).
        'unavailable' => 'Le podium n’a pas pu être chargé.',
        'retry' => 'Réessayer',
    ],

    'recap' => [
        'title' => 'Récapitulatif des films',
        'round' => 'Manche :number',
        'cancelled' => 'Manche annulée',
        'nobody' => 'Personne n’a trouvé',
        'found_by' => 'Trouvé par :count joueur|Trouvé par :count joueurs',
    ],

    // Écran d'aide (spec 90 § 7.7) : `title`, `description` et `open` de 90 ;
    // `prefix`, règle du préfixe et du sous-titre, de 70 (§ 17) ; `scoring`,
    // nœud du barème, de 80. L'aide ne parle jamais du salon, du tirage ni de
    // films « jouables » : l'ambiguïté se mesure sur le catalogue publié
    // entier (90 § 7.7).
    'help' => [
        'title' => 'Réponses et points',
        'description' => 'Ce qui est accepté comme réponse, et comment les points sont comptés.',
        'open' => 'Aide',
        'prefix' => 'Quand le site compte plusieurs films d’une même saga, le titre de la saga seul, ou un sous-titre partagé, ne suffit pas.',
        'scoring' => [
            'tier_values' => 'Chaque image vaut des points ; avec le barème par défaut, la première, la plus cryptique, rapporte le plus. C’est l’image affichée à l’instant où le serveur reçoit votre réponse qui compte.',
            'speed_bonus' => 'Bonus de rapidité : jusqu’à :percent % de la valeur de l’image, dégressif jusqu’à l’image suivante. Avec le barème par défaut, attendre l’image suivante ne rapporte jamais plus ; au mieux autant.',
            'tie_break' => 'À score égal : le plus de bonnes réponses, puis le temps cumulé le plus court, puis le plus de trouvailles sur les premières images. Sinon, la place est partagée, jamais tirée au sort.',
            'no_penalty' => 'Une mauvaise réponse ne coûte aucun point.',
            'cancelled_round' => 'Une manche annulée à la suite d’un incident ne compte pas : ni points, ni manche jouée.',
        ],
    ],

];
