<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `game` : manche, palier, saisie, révélation, podium
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. `frame.alt` y figure parce que la spec
    | 05 l’impose nommément (§ Erreurs, validation et messages à destinataire
    | unique) : l’attribut `alt` d’une image de jeu est **neutre et traduit**,
    | il ne décrit jamais le contenu de l’image — le décrire donnerait la
    | réponse à un lecteur d’écran comme à un curieux du DOM.
    |
    | `frame.*` et `a11y.*` sont rédigés par la spec 90 (§ 6.4 et § 6.5,
    | lot L90-6a) : états du cadre d’image, et annonces de manche lues par
    | l’annonceur, aux seuils relatifs de la spec 90 § 7.4. Les autres
    | préfixes appartiennent aux specs 60, 70 et 80.
    |
    */

    'frame' => [
        'alt' => 'Frame :index of :total in the current round',
        'loading' => 'Loading frame…',
        'unavailable' => 'Frame unavailable.',
    ],

    'a11y' => [
        'choices_shown' => 'The answer choices are now shown.',
        'halfway' => 'Halfway through the round.',
        'last_quarter' => 'Last quarter of the round.',
        'round_ended' => 'Round over.',
        'seconds_left' => ':count second left.|:count seconds left.',
        'tier_opened' => 'New frame, :index of :total.',
    ],

    // Spec 60 § 12.7 et § 13.4 (lot L60-9) : lecture seule d’un onglet
    // supplanté par un autre onglet du même siège (`ReadOnlyNotice`), et
    // expulsion par l’hôte, avant le retour à `room.show`.
    'seat' => [
        'superseded' => 'You are now playing in another tab: this one only shows the game. Reload the page to take over here.',
        'kicked' => 'The host removed you from the room.',
    ],

    // Spec 60 § 5.4 (lot L60-13) : `not_revealing`, code du 409 du geste
    // « manche suivante » de l’hôte hors révélation, rendu par le client.
    'errors' => [
        'seat_superseded' => 'This tab is no longer in control: you are playing in another tab. Reload the page to take over here.',
        'not_revealing' => 'The next round can only be brought forward during the reveal.',
    ],

    // Spec 70 § 17 (lot L70-5) : la saisie en texte libre. `rejected`,
    // `attempts_left` et les messages d’état sont rendus par le client à
    // partir des données de la réponse ; `closed` et `too_fast` sont résolus
    // par le serveur, dans la langue de la requête. Aucun texte de refus ne
    // suggère la proximité (décision 13) : jamais « almost », jamais « not
    // quite ».
    'answer' => [
        'label' => 'Your answer',
        'placeholder' => 'Film title',
        'submit' => 'Submit',
        'rejected' => 'That’s not it.',
        'attempts_left' => '{0} No attempts left|{1} :count attempt left|[2,*] :count attempts left',
        'too_fast' => 'One attempt at a time.',
        'closed' => 'Answers are closed for this round.',
        'unreadable' => 'Type at least one letter or digit.',
        'exhausted' => 'You have used all your attempts for this round.',
        'text_exhausted' => 'No free-text attempts left: the choices will appear with the last frame.',
        'locked' => 'Found it!',
    ],

    // Spec 70 § 17 (lot L70-9) : le QCM. Essai unique et définitif par siège :
    // `wrong` est rendu par le client à partir de l’état `qcm_wrong` de la
    // réponse ; `invalid` est l’erreur de validation (422) d’une chaîne absente
    // des quatre propositions, résolue par le serveur dans la langue de la
    // requête.
    'choices' => [
        'label' => 'Choices',
        'wrong' => 'Wrong choice: answers are closed for this round.',
        'invalid' => 'This choice does not exist.',
    ],

    // Spec 80 § 15 (lot L80-6) : valeur du palier, score, classement, podium,
    // récapitulatif et aide du barème. Données, jamais phrases : le client
    // formate les nombres et les durées, et insère `:title` dans un fragment
    // portant son attribut `lang`, jamais en texte brut.

    'round' => [
        'tier_value' => ':points point at stake|:points points at stake',
    ],

    'score' => [
        'points' => ':count point|:count points',
        'bonus' => 'including :points speed bonus',
        'gained' => 'Earned: :points',
        'total' => 'Total: :points',
    ],

    'leaderboard' => [
        'title' => 'Standings',
        'rank_shared' => 'tied',
        'unranked' => 'Unranked',
        'column' => [
            'rank' => 'Rank',
            'player' => 'Player',
            'score' => 'Points',
            'correct_answers' => 'Correct answers',
            'answer_time' => 'Total time',
            'round_delta' => 'This round',
        ],
        'status' => [
            'left' => 'Left the game',
            'kicked' => 'Removed by the host',
        ],
        // Catégorie de `Intl.PluralRules(locale, { type: 'ordinal' })` ; le
        // suffixe est séparé de `:rank` par U+2060 (WORD JOINER), invisible
        // et insécable, pour que `:rank` reste un paramètre (80 § 15.1).
        'ordinal' => [
            'one' => ":rank\u{2060}st",
            'two' => ":rank\u{2060}nd",
            'few' => ":rank\u{2060}rd",
            'other' => ":rank\u{2060}th",
        ],
        'correct_answers' => ':count correct answer|:count correct answers',
        'late_joiner' => 'Joined at round :round',
        'answer_time' => 'Total time: :duration',
        'round_delta' => '+:points this round',
    ],

    'podium' => [
        'title' => 'Final standings',
        'completed' => 'Game over · :m rounds',
        'interrupted' => 'Game interrupted at round :k of :m',
        'rounds_played' => ':count round played|:count rounds played',
        'highlights' => [
            'best_answer' => 'Best answer: :nickname, “:title” (+:points)',
            'fastest_find' => 'Fastest find: “:title”, by :nickname in :duration',
            'none' => 'No correct answer in this game',
            'unfound' => ':count film nobody found|:count films nobody found',
        ],
        // Lot L80-7 (ajout à la liste de la spec 80 § 15.2) : état d’erreur du
        // podium, dont le bouton relance la resynchronisation (§ 20).
        'unavailable' => 'The final standings could not be loaded.',
        'retry' => 'Try again',
    ],

    'recap' => [
        'title' => 'Films recap',
        'round' => 'Round :number',
        'cancelled' => 'Round cancelled',
        'nobody' => 'Nobody found it',
        'found_by' => 'Found by :count player|Found by :count players',
    ],

    // Écran d'aide (spec 90 § 7.7) : `title`, `description` et `open` de 90 ;
    // `prefix`, règle du préfixe et du sous-titre, de 70 (§ 17) ; `scoring`,
    // nœud du barème, de 80. L'aide ne parle jamais du salon, du tirage ni de
    // films « jouables » : l'ambiguïté se mesure sur le catalogue publié
    // entier (90 § 7.7).
    'help' => [
        'title' => 'Answers and points',
        'description' => 'What counts as an answer, and how points are scored.',
        'open' => 'Help',
        'prefix' => 'When the site has several movies from the same saga, the saga title alone, or a shared subtitle, is not enough.',
        'scoring' => [
            'tier_values' => 'Each image is worth points; with the default scoring, the first, most cryptic one pays the most. What counts is the image on screen when the server receives your answer.',
            'speed_bonus' => 'Speed bonus: up to :percent% of the image’s value, shrinking until the next image. With the default scoring, waiting for the next image never pays more; at best the same.',
            'tie_break' => 'On equal scores: most correct answers, then shortest total time, then most finds on the earliest images. Otherwise the place is shared, never drawn at random.',
            'no_penalty' => 'A wrong answer costs no points.',
            'cancelled_round' => 'A round cancelled after an incident does not count: no points, no round played.',
        ],
    ],

];
