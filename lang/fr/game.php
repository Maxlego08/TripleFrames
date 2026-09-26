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

    'errors' => [
        'seat_superseded' => 'Cet onglet n’a plus la main : vous jouez dans un autre onglet. Rechargez la page pour la reprendre ici.',
    ],

    // Spec 80 § 15 (lot L80-6) : valeur du palier, score, classement, podium,
    // récapitulatif et aide du barème. Données, jamais phrases : le client
    // formate les nombres et les durées, et insère `:title` dans un fragment
    // portant son attribut `lang`, jamais en texte brut.

    'round' => [
        'tier_value' => ':points point en jeu|:points points en jeu',
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
    ],

    'recap' => [
        'title' => 'Récapitulatif des films',
        'round' => 'Manche :number',
        'cancelled' => 'Manche annulée',
        'nobody' => 'Personne n’a trouvé',
        'found_by' => 'Trouvé par :count joueur|Trouvé par :count joueurs',
    ],

    'help' => [
        'scoring' => [
            'tier_values' => 'Chaque image vaut des points ; avec le barème par défaut, la première, la plus cryptique, rapporte le plus. C’est l’image affichée à l’instant où le serveur reçoit votre réponse qui compte.',
            'speed_bonus' => 'Bonus de rapidité : jusqu’à :percent % de la valeur de l’image, dégressif jusqu’à l’image suivante. Avec le barème par défaut, attendre l’image suivante ne rapporte jamais plus ; au mieux autant.',
            'tie_break' => 'À score égal : le plus de bonnes réponses, puis le temps cumulé le plus court, puis le plus de trouvailles sur les premières images. Sinon, la place est partagée, jamais tirée au sort.',
            'no_penalty' => 'Une mauvaise réponse ne coûte aucun point.',
            'cancelled_round' => 'Une manche annulée à la suite d’un incident ne compte pas : ni points, ni manche jouée.',
        ],
    ],

];
