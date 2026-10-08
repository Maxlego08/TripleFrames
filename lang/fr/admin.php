<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `admin` : back-office de curation — français uniquement
    |--------------------------------------------------------------------------
    |
    | Le back-office est en français seulement (décision 9), mais 100 % de ses
    | textes passent par des clés : aucune chaîne en dur, même là. Il n’existe
    | donc **pas** de `lang/en/admin.php`, et c’est pourquoi le middleware
    | `App\Http\Middleware\ForceAdminLocale` force `Locale::French` sur tout le
    | groupe de routes d’administration — sans lui, un curateur dont le compte
    | est en `en` verrait s’afficher des clés brutes.
    |
    | Ce domaine est le seul exclu de la symétrie de clés vérifiée en CI, et il
    | n’est jamais expédié dans la charge utile d’un écran joueur.
    |
    | Piège à connaître avant d’écrire un écran : `ForceAdminLocale` appelle
    | `TranslationDomains::need('admin')`, et `TranslationDomains::selected()`
    | rend alors `['admin']` **seul** — `common` n’est PAS joint. Toute clé
    | appelée par une page d’administration vit donc ici, y compris les verbes
    | les plus banals (`admin.common.*`).
    |
    | Exception à connaître avant d’écrire ici : les messages sortants vers un
    | tiers extérieur (accusé de réception d’une demande de retrait,
    | notification de décision, réponse à un signalement) existent en FR **et**
    | EN et vivent dans le domaine `mail`, pas ici.
    |
    */

    'title' => 'Back-office',

    /*
    | Coquille du panneau — navigation et repères d’accessibilité. Les libellés
    | de rôle nomment le SEUIL franchi, jamais le compte : un administrateur
    | passe la porte du curateur parce qu’il est au-dessus du seuil.
    */
    'nav' => [
        'section' => 'Curation',
        'dashboard' => 'Tableau de bord',
        'curation' => 'File de curation',
        'catalog' => 'Catalogue',
        'near_misses' => 'Suggestions d’alias',
        // Films jamais trouvés et incidents (ligne 29, L20-29).
        'incidents' => 'Incidents',
        // Les signalements de contenu par les joueurs (ligne 48, D63 du 07/10).
        'content_reports' => 'Signalements',
        // L'écran des thèmes (spec 20 § 9.6, D43 du 01/10).
        'themes' => 'Thèmes',
        'import' => 'Import',
        // Les lots d'images (ligne 46, D57 du 05/10).
        'frame_batch' => 'Lots d’images',
        'review' => 'Revue',
        'throughput' => 'Débit',
        'guide' => 'Premiers pas',
        // Le groupe de l'administrateur seul (spec 20 § 2.8) : invisible
        // pour un curateur.
        'administration' => 'Administration',
        'users' => 'Comptes',
        'access' => 'Accès',
        // Le journal d'administration (ligne 41, D41 du 30/09).
        'journal' => 'Journal',
        // L'inspection des parties et des sièges (lignes 36 et 42, D46 du 01/10).
        'games' => 'Parties',
        'players' => 'Joueurs',
        // Les performances (ligne 43, D47 du 01/10).
        'performance' => 'Performances',
        // L'audience (ligne 44, D48 du 01/10).
        'audience' => 'Audience',
        // Les avatars téléversés (ligne 45, D49 du 01/10).
        'avatars' => 'Avatars',
        // La modération des pseudos (ligne 35, D66 du 07/10).
        'moderation' => 'Pseudos',
        // Le geste rétroactif de grille (ligne 33, L20-25), administrateur seul.
        'exclusion_grid' => 'Grille rétroactive',
        'back_to_site' => 'Retour au site',
        'role' => [
            'curator' => 'Curateur',
            'admin' => 'Administrateur',
        ],
    ],

    'a11y' => [
        'nav' => 'Navigation du back-office',
        'main' => 'Contenu principal',
        'filters_form' => 'Filtres du catalogue',
        'sort_by' => 'Trier par :column',
        'open_movie' => 'Ouvrir la fiche de :title',
        'curate_movie' => 'Curer les images de :title',
        'curation_filters_form' => 'Filtres de la file de curation',
        'open_run' => 'Ouvrir le balayage n° :id',
        'run_status' => 'État du balayage : :status',
        'pagination' => 'Pagination',
        // Annuaire des comptes et gestion des accès (spec 20 § 2.8) : `:name`,
        // le pseudo du compte.
        'users_filters_form' => 'Filtres de l’annuaire des comptes',
        'access_search_form' => 'Recherche d’un compte par son adresse',
        'open_account' => 'Ouvrir la fiche du compte :name',
        'change_role_of' => 'Changer le rôle de :name',
        'correct_real_name_of' => 'Corriger le nom réel de :name',
        // Journal d'administration (ligne 41, D41 du 30/09).
        'journal_filters_form' => 'Filtres du journal d’administration',
        'journal_open_subject' => 'Ouvrir :subject',
        'journal_open_actor' => 'Ouvrir la fiche du compte de :name',
        // Coquille mobile de `admin-sidebar.tsx` : titre et description
        // accessibles de SA feuille, jamais le « Sidebar » anglais figé de
        // `ui/sidebar` (spec 20 § 13.4, 90 § 2.5).
        'nav_mobile' => 'Menu du back-office',
        'nav_mobile_description' => 'Les écrans de curation et le retour au site.',
        // Fermeture propre de toute feuille et de toute boîte de dialogue du
        // back-office : la fermeture générée porte « Close », en anglais.
        'close' => 'Fermer',
    ],

    /*
    | Pied du back-office (`admin-footer.tsx`) : ses propres clés, jamais le
    | domaine `legal`, que le back-office ne reçoit pas (C15 § 2.3). Les liens
    | mènent aux pages légales du site ; l'attribution TMDB y est visible comme
    | sur tout écran (principe 12).
    */
    'footer' => [
        'label' => 'Informations légales',
        'notice' => 'Mentions légales',
        'terms' => 'Conditions générales d’utilisation',
        'privacy' => 'Politique de confidentialité',
        'tmdb_attribution' => 'Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB.',
        'tmdb_logo_alt' => 'Logo de TMDB',
    ],

    /*
    | Écran d'enrôlement du second facteur (`admin/two-factor-required`), où la
    | garde `admin.2fa` renvoie tout compte curateur ou administrateur sans
    | double authentification confirmée (spec 20 § 2.4). Jamais un 403 muet :
    | l'écran dit pourquoi la porte est fermée et mène à la sécurité du compte.
    | Aucune procédure technique ici : la perte du second facteur au-delà des
    | codes de secours relève de l'exploitation, hors du chemin du curateur.
    */
    'two_factor' => [
        'title' => 'Double authentification requise',
        'heading' => 'Activez la double authentification pour entrer',
        'description' => 'Le back-office est fermé à tout compte curateur ou administrateur dont la double authentification n’est pas confirmée.',
        'why' => [
            'heading' => 'Pourquoi cette porte',
            'body' => 'Chaque revue, chaque publication et chaque ligne du journal d’administration portent votre nom réel. Un mot de passe dérobé suffirait à publier au nom du projet ; un second facteur, produit par votre téléphone, le rend inutile à lui seul.',
        ],
        'steps' => [
            'heading' => 'Marche à suivre',
            'open' => 'Ouvrez la sécurité de votre compte ; votre mot de passe vous sera redemandé.',
            'enable' => 'Activez la double authentification, puis scannez le code affiché avec une application d’authentification.',
            'confirm' => 'Saisissez le code que produit l’application : c’est cette confirmation, et elle seule, qui ouvre la porte.',
            'recovery' => 'Rangez vos codes de secours en lieu sûr, ailleurs que sur votre téléphone : ils sont votre seule entrée si vous le perdez.',
            'return' => 'Revenez ici, puis entrez dans le back-office.',
        ],
        'action' => [
            'open_security' => 'Ouvrir la sécurité du compte',
            'enter' => 'J’ai confirmé : entrer dans le back-office',
        ],
        'confirmed' => [
            'heading' => 'Double authentification confirmée',
            'description' => 'Votre compte est protégé par un second facteur : la porte du back-office est ouverte.',
            'enter' => 'Entrer dans le back-office',
        ],
    ],

    /*
    | Page « premiers pas du curateur » (`admin/guide`, spec 20 § 13.6, lot
    | L20-18) : livrée avec l'outil, et condition du lot pilote. Elle ne
    | recopie aucun texte normatif : l'échelle (`level.*`), la grille courante
    | (`exclusion_grid.v{n}.*`) et les raccourcis (`shortcuts.*`) y sont rendus
    | depuis leurs propres clés, que le serveur envoie — un libellé ne vit qu'à
    | un seul endroit. Ce bloc ne porte que ce qui relie et explique.
    |
    | `floor.rule` : `:width` et `:surface` sont des pourcentages déjà mis en
    | forme ; `floor.min_width` : `:min_width`, un nombre de pixels mis en
    | forme. `grid.heading` : `:version`, la version courante de la grille ;
    | `grid.levels` : `:levels`, une liste de niveaux ;
    | `scale.specific_items` : `:items`, les libellés des points de la grille
    | propres à ce niveau.
    */
    'guide' => [
        'title' => 'Premiers pas du curateur',
        'heading' => 'Premiers pas du curateur',
        'description' => 'Ce qu’il faut savoir avant de curer un premier film : les deux passes, l’échelle des niveaux, le plancher de recadrage, la grille d’exclusion, la revue, la publication, écarter, les raccourcis, et ce qu’il ne faut jamais faire.',
        'open_queue' => 'Ouvrir la file de curation',
        // Déconnexion pendant une visite partie de cette page : rien n'a été
        // envoyé, il n'y avait rien à saisir.
        'offline' => 'Connexion perdue : l’écran demandé n’a pas pu s’ouvrir. Vérifiez votre réseau, puis réessayez.',
        'toc' => [
            'label' => 'Sommaire de la page',
            'heading' => 'Sommaire',
        ],
        'passes' => [
            'heading' => 'Les deux passes',
            'intro' => 'Un film n’a pas une suite d’images ordonnée : il a une banque d’images, et chaque image porte un niveau, de 1, très cryptique, à 5, évident. Chaque manche tire ses images dans cette banque. Un film se cure en deux passes.',
            'one' => 'Passe 1 : une variante jouable à chacun des niveaux 1, 3 et 5. Elle suffit à publier le film, qui entre alors dans le vivier des parties.',
            'two' => 'Passe 2 : une deuxième variante aux niveaux 1, 3 et 5, et une variante aux niveaux 2 et 4. C’est un objectif de curation, jamais une condition de publication : un film publié s’enrichit sans quitter le jeu.',
            'steps' => [
                'heading' => 'Le parcours d’un film',
                'open' => 'Ouvrez le film suivant de la file de curation. Les films entamés viennent d’abord : vous reprenez là où vous vous étiez arrêté.',
                'crop' => 'Dans la banque d’images, ouvrez un visuel TMDB — ou, quand l’écran le propose, envoyez une capture : une image du film prise par vous, avec son minutage dans le film —, cadrez l’image, choisissez son niveau, puis ajoutez-la. Elle part en traitement : passez à la suivante sans l’attendre.',
                'review' => 'Une fois l’image traitée, passez-la en revue sur son rendu final : une revue conforme la publie.',
                'publish' => 'Quand le contenu du film est vérifié et que les niveaux 1, 3 et 5 ont chacun une image en jeu, publiez le film.',
                'next' => '« Film suivant » vous mène au prochain film de la file.',
            ],
        ],
        'scale' => [
            'heading' => 'L’échelle de 1 à 5',
            'specific_items' => 'Propre à ce niveau dans la grille d’exclusion : :items.',
            'change' => 'Le niveau d’une image se change ensuite depuis la banque d’images. Une image publiée qui change de niveau sort du jeu et repasse en revue.',
        ],
        'floor' => [
            'heading' => 'Le plancher de recadrage, et pourquoi',
            'rule' => 'Le cadre, toujours au format 16:9, ne reprend jamais plus de :width de la largeur ni de la hauteur du visuel : il en couvre au plus :surface de la surface, quel que soit le format du visuel.',
            'min_width' => 'Il ne descend pas non plus sous :min_width pixels de large : l’image de jeu serait trop agrandie, donc floue.',
            'why' => 'Pourquoi : un visuel repris presque entier se retrouve en une recherche d’image inversée, et la manche se gagnerait sans avoir vu le film ; un cadre resserré, non. C’est aussi un engagement du projet : une image de jeu est une image transformée, jamais un visuel redistribué tel quel, et cet engagement doit se vérifier, pas seulement se déclarer.',
            'checked' => 'Le recadreur vous arrête au plancher, et le serveur le revérifie à l’ajout de l’image puis à son traitement : un cadre hors du plancher n’entre jamais en jeu.',
        ],
        'grid' => [
            'heading' => 'La grille d’exclusion, version :version',
            'intro' => 'Chaque revue répond à chacun des points qui s’appliquent au niveau de l’image : un seul point en défaut suffit à la rejeter. Les points se jugent sur le rendu final, jamais sur l’aperçu du recadreur.',
            'versioned' => 'Votre revue cite cette version de la grille. Un point publié ne se réécrit jamais : une grille modifiée devient une nouvelle version, et les images publiées sous l’ancienne rejoignent la liste « À re-revoir ».',
            'items_label' => 'Points de la grille',
            'levels_all' => 'Tous les niveaux',
            'levels' => 'Niveaux :levels',
        ],
        'review' => [
            'heading' => 'La revue et la source déclarée',
            'final_render' => 'Une image n’entre en jeu qu’après une revue de son rendu final, tel qu’un joueur le verra : dans le cadre sombre du jeu, à la largeur d’un téléphone et à celle d’un ordinateur.',
            'gestures' => '« Conforme, publier » répond « conforme » à chacun des points affichés et publie l’image. « Non conforme » vous fait cocher les points en défaut, puis « Rejeter » : l’image rejoint la liste « Rejetées », d’où elle peut être revue, re-recadrée ou écartée.',
            'source' => 'La source de l’image s’affiche en lecture seule : le chemin du visuel TMDB, ou le minutage d’une capture. Votre revue la déclare, datée et signée de votre nom réel : c’est la preuve de l’origine de l’image, et elle ne se déclare jamais après coup.',
            'immutable' => 'Une revue ne se modifie ni ne se supprime. Une image recadrée, changée de niveau ou dépubliée sort du jeu, et n’y revient que par une nouvelle revue.',
            'open' => 'Ouvrir la passe de revue',
        ],
        'publication' => [
            'heading' => 'La publication et l’avertissement d’ambiguïté',
            'explicit' => 'Rien ne se publie seul : publier un film est votre geste. « Publier le film » s’active quand le contenu est vérifié, que les niveaux 1, 3 et 5 ont chacun une image en jeu et que le film a au moins un titre à deviner ; sinon, le bouton nomme ce qui manque.',
            'frames_first' => 'Une image se publie par sa revue, même quand son film est encore un brouillon : c’est ainsi que se construit la passe 1.',
            'ambiguity' => 'Avant de confirmer, l’écran liste les réponses que la publication rendra ambiguës, et les films qui les portent déjà. L’ambiguïté se mesure sur tout le catalogue publié, et elle ne change jamais un score déjà attribué.',
            'stale' => 'Si le catalogue a changé entre l’aperçu et votre clic, la publication est refusée et l’aperçu se réaffiche : l’avertissement que vous lisez n’est jamais périmé.',
        ],
        'set_aside' => [
            'heading' => 'Écarter un film ou une image',
            'movie' => 'Un film incurable — aucun visuel TMDB exploitable, et aucune capture possible — sort de la file par « Écarter le film », avec un motif obligatoire inscrit au journal. Rien n’est perdu : un film écarté peut toujours être curé, puis publié.',
            'unpublish_movie' => 'Dépublier un film publié le sort du vivier, motif obligatoire ; ses images restent publiées. Il revient en jeu par « Republier le film ».',
            'frame' => 'Écarter une image jamais publiée la met de côté pour de bon : elle ne repasse jamais en revue, et pour réutiliser son visuel, on ajoute une nouvelle variante. Dépublier une image publiée la sort du jeu ; elle y revient par une nouvelle revue.',
            'coverage' => 'Si le geste retire à un film publié sa dernière image jouable au niveau 1, 3 ou 5, l’écran le dit avant confirmation : le film reste publié, mais incomplet, et l’écran annonce jusqu’à combien d’images par manche il reste jouable.',
        ],
        'shortcuts' => [
            'operability' => 'Sans eux, tout se fait aussi au clavier : Tab parcourt l’écran ; dans la grille des visuels, les flèches passent d’un visuel à l’autre, et Entrée ou Espace l’ouvre dans le cadre ; le cadre sélectionné se déplace aux flèches, s’élargit avec « + », se resserre avec « - », et Origine rétablit le cadre par défaut ; dans le choix du niveau, les flèches changent de niveau.',
        ],
        'never' => [
            'heading' => 'Ce qu’il ne faut jamais faire',
            'poster' => 'Publier une affiche, une jaquette ou un visuel promotionnel : seul un photogramme du film est admis.',
            'credits' => 'Publier un plan de générique, un carton-titre ou un logo de studio.',
            'set_photo' => 'Publier une photo de plateau ou un portrait posé d’acteur : le droit à l’image de l’acteur s’ajoute au droit d’auteur sur l’œuvre.',
            'delete' => 'Supprimer un film, une image ou une revue : c’est impossible, et c’est voulu. Un film ou une image s’écarte ou se dépublie ; une revue se corrige par une nouvelle revue.',
            'alias_for_title' => 'Corriger un titre par un alias : un titre faux se corrige sur la fiche du film, dans sa langue. Un alias ajoute une réponse acceptée ; il ne change pas le titre que voient les joueurs.',
        ],
    ],

    'common' => [
        'none' => 'Aucun',
        'unknown' => 'Inconnu',
        'yes' => 'Oui',
        'no' => 'Non',
        'all' => 'Tous',
        'search' => 'Rechercher',
        'submit' => 'Valider',
        'reset' => 'Réinitialiser',
        'refresh' => 'Rafraîchir',
        'empty' => 'Rien à afficher.',
        'loading' => 'Chargement en cours…',
        'error' => 'Ces données n’ont pas pu être rechargées.',
        'read_only' => 'Lecture seule',
        'deleted_account' => 'Auteur supprimé',
        'cancel' => 'Annuler',
        // Motif obligatoire d'un geste consigné au journal (C14) : l'envoi
        // reste inactif tant qu'il est vide, et ce texte dit pourquoi.
        'reason_required' => 'Saisissez un motif : il est inscrit tel quel au journal d’administration et sur la fiche du film.',
        // Déconnexion ou erreur réseau pendant une visite : rien n'est parti,
        // et le formulaire reste tel quel (spec 20 § 6.8, § 13.5).
        'offline' => 'Connexion perdue : rien n’a été envoyé et votre saisie est conservée. Vérifiez votre réseau, puis réessayez.',

        /*
        | Formats de liaison. Ce ne sont pas des phrases, mais la ponctuation
        | EST une convention de langue — l'espace insécable avant le
        | deux-points n'existe qu'en français — et la règle 4 veut que rien de
        | visible ne s'écrive hors dictionnaire. Le prochain écran, lui, sera
        | bilingue.
        */
        'label_value' => ':label : :value',
        'list_separator' => ' · ',
        'at_least' => '≥ :value',
    ],

    /*
    | Pagination maison. Les composants `PaginationPrevious` et
    | `PaginationNext` de shadcn embarquent « Previous » et « Next » EN DUR
    | dans un fichier interdit de modification : la pagination du back-office
    | est donc bâtie sur `Button` et `<Link>`, et ses libellés vivent ici.
    */
    'pagination' => [
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'summary' => ':from à :to sur :total',
        'page' => 'Page :current sur :last',
    ],

    /*
    | Valeurs d’énumération. Une colonne d’état ne s’affiche jamais brute : un
    | curateur lit « Publié », pas « published ».
    */
    'enum' => [
        'availability' => [
            'draft' => 'Brouillon',
            'published' => 'Publié',
            'unpublished' => 'Dépublié',
            'suspended' => 'Suspendu',
            'withdrawn' => 'Retiré',
        ],
        'content_flag' => [
            'clear' => 'Contenu vérifié',
            'blocked' => 'Bloqué',
            'unrated_pending' => 'Classification à vérifier',
        ],
        'import_source' => [
            'discover' => 'Balayage',
            'paste' => 'Collage',
            'demo' => 'Démonstration',
        ],
        'import_run_kind' => [
            'discover' => 'Balayage discover',
            'paste' => 'Collage d’identifiants',
            'resync' => 'Resynchronisation',
        ],
        'import_run_status' => [
            'running' => 'En cours',
            'queued' => 'En file',
            'completed' => 'Terminé',
            'failed' => 'Échoué',
        ],
        'movie_difficulty' => [
            'very_easy' => 'Très facile',
            'easy' => 'Facile',
            'medium' => 'Moyen',
            'hard' => 'Difficile',
            'very_hard' => 'Très difficile',
        ],
        'content_origin' => [
            'tmdb' => 'TMDB',
            'curator' => 'Curateur',
        ],
        'theme_kind' => [
            'genre' => 'Genre',
            'decade' => 'Décennie',
            'studio' => 'Studio',
            'saga' => 'Saga',
            'language' => 'Langue',
            'difficulty' => 'Difficulté',
        ],
        'theme_membership' => [
            'added' => 'Ajouté à la main',
            'removed' => 'Retiré à la main',
        ],
        'tmdb_tag_kind' => [
            'genre' => 'Genre',
            'company' => 'Société',
        ],
        'certification_country' => [
            'FR' => 'France',
            'US' => 'États-Unis',
        ],
        'frame_processing' => [
            'pending' => 'En attente',
            'ready' => 'Prête',
            'failed' => 'Échec',
        ],
        'locale' => [
            'fr' => 'Français',
            'en' => 'Anglais',
        ],
        'user_role' => [
            'player' => 'Joueur',
            'curator' => 'Curateur',
            'admin' => 'Administrateur',
        ],
        'oauth_provider' => [
            'discord' => 'Discord',
            'google' => 'Google',
        ],
        /*
        | Journal `admin_action` : une feuille par cas de la liste fermée
        | (`AdminActionType::labelKey()`, points remplacés par `_`) et par
        | sujet (`AdminActionSubject::labelKey()`).
        */
        'admin_action' => [
            'role_changed' => 'Changement de rôle',
            'user_real_name_changed' => 'Nom réel corrigé',
            'movie_published' => 'Film publié',
            'movie_unpublished' => 'Film dépublié',
            'movie_republished' => 'Film republié',
            'movie_content_verified' => 'Contenu du film vérifié',
            'movie_suspended' => 'Film suspendu',
            'movie_unsuspended' => 'Suspension du film levée',
            'movie_withdrawn' => 'Film retiré (retrait juridique)',
            'frame_unpublished' => 'Image dépubliée',
            'frame_grid_unpublished' => 'Image dépubliée après révision de la grille',
            'frame_suspended' => 'Image suspendue',
            'frame_unsuspended' => 'Suspension de l’image levée',
            'frame_withdrawn' => 'Image retirée (retrait juridique)',
            'avatar_hidden' => 'Avatar masqué',
            'avatar_unhidden' => 'Avatar rétabli',
            'avatar_removed' => 'Avatar retiré',
            'nickname_masked' => 'Pseudo masqué',
            'nickname_unmasked' => 'Pseudo rétabli',
            'nickname_banned' => 'Pseudo banni',
            'takedown_decided' => 'Demande de retrait décidée',
            'site_closed' => 'Site fermé',
            'site_reopened' => 'Site rouvert',
            'movie_title_saved' => 'Titre corrigé',
            'movie_title_removed' => 'Titre retiré',
            'movie_alias_added' => 'Alias ajouté',
            'movie_alias_removed' => 'Alias retiré',
            'movie_grouped' => 'Film regroupé',
            'movie_ungrouped' => 'Film retiré de son groupe',
            'movie_theme_set' => 'Thème du film changé',
            'frame_added' => 'Image ajoutée',
            'frame_recropped' => 'Image recadrée',
            'frame_processing_retried' => 'Traitement de l’image relancé',
            'frame_level_changed' => 'Niveau de l’image changé',
            'frame_reviewed' => 'Image revue',
            'movie_frames_reviewed' => 'Images du film validées en lot',
            'import_discover_started' => 'Balayage TMDB lancé',
            'import_paste_started' => 'Collage d’identifiants lancé',
            'import_seed_list_started' => 'Lot de la liste d’amorçage lancé',
            'import_resumed' => 'Balayage repris',
            'accounts_directory_viewed' => 'Annuaire des comptes consulté',
            'accounts_access_viewed' => 'Écran des accès consulté',
            'user_looked_up' => 'Compte recherché par adresse',
            'user_viewed' => 'Fiche du compte consultée',
            'games_directory_viewed' => 'Liste des parties consultée',
            'game_viewed' => 'Fiche de la partie consultée',
            'players_directory_viewed' => 'Annuaire des joueurs consulté',
            'player_viewed' => 'Fiche du joueur consultée',
            'theme_created' => 'Thème créé',
            'theme_updated' => 'Thème corrigé',
            'theme_published' => 'Thème publié',
            'theme_unpublished' => 'Thème dépublié',
            'content_report_dismissed' => 'Signalements ignorés',
            'movie_resynced' => 'Film resynchronisé depuis TMDB',
            'import_abandoned' => 'Balayage clos à la main',
            'movie_difficulty_corrected' => 'Difficulté du film corrigée',
        ],
        'admin_action_subject' => [
            'movie' => 'Film',
            'frame' => 'Image',
            'user' => 'Compte',
            'player' => 'Joueur',
            'takedown_request' => 'Demande de retrait',
            'site' => 'Site',
            'import_run' => 'Balayage d’import',
            'accounts' => 'Comptes',
            'theme' => 'Thème',
            'game' => 'Partie',
            'games' => 'Parties',
            'players' => 'Joueurs',
            'content_report' => 'Signalement de contenu',
        ],
    ],

    /*
    | Tableau de bord — supervision, aucune écriture (spec 20 § 8.6). Une tuile
    | à zéro est une information : elle s’affiche, elle ne s’escamote pas.
    | `pool.*` : le vivier CATALOGUE compté en œuvres (contrat C2, n° 24), un
    | plafond et jamais le vivier d’un salon. `curation.*` et `frames.*` :
    | le travail restant, chaque compteur menant à la liste qu’il annonce.
    */
    'dashboard' => [
        'title' => 'Tableau de bord',
        'heading' => 'Tableau de bord de curation',
        'description' => 'L’état du catalogue, du vivier, de la curation et des derniers balayages. Cet écran ne modifie rien.',

        'availability' => [
            'heading' => 'Disponibilité',
            'description' => 'Répartition des films du catalogue par état de disponibilité.',
            'total' => 'Total au catalogue',
        ],

        'content_flag' => [
            'heading' => 'Drapeau de contenu',
            'description' => 'Un film n’est publiable que si son contenu a été vérifié (décision 12).',
        ],

        'pool' => [
            'heading' => 'Vivier catalogue, par nombre d’images',
            'description' => 'Œuvres jouables à N images par manche : films publiés, au contenu vérifié, couvrant assez de niveaux. Des films regroupés comme une même œuvre comptent pour une seule.',
            'frames_per_round' => 'N = :count',
            'works' => 'œuvres',
            'empty' => 'Aucune œuvre n’entre encore dans le vivier catalogue.',
            'scope_notice' => 'Vivier catalogue : ni thème, ni non-répétition. C’est un plafond, jamais le vivier d’un salon — un lobby, qui écarte aussi les films déjà joués, affiche toujours un nombre inférieur ou égal.',
        ],

        'curation' => [
            'heading' => 'Curation des films',
            'description' => 'Les films prêts à publier, les films publiés devenus incomplets et les films écartés.',
            'ready_to_publish' => 'Prêts à publier',
            'ready_to_publish_hint' => 'Brouillons au contenu vérifié, niveaux 1, 3 et 5 en jeu.',
            'incomplete' => 'Publiés incomplets',
            'incomplete_hint' => 'Couverture 1, 3 et 5 perdue : ils restent jouables avec repli de niveau.',
            'set_aside' => 'Écartés',
            'set_aside_hint' => 'Sortis de la file sans être publiés.',
            'see_list' => 'Voir la liste : :label',
        ],

        'frames' => [
            'heading' => 'Images à traiter',
            'description' => 'Le travail de revue en attente, et les traitements d’image qui ont échoué.',
            'to_review' => 'À revoir',
            'to_rereview' => 'À re-revoir',
            'rejected' => 'Rejetées',
            'failed' => 'Traitement en échec',
            'see_review' => 'Ouvrir la file de revue',
        ],

        'set_aside' => [
            'heading' => 'Films écartés',
            'description' => 'Les derniers films sortis de la file sans être publiés, avec leur motif. Les curer puis les publier en fait une première publication.',
            'empty' => 'Aucun film n’a été écarté.',
            'reason' => 'Motif',
            'no_reason' => 'Aucun motif enregistré',
            'set_aside_at' => 'Écarté le',
            'see_all' => 'Voir tous les films écartés',
        ],

        'coverage' => [
            'heading' => 'Couverture d’images',
            'description' => 'Variantes disponibles par niveau, et films dont la banque d’images couvre les niveaux exigés.',
            'level' => 'Niveau :level',
            'variants' => 'variantes',
            'movies_at_level' => 'films pourvus',
            'variants_total' => 'Variantes, tous niveaux',
            'publishable' => 'Films couvrant 1, 3 et 5',
            'without_frames' => 'Films sans aucune image',
            'single_variant' => 'Niveaux à variante unique',
            'passes_hint' => 'Un niveau à variante unique sert la même image à tout le salon, à chaque tirage.',
        ],

        'queue' => [
            'heading' => 'Tête de la file de curation',
            'description' => 'Les premiers films de la file : d’abord les films entamés, du plus récemment touché au plus ancien, puis les autres par nombre de votes décroissant.',
            'empty' => 'Aucun brouillon en attente de curation.',
            'see_all' => 'Ouvrir la file de curation',
            'unrated_pending' => 'Films non publiables tant que le contenu n’est pas vérifié : :count',
        ],

        'exceptions' => [
            'heading' => 'Entrées par exception',
            'description' => 'Films entrés hors du filtre de notoriété, et par quel motif (décision 11).',
            'total' => 'Entrés par exception',
        ],

        'runs' => [
            'heading' => 'Derniers balayages',
            'description' => 'Les cinq derniers imports, leur état et leurs quatre compteurs.',
            'empty' => 'Aucun balayage n’a encore été lancé.',
            'see_all' => 'Voir l’écran d’import',
        ],

        'tmdb_disabled' => 'Aucune clé TMDB n’est configurée : l’import est indisponible. Le jeu, lui, n’appelle jamais TMDB.',
    ],

    /*
    | File de curation (spec 20 § 4.1) : les brouillons hors démonstration,
    | films entamés d’abord puis par votes décroissants. « Film suivant »
    | mène à l’éditeur du premier film de la file autre que le courant ;
    | `empty` est aussi le message rendu quand il n’en reste aucun.
    | `filters.*` : les filtres qui composent le lot pilote par voie.
    | `results` : `:total` est un nombre déjà mis en forme ; `row.touched_at` :
    | `:moment` une date déjà mise en forme.
    */
    'curation' => [
        'title' => 'File de curation',
        'heading' => 'File de curation',
        'description' => 'Les films à curer, dans l’ordre de travail : d’abord les films entamés, du plus récemment touché au plus ancien, puis les autres par nombre de votes décroissant. Le catalogue de démonstration n’y figure pas.',
        'next' => 'Film suivant',
        'next_hint' => 'Ouvre l’éditeur du premier film de la file que personne d’autre ne cure, filtres conservés.',
        // La réservation souple (spec 20 § 4.1, L20-32) : un film ouvert par
        // un autre curateur, que « Film suivant » saute.
        'claimed_by' => 'En cours chez :name',
        'claimed_hint' => 'Un autre curateur travaille sur ce film : « Film suivant » le saute. La réservation tombe d’elle-même après quelques minutes sans activité.',
        'empty' => 'Aucun autre film n’attend dans la file de curation. Importez de nouveaux films pour la remplir.',
        'empty_filtered' => 'Aucun film de la file ne correspond à ces filtres. Effacez-les pour revoir toute la file.',
        'empty_stratum' => 'Aucun autre film de cette sélection n’attend dans la file. Effacez les filtres pour revoir toute la file.',
        'go_import' => 'Ouvrir l’import',
        'results' => ':total film(s) dans la file au filtre courant',

        'filters' => [
            'heading' => 'Filtres',
            'description' => 'Choisissez une voie d’entrée pour constituer le lot pilote, stratifié entre films du balayage et films entrés par exception. Les filtres ne changent jamais l’ordre de la file.',
            'entry' => [
                'label' => 'Voie d’entrée',
                'discover' => 'Balayage',
                'exception' => 'Entrés par exception',
            ],
            'motive' => 'Motif d’exception',
            'content_flag' => 'Drapeau de contenu',
            'submit' => 'Filtrer',
            'reset' => 'Tout effacer',
        ],

        'totals' => [
            'heading' => 'Reste à curer, par voie d’entrée',
            'description' => 'Toute la file, filtres ignorés : de quoi constituer chaque strate du lot pilote.',
            'discover' => 'Voie du balayage',
            'exception' => 'Voie d’exception',
        ],

        'column' => [
            'rank' => 'Rang',
            'title' => 'Titre original',
            'year' => 'Année',
            'votes' => 'Votes',
            'entry' => 'Voie',
            'content_flag' => 'Contenu',
            'levels' => 'Niveaux',
            'variants' => 'Variantes',
            'progress' => 'Avancement',
            'actions' => 'Actions',
        ],

        'row' => [
            'started' => 'Entamé',
            'not_started' => 'Non entamé',
            'touched_at' => 'Touché le :moment',
            'curate' => 'Curer',
            'open' => 'Fiche',
        ],
    ],

    /*
    | Débit de curation et verdict du lot pilote (spec 20 § 10.2 à § 10.4) :
    | un agrégat, jamais nominatif. Toutes les substitutions reçoivent des
    | valeurs DÉJÀ mises en forme par l’écran (nombres, durées, dates) :
    | `:count`, `:quota`, `:rank` des nombres ; `:duration`, `:hours` des
    | durées ; `:moment` une date.
    */
    'throughput' => [
        'title' => 'Débit de curation',
        'heading' => 'Débit de curation et lot pilote',
        'description' => 'Le temps actif de curation par film et le temps de recadrage par image, mesurés en base pendant la passe 1. Agrégat seulement : aucun curateur n’est nommé. Le catalogue de démonstration est exclu.',
        'refresh' => 'Rafraîchir',
        'empty' => 'Aucun film réel n’est encore terminé : un film l’est à sa première publication, ou quand il est écarté.',
        'go_curation' => 'Ouvrir la file de curation',
        'no_value' => 'Sans mesure',
        // Heures hebdomadaires de curation non déclarées : la projection en
        // semaines est remplacée par ce texte, jamais par un quotient.
        'hours_undeclared' => 'Heures hebdomadaires de curation non déclarées : aucune projection en semaines. Elles se déclarent avant le lot pilote.',

        'entry' => [
            'discover' => 'Voie du balayage',
            'exception' => 'Voie d’exception',
            'total' => 'Deux voies',
        ],

        'error' => [
            'title' => 'Le tableau du débit n’a pas pu être rechargé.',
            'description' => 'Les chiffres affichés sont ceux du dernier chargement réussi.',
            'retry' => 'Réessayer',
        ],

        'pilot' => [
            'heading' => 'Lot pilote',
            'description' => 'Les :discover premiers films terminés de la voie du balayage et les :exception premiers de la voie d’exception, soit :size films, comptés à partir du rang fixé avant le lot pour chaque voie. Un film écarté compte comme un échec.',
            'progress' => ':count sur :quota films terminés',
            'first_rank' => 'Comptés à partir du rang :rank de la voie',
            'full' => 'Quota atteint',
            'open' => 'En cours',
            'pending' => 'Le verdict attend que chaque voie ait son quota de films terminés.',
        ],

        'verdict' => [
            'heading' => 'Verdict du pilote',
            'filled_at' => 'Fenêtre du pilote remplie le :moment. Ce verdict ne bouge plus : recopiez-le, daté, dans le compte rendu du pilote, qui fait foi.',
            'disqualified_title' => 'Outil disqualifié',
            'disqualified' => 'Le temps actif total du pilote, :duration, dépasse le seuil de :hours. L’outil est re-livré avant toute curation de masse, puis un nouveau pilote est mené.',
            'qualified_title' => 'Temps actif dans la limite',
            'qualified' => 'Le temps actif total du pilote, :duration, tient sous le seuil de disqualification de :hours.',
            'outside_back_office' => 'Toute intervention hors du back-office sur le chemin du curateur pendant le pilote disqualifie aussi l’outil : elle se consigne dans le compte rendu du pilote, pas sur cet écran.',
            'total_active' => 'Temps actif total, films écartés compris',
            'p90' => 'p90 du temps actif par film publié',
            'failures' => 'Films écartés, comptés comme échecs',
            'films' => 'Films du pilote',
            'j1_heading' => 'Films du jalon 1',
            'j1_kept' => 'Le jalon 1 reste à :count films : le p90 multiplié par les :remaining films restant après le pilote tient dans la réserve de curation de :hours.',
            'j1_reduced' => 'Le jalon 1 compte :count films : le p90 multiplié par les :remaining films restant après le pilote dépasse la réserve de curation de :hours, qui, divisée par le p90, donne ce nombre.',
            'no_published' => 'Aucun film publié dans le pilote : sans p90, ni le nombre de films du jalon 1 ni la cible de volume ne se calculent.',
            'volume_heading' => 'Cible de volume',
            'volume_target' => 'Cible de volume : :count films, au p90 de la passe 1, plafonnée à :cap films.',
            'volume_undeclared' => 'Heures de curation non déclarées : aucune cible de volume. Déclarez avant le lot les heures hebdomadaires et l’horizon en semaines.',
        ],

        'projection' => [
            'heading' => 'Projection',
            'description' => 'Temps de curation projeté : la cible multipliée par le p90 du temps actif par film publié, puis divisé par les heures hebdomadaires déclarées.',
            'indicative' => 'Projection indicative au p90 de tous les films terminés : elle suit la curation, là où le verdict du pilote est figé.',
            'target' => 'Cible',
            'films' => 'Films',
            'hours' => 'Heures',
            'weeks' => 'Semaines',
            'j1' => 'Jalon 1',
            'volume' => 'Volume',
            'unavailable' => 'Pas de projection sans film publié.',
        ],

        'measures' => [
            'pilot_heading' => 'Mesures du lot pilote',
            'pilot_description' => 'Les films de la fenêtre du pilote, par voie d’entrée. Seules comptent les images créées avant la terminaison de leur film : la passe 2 n’entre pas dans la mesure.',
            'all_heading' => 'Tous les films terminés',
            'all_description' => 'Tous les films réels terminés, pilote compris, par voie d’entrée.',
            'column' => [
                'entry' => 'Voie',
                'films' => 'Films terminés',
                'published' => 'Publiés',
                'set_aside' => 'Écartés',
                'active_total' => 'Temps actif total',
                'active_median' => 'Médiane par film publié',
                'active_p90' => 'p90 par film publié',
                'crop_frames' => 'Images recadrées',
                'crop_median' => 'Médiane du recadrage',
                'crop_p90' => 'p90 du recadrage',
            ],
        ],

        'set_aside' => [
            'heading' => 'Films écartés et motifs',
            'description' => 'Les films terminés par un écart plutôt qu’une publication, dans l’ordre de leur terminaison. Un film écarté puis publié plus tard reste un échec de la mesure.',
            'empty' => 'Aucun film terminé n’a été écarté.',
            'in_pilot' => 'Pilote',
            'no_reason' => 'Aucun motif enregistré',
            'column' => [
                'title' => 'Film',
                'entry' => 'Voie',
                'reason' => 'Motif',
                'terminated_at' => 'Écarté le',
            ],
        ],
    ],

    /*
    | Réponses texte récurrentes, agrégées sans donnée de joueur. Une ligne
    | n'apparaît qu'après trois manches distinctes ; elle reste une suggestion
    | jusqu'au geste explicite d'un curateur.
    */
    /*
    | La file des signalements de contenu par les joueurs (spec 20 § 11.6,
    | D63 du 07/10) : groupée par cible, curateur et au-delà. Aucun effet
    | automatique ; dépublier reprend les gestes du catalogue et de la banque.
    */
    'content_report' => [
        'title' => 'Signalements de contenu',
        'heading' => 'Signalements de contenu',
        'description' => 'Films et images signalés par les joueurs depuis la révélation ou le podium. Un signalement ne change rien tout seul : à vous de décider.',
        'empty' => 'Aucun signalement à examiner.',
        'empty_closed' => 'Aucun signalement traité.',
        'list' => 'Cibles signalées',
        'filters' => [
            'label' => 'Afficher',
            'open' => 'À traiter',
            'closed' => 'Traités',
        ],
        'counts' => [
            'open_targets' => 'Cibles à traiter : :count',
            'open_reports' => 'Signalements ouverts : :count',
        ],
        'scope' => [
            'frame' => 'Image',
            'movie' => 'Film entier',
        ],
        'reasons' => [
            'wrong_movie' => 'Pas ce film, ou fiche fausse',
            'title_visible' => 'Titre ou texte révélateur lisible',
            'wrong_level' => 'Niveau inadapté',
            'poor_quality' => 'Mauvaise qualité',
            'offensive' => 'Contenu choquant',
            'other' => 'Autre',
        ],
        'resolution' => [
            'movie_unpublished' => 'Film dépublié',
            'frame_unpublished' => 'Image dépubliée',
            'dismissed' => 'Ignoré',
            'already_handled' => 'Déjà hors jeu',
        ],
        'column' => [
            'target' => 'Cible',
            'reasons' => 'Motifs',
            'comments' => 'Derniers commentaires',
            'reported' => 'Signalé',
            'actions' => 'Actions',
        ],
        'reports_count' => ':count signalement(s)',
        'reported_between' => 'Du :first au :last',
        'frame_level' => 'Niveau :level',
        'thumbnail_alt' => 'Image signalée de « :title »',
        'movie_link' => 'Fiche du film',
        'bank_link' => 'Banque d’images',
        'coverage_warning' => 'Dépublier cette image casse la couverture 1-3-5 : le film restera jouable jusqu’à :max images par manche.',
        'coverage_warning_unplayable' => 'Dépublier cette image rend le film injouable.',
        'actions' => [
            'unpublish_movie' => 'Dépublier le film',
            'unpublish_frame' => 'Dépublier l’image',
            'dismiss' => 'Ignorer',
        ],
        'resolved_on' => 'Traité le :date',
        'availability' => 'État actuel : :state',
        'reason_required_label' => 'Motif (obligatoire)',
        'reason_optional_label' => 'Motif (facultatif), inscrit au journal',
        'gesture_label' => ':action — :title',
        'dialogs' => [
            'unpublish_movie_title' => 'Dépublier « :title » ?',
            'unpublish_movie_description' => 'Le film sort du jeu et tous ses signalements ouverts sont clos. Le motif est obligatoire.',
            'unpublish_frame_title' => 'Dépublier cette image ?',
            'unpublish_frame_description' => 'L’image sort du jeu et ses signalements ouverts sont clos.',
            'dismiss_title' => 'Ignorer ces signalements ?',
            'dismiss_description' => 'La cible reste en jeu ; ses signalements ouverts sont clos et le geste est journalisé.',
        ],
        'flash' => [
            'movie_unpublished' => 'Film dépublié. Signalements clos : :count.',
            'frame_unpublished' => 'Image dépubliée. Signalements clos : :count.',
            'dismissed' => 'Signalements ignorés : :count.',
        ],
    ],

    /*
    | Films jamais trouvés et incidents (spec 20 § 12.1, ligne 29, L20-29) :
    | agrégat par film, sans aucune identité de joueur.
    */
    'incidents' => [
        'title' => 'Incidents',
        'heading' => 'Films jamais trouvés et incidents',
        'description' => 'Sur les :days derniers jours : les manches que personne n’a trouvées, les manches annulées et les images remplacées en cours de manche, film par film. Aucun joueur n’y est nommé.',
        'empty' => 'Aucun incident sur cette période.',
        'list' => 'Films concernés',
        'reaction' => 'Une réaction est un geste de curation ordinaire : une autre variante, un niveau revu, un alias.',
        'column' => [
            'movie' => 'Film',
            'never_found' => 'Jamais trouvé',
            'cancelled' => 'Manches annulées',
            'substituted' => 'Images remplacées',
        ],
        'never_found' => ':never sur :completed manches terminées',
        'reason_count' => ':reason : :count',
        'none' => '—',
    ],

    'near_misses' => [
        'title' => 'Suggestions d’alias',
        'heading' => 'Suggestions d’alias',
        'description' => 'Des réponses refusées qui reviennent dans au moins trois parties différentes. Vérifiez qu’elles désignent bien le film avant de les accepter.',
        'refresh' => 'Actualiser les suggestions',
        'empty' => 'Aucune formulation récurrente à examiner.',
        'list' => 'Formulations à examiner',
        'column' => [
            'movie' => 'Film',
            'answer' => 'Réponse proposée',
            'frequency' => 'Fréquence',
            'period' => 'Mois observés',
            'locale' => 'Langue de l’alias',
            'actions' => 'Actions',
        ],
        'frequency' => ':occurrences saisie(s) dans :rounds partie(s) · écart au titre :distance (plus petit = plus proche)',
        'period' => 'Du :first au :last',
        'alias_label' => 'Graphie de l’alias proposé depuis « :answer »',
        'locale_label' => 'Langue de l’alias « :answer »',
        'promote' => 'Accepter comme alias',
        'dismiss_label' => 'Ignorer la suggestion « :answer »',
        'flash' => [
            'refreshed' => 'Suggestions actualisées : :count à examiner.',
            'promoted' => 'La formulation est désormais acceptée comme alias.',
            'dismissed' => 'La suggestion a été ignorée.',
        ],
    ],

    'catalog' => [
        'title' => 'Catalogue',
        'heading' => 'Catalogue des films',
        'description' => 'Liste en lecture seule. Chaque film se publie, se dépublie, s’écarte et se corrige depuis sa fiche ; les films prêts se publient aussi ensemble.',
        'results' => ':total film(s) au filtre courant',

        // « Publier les films prêts » (spec 20 § 8.1 bis, D59 du 06/10).
        'publish_ready' => [
            'action' => 'Publier les films prêts',
            'title' => 'Publier les films prêts',
            'description' => 'Les brouillons au contenu vérifié dont les niveaux 1, 3 et 5 sont en jeu. Chacun est publié comme depuis sa fiche, à votre nom, et entre au vivier des parties lancées ensuite. Les films dépubliés ou écartés n’y figurent pas : ils se republient depuis leur fiche.',
            'list_heading' => 'Films publiés par ce geste',
            'ambiguity_description' => 'Pour chaque film, les formes que la publication rendra ambiguës, les autres films du lot comptés comme publiés. Un préfixe ou un sous-titre ambigu n’est plus accepté seul ; les titres complets et les alias restent acceptés.',
            'none_ambiguous' => 'Aucune forme ne deviendra ambiguë.',
            'skipped_heading' => 'Prêts, mais pas publiables',
            'skipped_description' => 'Ces films ne seront pas publiés : corrigez-les depuis leur fiche.',
            'empty_title' => 'Aucun film prêt à publier',
            'empty_description' => 'Un brouillon devient prêt quand son contenu est vérifié et que ses niveaux 1, 3 et 5 ont chacun une image en jeu.',
            'movie' => ':title (:year)',
            'movie_without_year' => ':title',
            'submit' => 'Publier :count film|Publier les :count films',
            'stale' => 'Le lot a changé depuis son affichage (un film n’est plus prêt, ou un avertissement d’ambiguïté a changé) : rien n’a été publié. Relisez le lot mis à jour, puis confirmez de nouveau.',
            'flash' => ':count film publié : il entre au vivier des parties lancées désormais.|:count films publiés : ils entrent au vivier des parties lancées désormais.',
        ],

        'filters' => [
            'heading' => 'Filtres',
            'search' => [
                'label' => 'Recherche',
                'placeholder' => 'Titre original ou identifiant TMDB',
                'hint' => 'Recherche sur le titre original et sa translittération latine ; une saisie entièrement numérique cherche aussi l’identifiant TMDB.',
            ],
            'availability' => 'Disponibilité',
            'content_flag' => 'Drapeau de contenu',
            'import_source' => 'Voie d’entrée',
            'playable_at' => [
                'label' => 'Jouable à N images',
                'option' => 'N = :count et plus',
            ],
            'exception' => [
                'label' => 'Entrés par exception',
                'any' => 'Toutes les exceptions',
                'language' => 'Motif : langue originale',
                'vote_count' => 'Motif : notoriété',
                'release_year' => 'Motif : année de sortie',
            ],
            // La file « titres manquants » (spec 20 § 9.3) : les films
            // publiés d’abord.
            'missing_title' => [
                'label' => 'Titre manquant',
                'fr' => 'Sans titre en français',
                'en' => 'Sans titre en anglais',
                'hint' => 'Lu dans la projection à jour ; les films publiés viennent en tête.',
            ],
            'curation_status' => [
                'label' => 'État de curation',
                'ready_to_publish' => 'Prêts à publier',
                'incomplete' => 'Publiés incomplets',
                'set_aside' => 'Écartés',
            ],
            // Les films actifs dans un thème, publié ou non (spec 20 § 9.6).
            'theme' => [
                'label' => 'Thème',
                'unpublished' => ':label (non publié)',
            ],
            'submit' => 'Filtrer',
            'reset' => 'Tout effacer',
        ],

        'facets' => [
            'heading' => 'Entrées par exception, par motif',
            'description' => 'Décision 11 : un film entré hors filtre est marqué, et le marquage n’est jamais silencieux.',
            'total' => 'Entrés par exception',
            'language' => 'Motif langue',
            'vote_count' => 'Motif notoriété',
            'release_year' => 'Motif année',
            'scope_notice' => 'Ces quatre nombres sont mesurés sur le jeu filtré courant PRIVÉ de la seule facette « entrés par exception » — les autres filtres, eux, s’appliquent.',
        ],

        'exception' => [
            'badge' => 'Exception',
            'motive' => [
                'language' => 'Langue originale hors filtre',
                'vote_count' => 'Sous le seuil de notoriété',
                'release_year' => 'Sortie avant l’année minimale',
            ],
        ],

        'sort' => [
            'label' => 'Tri',
            'created_at' => 'Date d’entrée',
            'title_original' => 'Titre original',
            'release_year' => 'Année',
            'vote_count' => 'Votes',
            'levels_count' => 'Niveaux couverts',
            'variants_total' => 'Variantes',
            'direction' => [
                'asc' => 'Croissant',
                'desc' => 'Décroissant',
            ],
        ],

        'column' => [
            'title' => 'Titre original',
            'year' => 'Année',
            'language' => 'Langue',
            'votes' => 'Votes',
            'availability' => 'Disponibilité',
            'content_flag' => 'Contenu',
            'source' => 'Voie d’entrée',
            'levels' => 'Niveaux',
            'variants' => 'Variantes',
            'entered_at' => 'Entré le',
            'actions' => 'Actions',
        ],

        'row' => [
            'open' => 'Ouvrir',
        ],

        'empty' => [
            'heading' => 'Aucun film',
            'filtered' => 'Aucun film ne correspond à ces filtres. Effacez-les pour revoir tout le catalogue.',
            'no_movies' => 'Le catalogue est vide : lancez un balayage depuis l’écran d’import.',
        ],

        /*
        | Import du catalogue — motifs portés par `ImportOutcome::$reasonKey`.
        | Une ligne par motif, avec exactement les substitutions que le code
        | fournit : `certification` reçoit toujours `:country` et
        | `:certification`, `withdrawn` toujours `:reason` (éventuellement vide).
        */
        'import' => [
            // `--actor` nomme un compte ; une valeur inconnue est signalée puis
            // ignorée — la traçabilité ne vaut pas qu'on refuse d'importer.
            'actor_unknown' => 'Auteur :actor inconnu : le balayage sera enregistré sans auteur.',
            'refused' => [
                'adult' => 'Refusé : TMDB classe ce film en contenu pour adultes (décision 12).',
                'certification' => 'Refusé : classification :certification en :country (décision 12).',
                'withdrawn' => 'Refusé : film retiré du catalogue par la curation. Motif : :reason',
            ],
            'skipped' => [
                'duplicate' => 'Ignoré : ce film est déjà au catalogue.',
                'filter' => 'Ignoré : sous le filtre de notoriété (décision 11). Employez la voie d’exception pour le forcer.',
                'not_found' => 'Ignoré : TMDB ne connaît pas cet identifiant.',
            ],
        ],
    ],

    /*
    | Fiche film — LECTURE SEULE. Aucun geste d’écriture n’est offert ici :
    | publier, dépublier, suspendre, retirer, corriger un titre et cocher
    | « contenu vérifié » ont chacun leur seuil, tous tranchés par la spec 20.
    */
    'movie' => [
        'title' => 'Fiche film',
        'heading' => 'Fiche film',
        'back' => 'Retour au catalogue',
        'read_only_notice' => 'Les images du film se curent dans l’éditeur de la banque d’images. Le film se publie ici ou depuis l’éditeur, et se dépublie ou s’écarte ici ; ses titres, ses alias et son regroupement se corrigent dans leurs onglets.',
        'curate' => 'Curer les images',

        'tabs' => [
            'identity' => 'Identité',
            'titles' => 'Titres et alias',
            'tags' => 'Étiquettes TMDB',
            'projection' => 'Projection',
            'themes' => 'Thèmes',
            'frames' => 'Banque d’images',
            'group' => 'Même œuvre',
            'import' => 'Import',
        ],

        'identity' => [
            'heading' => 'Identité',
            // L'identifiant du film AU CATALOGUE, que le regroupement manuel
            // demande (spec 20 § 9.4) — distinct de l'identifiant TMDB.
            'id' => 'Identifiant catalogue',
            'tmdb_id' => 'Identifiant TMDB',
            'tmdb_link' => 'Ouvrir sur themoviedb.org',
            'tmdb_missing' => 'Aucun identifiant TMDB',
            'title_original' => 'Titre original',
            'title_latin' => 'Translittération latine',
            'original_language' => 'Langue originale',
            'release_year' => 'Année de sortie',
            'vote_count' => 'Votes TMDB',
            'adult' => 'Marqué « adulte » par TMDB',
            'collection' => 'Saga TMDB',
            'group' => 'Groupe d’homonymes',
            'difficulty' => 'Difficulté effective',
            'difficulty_derived' => 'Difficulté dérivée',
            'difficulty_override' => 'Correction manuelle',
            'created_at' => 'Entré au catalogue le',
            'updated_at' => 'Mis à jour le',
        ],

        /*
        | Difficulté corrigée (spec 20 § 9.6, L20-28b) : la correction survit
        | au réimport ; « Aucune correction » rend la main à la dérivation. La
        | correction met à jour les thèmes de difficulté du film aussitôt.
        */
        'difficulty' => [
            'heading' => 'Corriger la difficulté',
            'description' => 'La difficulté effective sert au seul thème « difficulté ». Une correction prime sur la valeur dérivée de la notoriété et n’est jamais réécrite par un réimport.',
            'label' => 'Difficulté corrigée',
            'derived_option' => 'Aucune correction (valeur dérivée)',
            'derived_hint' => 'Valeur dérivée actuelle : :value.',
            'submit' => 'Enregistrer la difficulté',
            'flash' => [
                'saved' => 'Difficulté enregistrée : les thèmes de difficulté du film sont à jour.',
                'unchanged' => 'Rien n’a changé : cette difficulté était déjà en place.',
            ],
        ],

        // Le lien vers l'écran de resynchronisation (spec 20 § 3.7).
        'resync' => [
            'action' => 'Resynchroniser depuis TMDB',
        ],

        // La dépublication proposée par une resynchronisation (spec 20
        // § 3.7) : motif pré-rempli, modifiable.
        'unpublish_proposed' => [
            'default_reason' => 'Certification restrictive découverte à la resynchronisation TMDB.',
        ],

        'availability' => [
            'heading' => 'Disponibilité',
            'state' => 'État',
            'changed_at' => 'Changé le',
            'reason' => 'Motif du changement',
            'first_published_at' => 'Première mise en jeu',
            'content_flag' => 'Drapeau de contenu',
            'content_verified_by' => 'Contenu vérifié par',
            'content_verified_at' => 'Contenu vérifié le',
            'not_verified' => 'Contenu jamais vérifié',
            'publishable' => 'Publiable : contenu vérifié ET niveaux 1, 3 et 5 couverts.',
            'blocked_by_content' => 'Non publiable : le contenu n’est pas vérifié (les niveaux 1, 3 et 5, eux, sont couverts).',
            'blocked_by_levels' => 'Non publiable : les niveaux 1, 3 et 5 ne sont pas tous couverts (le contenu, lui, est vérifié).',
            'blocked_by_both' => 'Non publiable : ni le contenu vérifié, ni les niveaux 1, 3 et 5 couverts.',
        ],

        /*
        | Titres (spec 20 § 9.1) : un titre par locale ACTIVÉE, corrigé en
        | `origin = curator` — protégé de toute resynchronisation —, retiré
        | seulement s'il vient d'un curateur. `:locale` est le nom de la
        | langue. Les lignes d'autres locales de catalogue restent en lecture
        | seule. Aucun titre n'est recopié d'une langue à l'autre : l'absence
        | d'une ligne est l'information.
        */
        'titles' => [
            'heading' => 'Titres affichables',
            'description' => 'Un titre sert à AFFICHER le film dans la langue d’un joueur, et il est aussi accepté comme réponse. Corrigé ici, il ne sera jamais réécrit par une resynchronisation TMDB.',
            'column' => [
                'locale' => 'Locale de catalogue',
                'title' => 'Titre',
                'origin' => 'Origine',
                'edited_by' => 'Corrigé par',
                'coverage' => 'Couverture',
                'actions' => 'Gestes',
            ],
            // La couverture par langue, lue dans le masque de la projection
            // (spec 20 § 9.3) : ce que le tirage des propositions interroge.
            'coverage' => [
                'present' => 'Présent',
                'absent' => 'Absent',
                'stale' => 'Masque à reprojeter',
            ],
            'missing' => 'Aucun titre',
            'missing_hint' => 'L’absence d’un titre est une information : aucun titre n’est recopié d’une autre langue. Le joueur de cette langue voit le titre d’une autre langue activée, sinon le titre original.',
            'other_locales' => 'Autres locales de catalogue — lecture seule',
            'other_locales_description' => 'Ces titres ne sont ni affichés à un joueur ni acceptés comme réponse : seules les langues activées du jeu le sont.',
            'tmdb_hint' => 'Un titre TMDB se corrige, il ne se retire pas : la resynchronisation suivante le recréerait.',
            'edit' => 'Corriger',
            'edit_label' => 'Corriger le titre : :locale',
            'add' => 'Saisir',
            'add_label' => 'Saisir le titre : :locale',
            'remove' => 'Retirer',
            'remove_label' => 'Retirer le titre : :locale',
            'dialog' => [
                'title_edit' => 'Corriger le titre — :locale',
                'title_add' => 'Saisir le titre — :locale',
                'description' => 'Le titre est enregistré comme correction de curateur : aucune resynchronisation TMDB ne le réécrira. Il est aussi accepté comme réponse, avec son préfixe et son sous-titre.',
                'field' => 'Titre affiché',
                'submit' => 'Enregistrer le titre',
            ],
            'remove_dialog' => [
                'title' => 'Retirer le titre — :locale',
                'description' => 'Seule une correction de curateur se retire. Cette langue restera sans titre jusqu’à la prochaine resynchronisation TMDB, qui la repourvoira si TMDB en connaît un ; aucun titre ne sera recopié d’une autre langue.',
                'submit' => 'Retirer le titre',
            ],
            'destroy' => [
                'missing' => 'Ce film n’a aucun titre dans cette langue : rien à retirer.',
                'not_curator' => 'Ce titre vient de TMDB : il se corrige, il ne se retire pas — la prochaine resynchronisation le recréerait.',
            ],
            'flash' => [
                'saved' => 'Titre enregistré : il est affiché et accepté comme réponse.',
                'removed' => 'Titre retiré.',
            ],
        ],

        /*
        | Alias (spec 20 § 9.2) : ajoutés dans une langue activée, retirés
        | quelle que soit leur origine, TMDB compris. Un alias valide une
        | réponse, il n'est jamais affiché, et il ne donne ni préfixe ni
        | sous-titre. `:alias` est le texte de l'alias.
        */
        'aliases' => [
            'heading' => 'Alias acceptés',
            'description' => 'Un alias sert à VALIDER une réponse, jamais à afficher le film. Tous les alias de toutes les langues activées sont acceptés, quel que soit le joueur.',
            'column' => [
                'locale' => 'Locale',
                'alias' => 'Alias',
                'origin' => 'Origine',
                'created_by' => 'Ajouté par',
                'actions' => 'Gestes',
            ],
            'empty' => 'Aucun alias.',
            'add' => [
                'heading' => 'Ajouter un alias',
                'locale' => 'Langue de l’alias',
                'locale_placeholder' => 'Choisir une langue',
                'field' => 'Alias',
                'hint' => 'Une variante que les joueurs tapent vraiment : « seigneur des anneaux 2 » est un alias, jamais une règle. Un alias ne donne ni préfixe ni sous-titre, et un titre ne se corrige jamais par un alias.',
            ],
            'dialog' => [
                'title' => 'Ajouter l’alias',
                'description' => 'L’alias sera accepté comme réponse dans tous les salons, quel que soit le joueur. Il n’est jamais affiché.',
                'submit' => 'Ajouter l’alias',
            ],
            'remove' => 'Retirer',
            'remove_label' => 'Retirer l’alias « :alias »',
            'remove_dialog' => [
                'title' => 'Retirer l’alias',
                'description' => '« :alias » ne sera plus accepté comme réponse, sauf si un titre ou un autre alias du film donne la même forme.',
                'tmdb_notice' => 'Cet alias vient de TMDB : la prochaine resynchronisation le fera réapparaître.',
                'submit' => 'Retirer l’alias',
            ],
            'locale_not_enabled' => 'Cette langue n’est pas une langue activée du jeu : un alias n’y serait accepté nulle part.',
            'flash' => [
                'added' => 'Alias ajouté : il est accepté comme réponse.',
                'removed' => 'Alias retiré : il n’est plus accepté comme réponse.',
            ],
        ],

        /*
        | Les formes acceptées du film (spec 20 § 9.2), en lecture seule :
        | ce que le jeu compare vraiment à une réponse. `kind.*` : une feuille
        | par nature de clé de réponse, en tête de cellule.
        */
        'answer_keys' => [
            'heading' => 'Formes acceptées',
            'description' => 'Ce que le jeu compare réellement à une réponse : la forme normalisée de chaque titre, alias, préfixe et sous-titre. Lecture seule — corrigez un titre ou un alias pour la changer.',
            'column' => [
                'form' => 'Forme normalisée',
                'kind' => 'Nature',
                'status' => 'Acceptation',
            ],
            'exact' => 'Toujours acceptée',
            'accepted' => 'Acceptée',
            'ambiguous' => 'Refusée seule : un autre film publié porte cette forme',
            'empty' => 'Aucune forme acceptée : aucun titre de ce film ne peut se saisir comme réponse.',
            'kind' => [
                'title_original' => 'Titre original',
                'title_latin' => 'Titre translittéré',
                'title' => 'Titre',
                'alias' => 'Alias',
                'prefix' => 'Préfixe',
                'subtitle' => 'Sous-titre',
            ],
        ],

        /*
        | L'aperçu d'un titre ou d'un alias saisi, avant confirmation (spec 20
        | § 9.1, § 9.2) : sa forme normalisée ; pour un alias, la nature
        | EXACTE sous laquelle le film l'accepte déjà, ou la forme dérivée
        | qu'il rendrait exacte (`:kind`, feuille de `publish.preview.kind`) ;
        | et, sur un film publié, les formes qu'il rendrait ambiguës — lignes
        | rendues comme celles de l'avertissement de publication.
        */
        'text_preview' => [
            'heading' => 'Aperçu',
            'check' => 'Vérifier',
            'pending' => 'Vérifiez l’aperçu avant d’enregistrer : il montre la forme acceptée et ce qu’elle rendrait ambigu.',
            'stale' => 'Le texte a changé depuis l’aperçu : vérifiez de nouveau avant d’enregistrer.',
            'loading' => 'Calcul de l’aperçu…',
            'failed' => 'L’aperçu n’a pas pu être calculé : réessayez avant de confirmer.',
            'form' => 'Forme acceptée : « :form »',
            'form_empty' => 'Ce texte ne contient ni lettre ni chiffre : il ne sera jamais accepté comme réponse.',
            'already_accepted' => 'Cette forme est déjà acceptée pour ce film (:kind) : l’ajouter n’accepte rien de plus.',
            // Un alias qui reprend une forme DÉRIVÉE d'un titre du film la
            // rend exacte (spec 10 § 3.5) : il change donc quelque chose.
            'promotes_derived' => 'Cette forme est aujourd’hui dérivée d’un titre de ce film (:kind). En alias, elle deviendra exacte : toujours acceptée pour ce film, même si un autre film publié la porte un jour.',
            'promotes_ambiguous' => 'Cette forme est aujourd’hui dérivée d’un titre de ce film (:kind) et refusée seule : un autre film publié la porte. En alias, elle deviendra exacte et sera toujours acceptée pour ce film.',
            'not_published' => 'Film non publié : aucune de ses formes ne pèse encore dans le recompte d’ambiguïté.',
            'ambiguity_heading' => 'Formes rendues ambiguës',
        ],

        /*
        | Le regroupement « même œuvre » (spec 20 § 9.4) : manuel, jamais
        | TMDB, jamais montré à un joueur ; sa seule conséquence est que les
        | films du groupe ne tombent jamais dans une même partie. `:label` est
        | le libellé interne d'un groupe, `:title` et `:year` identifient un
        | film. Les refus sont rendus sous le champ de l'identifiant.
        */
        'group' => [
            'heading' => 'Même œuvre',
            'description' => 'Deux films regroupés ne tombent jamais dans une même partie : c’est la seule conséquence d’un groupe. Pour des homonymes ou un remake — jamais pour une saga, dont les films doivent pouvoir tomber ensemble. Jamais montré à un joueur.',
            'none' => 'Ce film n’appartient à aucun groupe.',
            'label' => 'Libellé du groupe',
            'note' => 'Note',
            'created_by' => 'Regroupé par',
            'created_at' => 'Regroupé le',
            'members' => 'Films du groupe',
            'this_movie' => 'Ce film',
            'movie' => ':title (:year)',
            'movie_without_year' => ':title',
            'leave' => [
                'action' => 'Retirer du groupe',
                'title' => 'Retirer ce film du groupe',
                'description' => 'Ce film pourra de nouveau tomber dans la même partie que les autres films du groupe. Un groupe réduit à un seul film disparaît.',
                'submit' => 'Retirer du groupe',
            ],
            'candidates' => [
                'heading' => 'Candidats : même titre',
                'description' => 'Les films qui portent exactement le même titre une fois normalisé : homonymes et remakes. Le back-office suggère, vous tranchez.',
                'empty' => 'Aucun autre film ne porte le même titre normalisé.',
                'in_group' => 'Groupe « :label »',
                'same_group' => 'Déjà dans ce groupe',
                'action' => 'Regrouper',
                'action_label' => 'Regrouper avec :movie',
            ],
            // Candidats par proximité (spec 20 § 9.4 [J2], L20-27) : calculés
            // à la demande, une suggestion, jamais un regroupement.
            'proximity' => [
                'heading' => 'Candidats : titre proche ou même saga',
                'description' => 'Les films au titre proche, à chiffres identiques, et ceux de la même saga TMDB. Le calcul parcourt tout le catalogue : il ne se lance qu’à la demande. Une saga n’est pas une même œuvre — regroupez seulement un remake ou un homonyme.',
                'load' => 'Chercher les candidats',
                'reload' => 'Relancer la recherche',
                'loading' => 'Recherche des candidats…',
                'failed' => 'Les candidats n’ont pas pu être calculés : réessayez.',
                'empty' => 'Aucun film proche ni de la même saga.',
                'reason' => [
                    'title_distance' => 'Titre proche',
                    'same_collection' => 'Même saga',
                ],
            ],
            // La voie manuelle : l'identifiant saisi est d'abord cherché,
            // puis le regroupement passe par la même confirmation que celle
            // d'un candidat — libellé pré-rempli, modifiable.
            'manual' => [
                'heading' => 'Regrouper avec un autre film',
                'description' => 'Pour un remake au titre différent, qu’aucun candidat ne propose. Le regroupement se confirme ensuite, libellé pré-rempli.',
                'movie' => 'Identifiant catalogue de l’autre film',
                'movie_hint' => 'Il figure sur la fiche de l’autre film, onglet « Identité ». Si l’un des deux films a déjà un groupe, l’autre le rejoint.',
                'submit' => 'Regrouper',
                'searching' => 'Recherche du film…',
                'failed' => 'Le film n’a pas pu être cherché : réessayez.',
                'same_group' => 'Ces deux films sont déjà dans le même groupe : rien à regrouper.',
            ],
            'dialog' => [
                'title' => 'Regrouper les deux films',
                'description' => 'Ce film et :movie ne tomberont plus dans une même partie.',
                'joins_theirs' => 'Ce film rejoindra le groupe « :label ».',
                'joins_ours' => ':movie rejoindra le groupe de ce film.',
                'label_hint' => 'Libellé interne, jamais montré à un joueur ; modifiable avant de regrouper.',
                'note_hint' => 'Facultative : pourquoi ces films sont une même œuvre.',
                'submit' => 'Regrouper',
            ],
            'movie_required' => 'Saisissez l’identifiant catalogue de l’autre film.',
            'self' => 'Un film ne se regroupe pas avec lui-même.',
            'other_missing' => 'Aucun film du catalogue ne porte cet identifiant.',
            'other_withdrawn' => 'Ce film a été retiré : il ne se regroupe plus.',
            'group_missing' => 'Ce groupe n’existe plus : rechargez la fiche.',
            'both_grouped' => 'Les deux films appartiennent déjà à deux groupes différents : retirez d’abord l’un d’eux de son groupe.',
            'already_grouped' => 'Ce film appartient déjà à un autre groupe : retirez-le d’abord de ce groupe.',
            'flash' => [
                'created' => 'Groupe créé : les deux films ne tomberont plus dans une même partie.',
                'joined' => 'Film rattaché au groupe.',
                'left' => 'Film retiré du groupe.',
                'unchanged' => 'Rien n’a changé : le regroupement demandé était déjà en place.',
            ],
        ],

        'certifications' => [
            'heading' => 'Classifications retenues',
            'description' => 'Chaîne brute TMDB conservée telle quelle, par pays. Une classification restrictive interdit la publication, et aucune voie d’import ne la contourne.',
            'column' => [
                'country' => 'Pays',
                'certification' => 'Classification',
                'released_on' => 'Sortie',
                'read_at' => 'Lue le',
            ],
            'restrictive' => 'Restrictive',
            'empty' => 'Aucune classification lue.',
        ],

        'tags' => [
            'heading' => 'Étiquettes TMDB',
            'description' => 'Identifiants TMDB bruts : une société porte son nom TMDB quand il est connu, un genre jamais (son libellé est celui de son thème).',
            'genres' => 'Genres',
            'companies' => 'Sociétés',
            'company_label' => ':name (:id)',
            'empty' => 'Aucune étiquette.',
        ],

        'projection' => [
            'heading' => 'Projection',
            'description' => 'Table dérivée, recalculée par la curation : c’est elle qui sert le vivier, jamais un agrégat sur la banque d’images.',
            'missing' => 'Aucune ligne de projection : ce film n’a jamais été reprojeté. Tout vaut zéro.',
            'levels_count' => 'Niveaux couverts',
            'levels_ratio' => ':count / :total',
            'levels_mask' => 'Masque de niveaux',
            'level_variants' => 'Niveau :level',
            'variants_total' => 'Variantes, tous niveaux',
            'playable_at' => 'Jouable à N images',
            'covers_publishable' => 'Couvre les niveaux 1, 3 et 5',
            'title_mask' => 'Masque des locales de titre',
            'title_mask_version' => 'Version du masque',
            'title_mask_stale' => 'Masque périmé : il a été calculé par une version antérieure et doit être reprojeté.',
            'recomputed_at' => 'Reprojeté le',
        ],

        'themes' => [
            'heading' => 'Thèmes',
            'description' => 'La règle automatique et l’exception manuelle sont séparées ; l’appartenance effective est ce que le tirage lit.',
            'empty' => 'Ce film n’appartient à aucun thème.',
            'column' => [
                'theme' => 'Thème',
                'kind' => 'Nature',
                'origin' => 'Origine',
                'active' => 'Appartenance effective',
                'actions' => 'Gestes',
            ],
            'origin' => [
                'auto' => 'Règle',
                'manual' => 'Exception : :state',
                'auto_manual' => 'Règle, exception : :state',
            ],
            'active' => [
                'yes' => 'Dans le thème',
                'no' => 'Hors du thème',
            ],
            'unpublished' => 'Non publié',
            'unpublished_hint' => 'Ce thème n’est pas publié : l’appartenance est enregistrée, mais aucun salon ne le propose encore.',
            'add' => 'Ajouter',
            'add_label' => 'Ajouter le film au thème :theme',
            'remove' => 'Retirer',
            'remove_label' => 'Retirer le film du thème :theme',
            'clear' => 'Annuler l’exception',
            'clear_label' => 'Annuler l’exception du thème :theme',
            'picker' => [
                'heading' => 'Ajouter des thèmes',
                'description' => 'Tous les thèmes sont disponibles, publiés ou non. Choisissez-les un par un, puis ajoutez toute la sélection en une fois.',
                'label' => 'Rechercher et ajouter un thème',
                'placeholder' => 'Rechercher par nom ou nature…',
                'all_selected' => 'Tous les thèmes sont sélectionnés',
                'no_results' => 'Aucun thème ne correspond à cette recherche.',
                'unpublished' => ':label (non publié)',
                // Une option du sélecteur : le libellé, puis la nature.
                'option' => ':label — :kind',
                'empty' => 'Le film est déjà dans tous les thèmes.',
                'selection' => 'Thèmes sélectionnés',
                'selection_empty' => 'Aucun thème sélectionné pour le moment.',
                'remove' => 'Retirer :theme de la sélection',
                'submit' => '{1} Ajouter :count thème…|[2,*] Ajouter :count thèmes…',
            ],
            'confirm' => [
                'add' => [
                    'title' => 'Ajouter le film au thème',
                    'description' => 'Le film entre dans « :theme » par une exception manuelle, signée de votre nom : une correction ultérieure de la règle ne l’en sortira pas.',
                    'submit' => 'Ajouter au thème',
                ],
                'add_many' => [
                    'title' => 'Ajouter le film aux thèmes',
                    'description' => 'Le film entre dans :count thèmes par des exceptions manuelles signées de votre nom : :themes.',
                    'submit' => 'Ajouter aux :count thèmes',
                ],
                'remove' => [
                    'title' => 'Retirer le film du thème',
                    'description' => 'Le film sort de « :theme » par une exception manuelle, signée de votre nom : la règle ne l’y fera plus revenir.',
                    'submit' => 'Retirer du thème',
                ],
                'clear' => [
                    'title' => 'Annuler l’exception',
                    'description' => 'La règle de « :theme » décide de nouveau seule. Si elle ne porte pas le film, il sort du thème.',
                    'submit' => 'Annuler l’exception',
                ],
            ],
            'flash' => [
                'added' => 'Film ajouté au thème.',
                'added_many' => '{1} Film ajouté à :count thème.|[2,*] Film ajouté à :count thèmes.',
                'removed' => 'Film retiré du thème.',
                'cleared' => 'Exception annulée : la règle décide.',
                'unchanged' => 'Rien n’a changé : l’exception était déjà celle-ci.',
            ],
            'collection' => [
                'heading' => 'Collection TMDB',
                'description' => 'La saga d’un film se désigne par sa collection TMDB.',
                'none' => 'Ce film n’appartient à aucune collection TMDB.',
                'name' => 'Collection',
                'saga' => 'Thème de saga',
                'no_saga' => 'Aucun thème de saga ne désigne cette collection.',
                'create_saga' => 'Créer la saga depuis cette collection',
            ],
        ],

        'frames' => [
            'heading' => 'Banque d’images',
            'description' => 'Niveau, disponibilité et état de traitement. Aucun chemin de fichier ne quitte le serveur, même en administration.',
            'column' => [
                'level' => 'Niveau',
                'availability' => 'Disponibilité',
                'processing' => 'Traitement',
                'error' => 'Erreur',
            ],
            'empty' => 'Aucune image : la banque de ce film est vide.',
            'open_editor' => 'Ouvrir l’éditeur de la banque d’images',
            'editor_hint' => 'Ajouter, recadrer, classer ou écarter une image se fait dans l’éditeur de la banque.',
        ],

        'import' => [
            'heading' => 'Provenance',
            'description' => 'Par quelle voie ce film est entré, et sous quel filtre.',
            'source' => 'Voie d’entrée',
            'run' => 'Balayage d’origine',
            'run_missing' => 'Aucun balayage rattaché.',
            'exception' => 'Entré par exception',
            'exception_none' => 'Entré dans le filtre ordinaire.',
        ],

        'curation' => [
            'heading' => 'Curation',
            'curated_by' => 'Curé par',
            'not_curated' => 'Jamais curé',
            'active_seconds' => 'Temps actif de curation (secondes)',
        ],

        /*
        | Gestes de la fiche (spec 20 § 4.3) : `heading` nomme le groupe de
        | boutons du bandeau de disponibilité.
        */
        'gestures' => [
            'heading' => 'Gestes sur le film',
            'none' => 'Aucun geste de curation n’est possible sur ce film dans son état actuel.',
        ],

        /*
        | Publier ou republier un film (spec 20 § 8.1, § 8.2) — un geste
        | explicite, derrière une confirmation qui montre d'abord l'aperçu
        | d'ambiguïté. `content_not_clear`, `coverage_missing` (`:levels`, les
        | niveaux exigés sans image en jeu) et `not_guessable` nomment la
        | condition manquante sous le bouton inactif ET motivent le refus du
        | serveur ; `preview_stale`, le refus d'une confirmation dont
        | l'avertissement a changé entre-temps.
        |
        | `preview.line` : `:form` la forme normalisée, `:kinds` sa nature pour
        | ce film, `:movies` les films publiés qui la portent aussi, chacun
        | rendu par `preview.movie` (`:title`, `:year`, `:kind`) et joints par
        | `common.list_separator`. `preview.kind.*` : une feuille par nature de
        | clé de réponse, en minuscules, composée dans ces phrases.
        */
        'publish' => [
            'action' => 'Publier le film',
            'action_republish' => 'Republier le film',
            'blocked_heading' => 'Pas encore publiable :',
            'title' => 'Publier le film',
            'title_republish' => 'Republier le film',
            'description' => 'Le film entre au vivier : toute partie lancée ensuite pourra le tirer, à chaque nombre d’images par manche que sa banque couvre. Une partie déjà en cours n’est pas touchée.',
            'submit' => 'Publier le film',
            'submit_republish' => 'Republier le film',
            'content_not_clear' => 'Le contenu du film n’est pas vérifié comme libre de toute classification restrictive : un film « à vérifier » se débloque par « Contenu vérifié » sur sa fiche ; un film bloqué ne se publie jamais.',
            'coverage_missing' => 'Niveaux sans image en jeu : :levels. Chacun des niveaux 1, 3 et 5 doit avoir au moins une image publiée par une revue conforme.',
            'not_guessable' => 'Aucun titre de ce film ne peut se saisir comme réponse : ni son titre original, ni ses titres, ni ses alias ne contiennent de lettre ou de chiffre. Donnez-lui un titre ou un alias lisible avant de le publier.',
            'preview_stale' => 'Le catalogue a changé depuis l’affichage de l’avertissement : rien n’a été publié. Relisez l’avertissement mis à jour, puis confirmez de nouveau.',
            'preview' => [
                'heading' => 'Formes rendues ambiguës',
                'description' => 'Un préfixe ou un sous-titre porté par plusieurs films publiés n’est plus accepté seul comme réponse, pour aucun d’eux. Les titres complets et les alias restent toujours acceptés.',
                'loading' => 'Calcul de l’avertissement d’ambiguïté…',
                'failed' => 'L’avertissement d’ambiguïté n’a pas pu être calculé : réessayez avant de confirmer.',
                'none' => 'Aucune forme ne deviendra ambiguë.',
                'line' => '« :form » — :kinds de ce film, porté aussi par : :movies',
                'movie' => ':title (:year), :kind',
                'movie_without_year' => ':title, :kind',
                'kind' => [
                    'title_original' => 'titre original',
                    'title_latin' => 'titre translittéré',
                    'title' => 'titre',
                    'alias' => 'alias',
                    'prefix' => 'préfixe',
                    'subtitle' => 'sous-titre',
                ],
            ],
            'flash' => [
                'published' => 'Film publié : il entre au vivier des parties lancées désormais.',
                'republished' => 'Film republié : il revient au vivier des parties lancées désormais.',
            ],
        ],

        /*
        | Dépublier un film publié (spec 20 § 8.3) : motif obligatoire, les
        | images restent publiées.
        */
        'unpublish' => [
            'action' => 'Dépublier le film',
            'title' => 'Dépublier le film',
            'description' => 'Le film sort du vivier : aucune partie lancée ensuite ne le tirera. Ses images restent publiées, et une partie en cours le termine normalement. Une republication le remettra en jeu.',
            'reason' => 'Motif de la dépublication',
            'submit' => 'Dépublier le film',
            'flash' => 'Film dépublié : il sort du vivier des parties lancées désormais.',
        ],

        /*
        | Écarter un brouillon incurable (spec 20 § 4.2) : dépublier un film
        | jamais publié. `default_reason` pré-remplit le motif, modifiable.
        */
        'set_aside' => [
            'action' => 'Écarter le film',
            'title' => 'Écarter le film',
            'description' => 'Le film sort de la file de curation sans être publié. Rien n’est perdu : curé puis publié plus tard, il fera sa première publication.',
            'reason' => 'Motif de la mise à l’écart',
            'default_reason' => 'Aucune image exploitable',
            'submit' => 'Écarter le film',
            'flash' => 'Film écarté : il sort de la file de curation.',
        ],

        /*
        | Coche « contenu vérifié, pas de classification restrictive » (spec 20
        | § 4.4) : motif obligatoire, pas de décoche. Un contenu bloqué ne se
        | lève par aucun geste (décision 12) : aucun bouton ne le propose, et
        | `blocked_notice` le dit à la place du bouton.
        */
        'content_verified' => [
            'action' => 'Contenu vérifié',
            'title' => 'Contenu vérifié, pas de classification restrictive',
            'description' => 'Vous déclarez avoir vérifié qu’aucune classification restrictive — France -18, États-Unis NC-17 ou X — ne frappe ce film. La coche ne se retire pas : si elle se révèle fausse, dépubliez le film.',
            'reason' => 'Ce que vous avez vérifié',
            'submit' => 'Confirmer la vérification',
            'blocked_notice' => 'Contenu bloqué par une classification restrictive : ce film n’entrera jamais au vivier, et aucun geste ne lève ce blocage.',
            'flash' => 'Contenu vérifié : cette condition de publication est levée.',
        ],
    ],

    /*
    | Une image de la banque (spec 20 § 5). `processing_error.*` : une feuille
    | par cas de `App\Enums\FrameProcessingFailure`, dont la VALEUR est la clé
    | complète écrite dans `frame.processing_error` (§ 5.6). Le curateur lit ce
    | qu’il peut faire — relancer, re-recadrer, écarter, choisir un autre
    | visuel —, jamais le détail technique, qui part au journal applicatif.
    */
    'frame' => [
        'processing_error' => [
            'source_missing' => 'Le visuel d’origine est introuvable sur le serveur : ajoutez de nouveau ce visuel, puis écartez cette image.',
            'source_unreadable' => 'Le visuel d’origine est illisible ou endommagé : choisissez un autre visuel, puis écartez cette image.',
            'source_format' => 'Ce visuel n’est ni un JPEG, ni un PNG, ni un WebP : choisissez un autre visuel, puis écartez cette image.',
            'source_animated' => 'Ce visuel est animé, alors qu’une image de jeu est toujours fixe : choisissez un autre visuel, puis écartez cette image.',
            'source_too_small' => 'Ce visuel est trop petit : l’image de jeu devrait l’agrandir au-delà de sa définition. Choisissez un visuel plus grand, puis écartez cette image.',
            'source_aspect' => 'Ce visuel est en portrait, ou trop étroit pour qu’un cadre y respecte le plancher de recadrage : choisissez un visuel en paysage, puis écartez cette image.',
            'crop_invalid' => 'Le cadre ne tient pas sur ce visuel, ou ne respecte pas le plancher de recadrage : si l’image a déjà été traitée, re-recadrez-la ; sinon, écartez-la et ajoutez de nouveau ce visuel avec un autre cadre.',
            'too_heavy' => 'Même à la qualité la plus basse admise, l’image dépasse le poids maximal d’une image de jeu : si elle a déjà été traitée, re-recadrez-la sur une zone moins chargée ; sinon, écartez-la et choisissez un autre visuel ou un autre cadre.',
            'resource_limit' => 'Ce visuel a demandé plus de ressources que le serveur n’en accorde à une image : relancez le traitement ; si l’échec se répète, choisissez un visuel plus petit.',
            'withdrawn' => 'Cette image est retirée : plus aucun traitement n’est possible.',
            'published' => 'Cette image est en jeu : elle n’est jamais retraitée telle quelle. Re-recadrez-la ou dépubliez-la d’abord.',
            'unexpected' => 'Le traitement de l’image a échoué pour une raison imprévue, et l’incident est consigné : relancez le traitement.',
        ],

        /*
        | Ajout depuis un visuel TMDB (spec 20 § 5.3). Chaque refus s’affiche
        | sous le visuel ou sous le cadre, et aucune image n’est créée.
        | `with_text` : un backdrop auquel TMDB attache une langue, refusé
        | avant tout téléchargement (D39 du 28/09) — la grille ne le propose
        | pas, seul un envoi forgé ou une grille périmée l’atteint.
        | `duplicate` : même visuel ET même cadre qu’une image déjà dans la
        | banque du film — une même source recadrée autrement reste permise.
        */
        'tmdb' => [
            'not_a_backdrop' => 'Ce visuel ne fait pas partie des visuels de ce film proposés par TMDB : choisissez-en un dans la grille. Une affiche ou un logo ne devient jamais une image de jeu.',
            'with_text' => 'Ce visuel peut contenir du texte : TMDB lui attache une langue. Choisissez un visuel sans texte, ou envoyez une capture.',
            // Même refus quand l'envoi de captures est fermé sur ce site :
            // il ne propose pas un geste que l'écran n'offre pas.
            'with_text_no_capture' => 'Ce visuel peut contenir du texte : TMDB lui attache une langue. Choisissez un visuel sans texte.',
            'download_failed' => 'TMDB n’a pas pu fournir ce visuel (service indisponible ou connexion interrompue) : aucune image n’a été créée. Réessayez dans un instant ; si l’échec persiste, signalez-le à l’administrateur du site.',
            'too_large' => 'Ce visuel est plus lourd que ce que le serveur accepte de télécharger : aucune image n’a été créée. Choisissez un autre visuel du film.',
            'duplicate' => 'Cette image existe déjà dans la banque du film : même visuel, même cadre. Déplacez ou redimensionnez le cadre pour créer une autre variante.',
        ],

        /*
        | Voie capture (spec 20 § 5.4, lot L20-33), ouverte au jalon 1 (D38 du
        | 28/09) ; un site peut la fermer. `disabled` motive alors le refus du
        | serveur, et `disabled_notice` le dit à la place du bouton absent —
        | tous deux neutres : ils ne nomment ni la cause ni le réglage.
        | `timecode` refuse un minutage absent ou hors de la forme h:mm:ss ;
        | `duplicate` : même capture ET même cadre qu’une image déjà dans la
        | banque du film.
        |
        | `ui.*` : l’écran de la capture, dans le recadreur de la banque.
        | `too_small` : `:width`, la largeur d’une image de jeu ; `too_heavy` :
        | `:max`, le plafond d’un envoi en Ko. `timecode_invalid` est le refus
        | du navigateur, avant tout envoi ; `timecode_pending` annonce qu’un
        | raccourci `1` à `5` a posé le niveau (`:level`, `:label`) mais que
        | l’envoi attend un minutage conforme ; `opened` et `sent` sont
        | annoncés par la région d’état du recadreur.
        */
        'capture' => [
            'disabled' => 'L’ajout d’une capture est fermé sur ce site : seuls les visuels TMDB du film peuvent devenir des images de jeu.',
            'disabled_notice' => 'L’envoi de captures est fermé sur ce site : seuls les visuels TMDB du film peuvent devenir des images de jeu.',
            'timecode' => 'Indiquez le minutage de l’image dans le film, en heures, minutes et secondes : par exemple 0:12:34, ou 1:02:03 au-delà d’une heure.',
            'duplicate' => 'Cette image existe déjà dans la banque du film : même capture, même cadre. Déplacez ou redimensionnez le cadre pour créer une autre variante.',
            'ui' => [
                'heading' => 'Envoyer une capture',
                'description' => 'Une capture est une image du film que vous avez prise vous-même. Son minutage dans le film est obligatoire : il signe la source que votre revue déclarera.',
                'choose' => 'Choisir une image',
                'paste_hint' => 'ou collez-la depuis le presse-papiers (Ctrl + V)',
                'preparing' => 'Préparation de l’image…',
                'unreadable' => 'Cette image n’a pas pu être lue : choisissez un autre fichier d’image, ou copiez-la de nouveau.',
                'too_small' => 'Cette image est en portrait, fait moins de :width pixels de large, ou ne laisse place à aucun cadre admis : elle ne peut pas donner une image de jeu. Choisissez une capture plus grande, en paysage.',
                'too_heavy' => 'Même compressée au plus bas, cette image dépasse :max Ko, le poids maximal d’un envoi : choisissez une capture moins chargée.',
                'unsupported' => 'Ce navigateur ne sait pas préparer l’image au format attendu : utilisez un navigateur récent, sur ordinateur.',
                'timecode_label' => 'Minutage dans le film',
                'timecode_hint' => 'Heures, minutes et secondes : par exemple 0:12:34.',
                'timecode_invalid' => 'Saisissez le minutage en heures, minutes et secondes, par exemple 0:12:34 : les minutes et les secondes vont de 00 à 59.',
                'timecode_pending' => 'Niveau :level — :label choisi. L’ajout attend le minutage de la capture.',
                'opened' => 'Capture ouverte dans le cadre : choisissez son niveau et saisissez son minutage.',
                'sent' => 'Capture ajoutée : elle part en traitement.',
                'discard' => 'Abandonner la capture',
                // Un pas de la bande pendant qu'une capture est ouverte : la
                // capture n'est pas dans la bande et reste ouverte.
                'strip_locked' => 'La capture reste ouverte : choisissez un visuel dans la grille, ou abandonnez la capture, pour passer à un visuel TMDB.',
                'source_label' => 'Capture',
            ],
        ],

        /*
        | Re-recadrer une image, en place (spec 20 § 5.7). Les trois refus
        | d’état sont des erreurs traduites, jamais des pages 403 : `busy` et
        | `locked` servent aussi « Relancer ». `default_reason` pré-remplit le
        | motif de la sortie du jeu d’une image publiée recadrée.
        */
        'recrop' => [
            'busy' => 'Cette image est en cours de traitement : attendez qu’il se termine, puis refaites votre geste.',
            'not_ready' => 'Cette image n’a pas encore de rendu à recadrer : relancez son traitement s’il a échoué, sinon écartez-la et ajoutez de nouveau son visuel.',
            'locked' => 'Cette image est suspendue ou retirée par un administrateur : elle ne peut plus être recadrée ni retraitée.',
            'default_reason' => 'Image re-recadrée : elle sort du jeu jusqu’à une revue de son nouveau rendu.',
        ],

        /*
        | Relancer un traitement (spec 20 § 5.6) : offert au seul échec
        | rejouable.
        */
        'retry' => [
            'not_retryable' => 'Ce traitement ne peut pas être relancé tel quel : recommencer donnerait le même échec. Re-recadrez l’image si elle a déjà un rendu, sinon écartez-la.',
        ],

        /*
        | Changer le niveau d’une image (spec 20 § 5.7). `default_reason` est
        | le motif ÉCRIT PAR LE SERVEUR quand une image publiée sort du jeu :
        | il est stocké tel quel au journal, en texte, jamais en clé (§ 2.7).
        */
        'level' => [
            'locked' => 'Cette image est suspendue ou retirée par un administrateur : son niveau ne peut plus être changé.',
            'default_reason' => 'Niveau de l’image changé : elle sort du jeu jusqu’à une nouvelle revue, les points de la grille dépendant du niveau.',
        ],

        /*
        | Avertissement de couverture, avant confirmation d’un geste qui fait
        | sortir du jeu la seule variante d’un niveau 1, 3 ou 5 d’un film
        | publié (spec 20 § 8.4) : le geste reste permis, le film reste publié.
        | `:max` est le plus grand nombre d’images par manche encore jouable.
        | `coverage_warning_unplayable` sert aussi un film DÉJÀ incomplet que
        | le geste rend injouable : son texte reste vrai dans les deux cas.
        */
        'unpublish' => [
            'coverage_warning' => 'Ce film deviendra incomplet : il restera jouable jusqu’à N = :max, avec repli de niveau.',
            'coverage_warning_unplayable' => 'Ce film ne sera plus jouable à aucun nombre d’images par manche : il reste publié, mais n’entrera dans aucun tirage tant que de nouvelles images ne seront pas publiées.',
        ],

        /*
        | Aperçu du rendu final d'une image (spec 20 § 5.8, § 6.7), dans le
        | cadre du jeu : `alt` est neutre et ne dit que le niveau (`:level`),
        | jamais ce que l'image montre ; `unavailable` couvre une image pas
        | encore traitée comme un chargement en échec.
        */
        'preview' => [
            'alt' => 'Rendu final de l’image de niveau :level',
            'loading' => 'Chargement du rendu final…',
            'unavailable' => 'Rendu final indisponible : l’image n’est pas encore traitée, ou son chargement a échoué.',
            'mobile' => 'Sur un téléphone',
            'desktop' => 'Sur un ordinateur',
        ],

        /*
        | Toasts de succès. Un traitement part en file et le curateur continue
        | sans l’attendre ; un changement de niveau ou une dépublication
        | s’écrivent sur-le-champ.
        */
        'flash' => [
            'queued' => 'Image ajoutée : elle est en traitement et apparaîtra dans la banque du film dès qu’elle sera prête.',
            'recrop_queued' => 'Nouveau cadre enregistré : l’image est en traitement et reviendra en revue dès qu’elle sera prête.',
            'retry_queued' => 'Traitement relancé : l’image apparaîtra dans la banque du film dès qu’elle sera prête.',
            'level_changed' => 'Niveau de l’image enregistré.',
            'level_changed_review' => 'Niveau de l’image enregistré : elle sort du jeu et repasse en revue.',
            'unpublished' => 'Image dépubliée : elle sort du jeu, et une revue pourra l’y remettre.',
            'set_aside' => 'Image écartée : elle ne sera pas proposée en revue. Pour réutiliser son visuel, ajoutez une nouvelle variante.',
        ],
    ],

    /*
    | Recadreur (spec 20 § 6.3 et § 6.4). Le cadre se tient dans l’espace du
    | master, et ses dimensions s’y lisent : `:width` et `:height` en pixels
    | du master, `:percent` la part de sa surface que couvre le cadre.
    | `instructions` décrit l’opérabilité au clavier, toujours accessible
    | depuis l’aide focalisable du recadreur ; les boutons qui forment
    | l’alternative non gestuelle du principe 8 restent tous visibles.
    | `:steps` y est le nombre de pas d’un geste fait avec Maj.
    | Les refus du plancher sont ceux de `validation.crop.*`, communs au
    | recadreur et au serveur. `image_failed_description` dit l’échec d’un
    | visuel TMDB ; `image_failed_capture`, celui d’une capture locale, que
    | seul un nouvel envoi rouvre.
    */
    'cropper' => [
        'region_label' => 'Cadre de l’image de jeu',
        'image_alt' => 'Visuel du film à recadrer',
        'instructions' => 'Au clavier, une fois le cadre sélectionné : les flèches le déplacent ; « + » ou « = » l’élargit, « - » le resserre ; avec Maj, chaque geste compte :steps pas ; Origine rétablit le cadre par défaut. À la souris, faites glisser l’intérieur du cadre pour le déplacer, ou l’un de ses coins pour le redimensionner ; une fois le cadre sélectionné, Ctrl + molette l’élargit vers le haut et le resserre vers le bas, et le pincement, au pavé tactile comme sur un écran tactile, suit l’écartement des doigts ; la molette seule fait défiler la page. Les boutons fléchés le déplacent d’un pas.',
        'dimensions' => 'Cadre de :width × :height pixels, soit :percent % de la surface du visuel.',
        'at_widest' => 'C’est le cadre le plus large que le plancher de recadrage admet.',
        'at_narrowest' => 'C’est le cadre le plus serré admis : l’image de jeu ne peut pas être davantage agrandie.',
        'controls_label' => 'Taille et position du cadre',
        'widen' => 'Plus large',
        'narrow' => 'Plus serré',
        'move_left' => 'Déplacer le cadre vers la gauche',
        'move_up' => 'Déplacer le cadre vers le haut',
        'move_down' => 'Déplacer le cadre vers le bas',
        'move_right' => 'Déplacer le cadre vers la droite',
        'center' => 'Centrer',
        'reset' => 'Cadre par défaut',
        'image_loading' => 'Chargement du visuel…',
        'image_failed' => 'Le visuel n’a pas pu être affiché',
        'image_failed_description' => 'Le serveur d’images de TMDB n’a pas répondu, ou la connexion est interrompue. Réessayez ; si l’échec persiste, choisissez un autre visuel du film.',
        'image_failed_capture' => 'La capture n’a pas pu être affichée dans le cadre : abandonnez-la, puis choisissez ou collez-la de nouveau.',
        'retry' => 'Réessayer',
    ],

    /*
    | Échelle 1-5 (spec 20 § 6.5) : guide normatif, rendu à côté du sélecteur
    | de niveau et repris par la page « premiers pas ». Le niveau est relatif
    | au FILM, jamais au catalogue. Aucun niveau n’est coché d’avance : un
    | défaut serait un classement non décidé. `option` : `:level` est le
    | chiffre du niveau, `:label` son libellé.
    */
    'level' => [
        'legend' => 'Niveau de l’image',
        'hint' => 'Choisissez le niveau avant l’envoi : aucun n’est coché d’avance. Il se juge par rapport à ce film, jamais par rapport au reste du catalogue.',
        'option' => 'Niveau :level — :label',
        1 => [
            'label' => 'Très cryptique',
            'guide' => 'Un détail, une texture, une matière, un second plan : rien qui se nomme sans avoir vu le film de près. Aucun visage du personnage principal.',
        ],
        2 => [
            'label' => 'Cryptique',
            'guide' => 'Un fragment de décor ou d’accessoire caractéristique, une silhouette, un personnage secondaire. Aucun visage du personnage principal.',
        ],
        3 => [
            'label' => 'Intermédiaire',
            'guide' => 'Un décor ou une situation que reconnaît qui a vu le film ; le personnage principal peut apparaître de dos, de loin ou en partie.',
        ],
        4 => [
            'label' => 'Lisible',
            'guide' => 'Une scène marquante ; le personnage principal est visible, mais pas dans le plan le plus célèbre du film.',
        ],
        5 => [
            'label' => 'Évident',
            'guide' => 'Le plan iconique, le personnage principal : l’image que tout le monde associe au film, sans jamais être son affiche.',
        ],
    ],

    /*
    | Raccourcis de débit (spec 20 § 6.4, lot L20-11). Facultatifs : l’outil
    | s’utilise entièrement par ses boutons et son clavier sans eux. Aucun
    | n’agit pendant une saisie dans un champ. `classify`, `neighbour` et
    | `pass` forment le rappel du pied de l’éditeur, repris par la page
    | « premiers pas » ; `cropper_hint` et `pass_hint` le rappellent sous le
    | recadreur et sous la revue. `announce.*` est lu par la région vivante de
    | l’éditeur : `:level` est le chiffre du niveau choisi, `:label` son
    | libellé (`level.{n}.label`).
    */
    'shortcuts' => [
        'heading' => 'Raccourcis de débit',
        'description' => 'Facultatifs : chaque geste a aussi son bouton, et l’outil s’utilise entièrement sans eux. Aucun raccourci n’agit pendant une saisie dans un champ.',
        'classify' => '1 à 5, le cadre sélectionné : classe l’image à ce niveau et l’ajoute à la banque en un seul geste.',
        'neighbour' => 'Page précédente et Page suivante : visuel précédent ou suivant de la bande, sans quitter le cadre. [ et ] restent disponibles ; sur un clavier AZERTY, AltGr + ( et AltGr + ).',
        'pass' => 'Entrée, dans la passe de revue : conforme, publier. Sur un bouton ou une case, Entrée garde son effet habituel.',
        'cropper_hint' => 'Raccourcis : Page précédente et Page suivante changent de visuel ; 1 à 5 classent et ajoutent l’image depuis le cadre sélectionné. [ et ] restent disponibles.',
        'pass_hint' => 'Raccourci : Entrée, hors d’un bouton ou d’une case, vaut « Conforme, publier ».',
        'announce' => [
            'sending' => 'Niveau :level — :label : ajout de l’image à la banque.',
            'blocked' => 'Niveau :level — :label choisi. L’ajout attend un cadre admis par le plancher de recadrage.',
        ],
    ],

    /*
    | Éditeur de la banque d'images (spec 20 § 6) : les visuels TMDB du film,
    | le recadreur et le niveau, la banque du film groupée par niveau avec sa
    | couverture, et la prévisualisation en conditions de jeu. Chaque geste
    | qui fait sortir une image du jeu est confirmé, et l'avertissement de
    | couverture (`frame.unpublish.*`) s'affiche AVANT l'envoi.
    |
    | `backdrop_alt` : `:index` et `:count` situent le visuel dans la grille ;
    | `backdrop_used` : `:levels`, les niveaux des images qui en proviennent ;
    | `backdrops_excluded` : `:count`, le nombre de visuels que TMDB attache à
    | une langue, écartés de la grille (D39 du 28/09), choisi par `tChoice` ;
    | `list.image` et `list.actions` : `:index` est le rang de l'image dans
    | son niveau. `preview.*` : `:count` est un nombre d'images par manche,
    | `:tier` un rang de palier, `:levels` une liste de niveaux.
    */
    'bank' => [
        'title' => 'Banque d’images',
        'description' => 'Ajoutez des images depuis les visuels TMDB du film ou par une capture, classez-les de 1 à 5, puis suivez leur traitement et la couverture du film. Une image n’entre en jeu qu’après une revue de son rendu final.',
        // Variante quand l'envoi de captures est fermé sur ce site.
        'description_tmdb_only' => 'Ajoutez des images depuis les visuels TMDB du film, classez-les de 1 à 5, puis suivez leur traitement et la couverture du film. Une image n’entre en jeu qu’après une revue de son rendu final.',
        'back' => 'Retour à la fiche du film',
        'retry' => 'Réessayer',
        'cancel' => 'Annuler',
        'processing_notice' => 'Des images sont en traitement : cet écran se met à jour de lui-même, inutile d’attendre pour continuer.',
        'processing_stalled' => 'Le traitement d’arrière-plan ne répond pas : une image attend son rendu depuis trop longtemps. Vous pouvez continuer à curer ; si l’attente se prolonge, prévenez l’administrateur du site.',
        'refresh_failed' => 'La mise à jour automatique de la banque a échoué.',
        'desktop_required' => 'Le recadreur demande un écran large, celui d’un ordinateur. Le reste de l’écran — la banque, les états, la couverture — reste utilisable ici.',

        'backdrops' => [
            'heading' => 'Visuels TMDB du film',
            'description' => 'Seuls sont proposés les visuels auxquels TMDB n’attache aucune langue : les autres peuvent contenir du texte. Une seule tabulation entre dans la grille : les flèches passent d’un visuel à l’autre, Entrée ou Espace ouvre le visuel dans le cadre.',
            'list_label' => 'Visuels TMDB proposés',
            'loading' => 'Chargement des visuels TMDB…',
        ],
        'no_backdrops' => 'Aucun visuel TMDB à proposer : TMDB n’en fournit aucun pour ce film, ou le film n’a pas d’identifiant TMDB (catalogue de démonstration).',
        'no_backdrops_all_text' => 'Aucun visuel TMDB sans texte à proposer : TMDB attache une langue à tous les visuels de ce film.',
        'backdrops_excluded' => ':count visuel écarté : TMDB lui attache une langue, il peut contenir du texte.|:count visuels écartés : TMDB leur attache une langue, ils peuvent contenir du texte.',
        'backdrops_failed' => 'Les visuels du film n’ont pas pu être obtenus auprès de TMDB (service indisponible ou connexion interrompue). Réessayez dans un instant.',
        'backdrop_alt' => 'Visuel :index sur :count',
        'backdrop_used' => 'Déjà utilisé (niveaux :levels)',
        'backdrop_opened' => 'Ouvert dans le cadre',

        'cropper' => [
            'heading' => 'Recadrer et classer',
            'description' => 'Le cadre s’ouvre sur le plus grand cadre admis, centré. Choisissez le niveau, puis ajoutez l’image : elle part en traitement et vous passez à un autre visuel sans attendre.',
            'empty' => 'Choisissez un visuel dans la grille, ou envoyez une capture, pour l’ouvrir dans le cadre.',
            'empty_tmdb_only' => 'Choisissez un visuel dans la grille pour l’ouvrir dans le cadre.',
            'close' => 'Fermer ce visuel',
            'add' => 'Ajouter à la banque',
            'adding' => 'Ajout en cours…',
            'locked' => 'Ce film est suspendu par un administrateur : aucune image ne peut y être ajoutée.',
            'level_required' => 'Choisissez un niveau avant d’ajouter l’image : aucun n’est coché d’avance.',
        ],

        /*
        | Bande balayable (spec 20 § 6.2, lot L20-11) : les visuels non encore
        | utilisés, sous le recadreur. `position` : `:index` est le rang du
        | visuel ouvert dans la bande, `:count` la taille de la bande ;
        | `opened` et `sent_next` : `:index` et `:count` le situent dans la
        | grille, comme `backdrop_alt`.
        */
        'strip' => [
            'heading' => 'Visuels non utilisés',
            'description' => 'Les visuels dont aucune image n’est encore tirée et qui peuvent s’ouvrir dans le cadre, dans l’ordre de la grille. [ et ], un glissement sur la bande ou ses boutons passent au visuel voisin sans quitter le cadre ; après chaque ajout, le visuel suivant s’ouvre de lui-même.',
            'list_label' => 'Bande des visuels non utilisés',
            'previous' => 'Visuel précédent',
            'next' => 'Visuel suivant',
            'position' => 'Visuel :index sur :count de la bande',
            'outside' => 'Le visuel ouvert a déjà servi : il n’est pas dans la bande.',
            'empty' => 'Aucun visuel n’attend dans la bande : chacun a déjà servi ou ne peut pas s’ouvrir dans le cadre. La grille reste ouverte pour tirer une autre variante d’un visuel utilisé.',
            'opened' => 'Visuel :index sur :count ouvert dans le cadre.',
            'sent_next' => 'Image ajoutée. Visuel :index sur :count ouvert dans le cadre.',
            'exhausted' => 'Image ajoutée. Plus aucun visuel non utilisé ne peut s’ouvrir dans le cadre : choisissez un visuel dans la grille pour une autre variante.',
            'edge_previous' => 'Aucun visuel non utilisé avant celui-ci.',
            'edge_next' => 'Aucun visuel non utilisé après celui-ci.',
        ],

        'list' => [
            'heading' => 'Banque du film',
            'description' => 'Les images du film, groupées par niveau, avec leur état. Une image rejetée en revue peut être re-recadrée ou écartée ; une image écartée ne revient jamais en revue.',
            'empty' => 'Aucune image : ajoutez-en une depuis les visuels TMDB du film, ou par une capture.',
            'empty_tmdb_only' => 'Aucune image : ajoutez-en une depuis les visuels TMDB du film.',
            'level_empty' => 'Aucune image à ce niveau.',
            'image' => 'Image :index du niveau :level',
            'actions' => 'Gestes sur l’image :index du niveau :level',
            'review_outdated' => 'À re-revoir : la grille a changé depuis sa revue',
            'review_rejected' => 'Rejetée en re-revue : reste en jeu jusqu’à décision',
            'source' => [
                'tmdb' => 'Source : visuel TMDB',
                'capture' => 'Source : capture personnelle',
            ],
            'recrop' => 'Re-recadrer',
            'retry' => 'Relancer',
            'change_level' => 'Changer de niveau',
            'unpublish' => 'Dépublier',
            'set_aside' => 'Écarter',
        ],

        'state' => [
            'processing' => 'En traitement',
            'failed' => 'En échec',
            'awaiting_review' => 'En attente de revue',
            'rejected' => 'Rejetée en revue',
            'in_play' => 'En jeu',
            'set_aside' => 'Écartée',
            'locked' => 'Suspendue ou retirée',
        ],

        'coverage' => [
            'heading' => 'Couverture par niveau',
            'description' => 'Variantes jouables de chaque niveau, et images encore en route vers le jeu.',
            'pass_one' => 'Passe 1 en cours : une variante jouable à chacun des niveaux 1, 3 et 5 suffit à rendre le film publiable.',
            'pass_two' => 'Passe 2 en cours : une deuxième variante aux niveaux 1, 3 et 5, et une variante aux niveaux 2 et 4. C’est un objectif de curation, jamais une condition de publication.',
            'pass_two_reached' => 'Passe 2 atteinte : chaque niveau a sa cible de variantes jouables.',
            'incomplete' => 'Film publié incomplet : l’un des niveaux 1, 3 ou 5 n’a plus de variante jouable. Il reste publié, jouable jusqu’à N = :max avec repli de niveau.',
            'incomplete_unplayable' => 'Film publié incomplet : il n’est plus jouable à aucun nombre d’images par manche. Il reste publié, mais n’entre dans aucun tirage tant que de nouvelles images ne sont pas publiées.',
            'table_label' => 'Couverture de la banque, niveau par niveau',
            'column' => [
                'level' => 'Niveau',
                'playable' => 'Jouables / cible',
                'processing' => 'En traitement',
                'awaiting_review' => 'En attente de revue',
                'rejected' => 'Rejetées',
                'failed' => 'En échec',
            ],
            'playable_ratio' => ':count / :target',
            'single_variant' => 'Variante unique',
        ],

        'gesture' => [
            'coverage_loading' => 'Vérification de la couverture du film…',
            'coverage_failed' => 'La couverture du film n’a pas pu être vérifiée : réessayez avant de confirmer.',
            'reason_optional' => 'Motif (facultatif)',
            'recrop' => [
                'title' => 'Re-recadrer l’image',
                'description' => 'Le nouveau cadre remplace l’ancien sur la même image : elle repart en traitement, puis en revue.',
                'published' => 'Cette image est en jeu : le nouveau cadre la fait sortir du jeu jusqu’à une revue de son nouveau rendu.',
                'reason' => 'Motif de la sortie du jeu',
                'master_loading' => 'Chargement de la source de recadrage…',
                'master_failed' => 'La source de recadrage n’a pas pu être chargée : réessayez ; si l’échec persiste, écartez l’image et ajoutez de nouveau son visuel.',
                'submit' => 'Enregistrer le nouveau cadre',
            ],
            'level' => [
                'title' => 'Changer le niveau de l’image',
                'description' => 'Le niveau se juge par rapport à ce film, jamais par rapport au reste du catalogue.',
                'published' => 'Cette image est en jeu : changer son niveau la fait sortir du jeu jusqu’à une nouvelle revue, les points de la grille dépendant du niveau.',
                'unchanged' => 'Choisissez un niveau différent du niveau actuel.',
                'submit' => 'Enregistrer le niveau',
            ],
            'unpublish' => [
                'title' => 'Dépublier l’image',
                'description' => 'L’image sort du jeu ; une nouvelle revue pourra l’y remettre.',
                'submit' => 'Dépublier l’image',
            ],
            'set_aside' => [
                'title' => 'Écarter l’image',
                'description' => 'L’image ne sera jamais proposée en revue. Pour réutiliser son visuel, ajoutez-en une nouvelle variante.',
                'submit' => 'Écarter l’image',
            ],
        ],

        'preview' => [
            'heading' => 'Prévisualisation en conditions de jeu',
            'description' => 'Ce que verrait un salon à chaque nombre d’images par manche : une variante par niveau, la plus ancienne, sur son rendu final et dans le cadre sombre du jeu, en largeur mobile puis en largeur d’ordinateur.',
            'frames_per_round' => 'Images par manche',
            'frames_per_round_option' => 'N = :count',
            'mask' => 'Images comptées',
            'mask_in_play' => 'En jeu',
            'mask_after_review' => 'Après revue',
            'mask_hint' => '« Après revue » ajoute aux images en jeu celles qui attendent leur revue.',
            'unplayable' => 'Pas jouable à N = :count : il faut au moins :count niveaux couverts.',
            'fallback' => 'Repli de niveau : ce film serait joué avec les niveaux :levels.',
            'levels' => 'Niveaux joués : :levels.',
            'tier' => 'Palier :tier — niveau :level',
        ],

        'footer' => [
            'heading' => 'Publication du film',
        ],
    ],

    /*
    | Grille d'exclusion (spec 20 § 7.1, contrat C14-bis) : une feuille
    | `label` et une feuille `help` par item, sous sa VERSION — forme R-47,
    | une clé de langue ne pouvant être à la fois feuille et nœud. Une revue
    | passée reste ainsi relisible dans les mots de la grille qu'elle a
    | appliquée. Textes normatifs, figés avec leur version : clarifier un
    | libellé impose une nouvelle version (§ 7.2), jamais une réécriture ici.
    */
    'exclusion_grid' => [
        /*
        | Geste rétroactif de grille (spec 20 § 7.7, D13 du 23/09, L20-25),
        | administrateur seul. Offert sous une version rétroactive seulement.
        | `done` : `:count` images dépubliées.
        */
        'retroactive' => [
            'title' => 'Grille rétroactive',
            'heading' => 'Appliquer la grille rétroactivement',
            'description' => 'Une version de la grille marquée rétroactive ajoute un critère pour un motif juridique. Ce geste dépublie les images publiées qui n’ont pas été revues à cette version, aux seuls niveaux que le nouveau critère concerne. Elles reviennent en jeu par une nouvelle revue. Aucun film n’est dépublié.',
            'unavailable' => 'La version courante de la grille n’est pas rétroactive : il n’y a rien à appliquer.',
            'version' => 'Version courante de la grille : :version.',
            'levels' => 'Niveaux concernés : :levels.',
            'nothing' => 'Aucune image publiée n’attend ce geste : toutes ont été revues à la version courante.',
            'summary' => ':frames image(s) seront dépubliées dans :movies film(s).',
            'column' => [
                'movie' => 'Film',
                'frames' => 'Images visées',
                'after' => 'Après le geste',
            ],
            'incomplete' => 'Devient incomplet, jouable jusqu’à :max images',
            'incomplete_unplayable' => 'Devient incomplet et injouable',
            'stays' => 'Reste complet',
            'reason' => 'Motif juridique',
            'reason_hint' => 'Recopié sur chaque ligne du journal.',
            'reference' => 'Référence de la demande de retrait',
            'reference_hint' => 'Facultative : la référence à 12 caractères de la demande liée.',
            'confirm' => 'Dépublier ces images',
            'confirm_title' => 'Appliquer la grille rétroactivement',
            'confirm_description' => 'Les images listées sortiront du jeu maintenant. Les films nommés « incomplets » resteront publiés, jouables par repli de niveau.',
            'version_changed' => 'La grille a changé de version depuis l’affichage : rechargez l’écran.',
            'done' => '{0} Aucune image n’était plus à dépublier.|{1} Une image dépubliée.|[2,*] :count images dépubliées.',
        ],
        'v1' => [
            'no_poster_or_cover' => [
                'label' => 'Ni affiche ni jaquette',
                'help' => 'L’image est un photogramme du film : ni affiche, ni jaquette, ni visuel de dossier de presse, ni montage promotionnel. Un visuel composé (plusieurs plans, titre stylisé, fond uni) est refusé.',
            ],
            'no_title_card' => [
                'label' => 'Pas de carton-titre',
                'help' => 'Aucun plan où s’affiche le titre du film, qu’il soit incrusté, peint dans le décor au moment du titre ou porté par un carton.',
            ],
            'no_studio_logo' => [
                'label' => 'Pas de logo de studio',
                'help' => 'Aucun logo ni ouverture de studio, de distributeur ou de producteur, même partiel ou en arrière-plan.',
            ],
            'no_credits' => [
                'label' => 'Pas de générique',
                'help' => 'Aucun plan de générique de début ou de fin, aucun nom d’acteur, de réalisateur ou de membre de l’équipe incrusté.',
            ],
            'no_identifying_text' => [
                'label' => 'Aucun texte qui identifie le film',
                'help' => 'Aucun texte, dans aucune écriture, qui nomme ou identifie le film : titre, sous-titre, nom de saga, accroche, crédits. Un texte de décor sans rapport avec le titre (enseigne, panneau, journal) reste autorisé, sans quoi le corpus japonais et le corpus d’exception deviendraient impubliables. En cas de doute, refuser.',
            ],
            'no_watermark_or_copyright' => [
                'label' => 'Aucun filigrane ni copyright incrusté',
                'help' => 'Aucun filigrane, aucune mention « © », aucune signature ni marque de site incrustée, même discrète dans un coin.',
            ],
            'no_broadcaster_or_trailer_overlay' => [
                'label' => 'Aucune incrustation de bande-annonce ou de diffuseur',
                'help' => 'Aucun logo de chaîne ou de plateforme, bandeau, date de sortie, mention « bande-annonce » ou « prochainement », ni élément d’interface de lecteur vidéo.',
            ],
            'no_promotional_still_or_portrait' => [
                'label' => 'Aucune photo de plateau ni portrait promotionnel',
                'help' => 'Pas de photo de tournage (équipe, matériel, coulisses) ni de portrait posé d’acteur hors du film : le droit à l’image de l’acteur est distinct du droit d’auteur sur l’œuvre. Seul un photogramme du film est admis.',
            ],
            'no_lead_face' => [
                'label' => 'Pas de visage du personnage principal',
                'help' => 'Aux niveaux 1 et 2 seulement, le visage du personnage principal n’apparaît pas de manière reconnaissable. Règle de cryptage de la manche, pas de conformité : un plan iconique de niveau 5 le montre forcément.',
            ],
        ],
    ],

    /*
    | Passe de revue (spec 20 § 7.3 à § 7.6). Trois listes : « À revoir »,
    | « À re-revoir », « Rejetées » ; `tabs.*` : `:count` est le nombre
    | d'images de la liste. Une image se juge sur son rendu FINAL, dans le
    | cadre sombre du jeu ; la source déclarée s'affiche en lecture seule et
    | l'envoi la confirme. Les refus (`stale`, `grid_version_outdated`,
    | `source_mismatch`, `locked`, `level_changed`, `already_reviewed`,
    | `answers_invalid`) n'écrivent aucune preuve ; tous sauf le dernier sont
    | relus sous le verrou de l'image. `panel.heading` : `:level` et
    | `:title` ; `list.failed`, `panel.previously_rejected` et
    | `unpublish.default_reason` : `:items`, les libellés des points en défaut.
    */
    'review' => [
        'title' => 'Passe de revue',
        'heading' => 'Passe de revue',
        'description' => 'Chaque image se juge sur son rendu final, tel qu’un joueur le verra, dans le cadre sombre du jeu et aux deux largeurs. Une revue conforme publie l’image ; une revue non conforme nomme les points en défaut. Les images sont regroupées par film : son contexte aide à juger le texte et les visages.',
        'desktop_required' => 'La revue demande un écran large, celui d’un ordinateur : l’image se juge sur son rendu final aux deux largeurs. Les listes, et les gestes sur une image rejetée, restent utilisables ici.',

        'tabs' => [
            'label' => 'Listes de la file de revue',
            'to_review' => 'À revoir (:count)',
            'to_rereview' => 'À re-revoir (:count)',
            'rejected' => 'Rejetées (:count)',
        ],
        // La file plafonnée à ses premiers films (amendé le 05/10, D57).
        'truncated' => 'Affichage des :shown premiers films sur :total : un film revu sort de la liste, le suivant y entre.',

        'lists' => [
            'to_review' => [
                'description' => 'Les images prêtes qui n’ont pas été jugées depuis leur dernier changement : nouvelles, re-recadrées, changées de niveau ou dépubliées. Seule une revue conforme les met en jeu.',
                'empty' => 'Aucune image à revoir : chaque image prête a été jugée.',
            ],
            'to_rereview' => [
                'description' => 'Les images en jeu revues sous une version antérieure de la grille. Elles restent en jeu pendant leur nouvelle revue.',
                'empty' => 'Aucune image à re-revoir : chaque image en jeu a été revue sous la grille courante.',
            ],
            'rejected' => [
                'description' => 'Les images dont la dernière revue est non conforme. Revoyez-en une si ce rejet était une erreur, re-recadrez-la dans la banque du film, ou écartez-la.',
                'empty' => 'Aucune image rejetée.',
            ],
        ],

        'list' => [
            'label' => 'Images du film :title',
            'frame' => 'Niveau :level — :label',
            'current' => 'En cours de revue',
            'in_play' => 'En jeu',
            'failed' => 'En défaut : :items',
            'actions' => 'Gestes sur l’image de niveau :level du film :title',
            'recrop' => 'Re-recadrer dans la banque',
            'unpublish' => 'Dépublier',
            'set_aside' => 'Écarter',
        ],

        'panel' => [
            'heading' => 'Image de niveau :level — :title',
            'movie' => 'Film',
            'title_latin' => 'Titre transcrit',
            'release_year' => 'Année de sortie',
            'level' => 'Niveau',
            'in_play' => 'Cette image est en jeu : une revue conforme l’y garde, une revue non conforme propose de la dépublier.',
            'previously_rejected' => 'Rejetée à la dernière revue, en défaut : :items. Seule une revue conforme peut encore partir.',
            'source' => [
                'heading' => 'Source déclarée',
                'tmdb' => 'Visuel TMDB',
                'capture' => 'Capture personnelle, à cet instant du film',
                'notice' => 'Lecture seule : votre revue confirme cette source, inscrite telle quelle dans sa preuve.',
            ],
            'items' => [
                'heading' => 'Grille d’exclusion, version :version',
                'description' => 'Les points qui s’appliquent au niveau :level. « Conforme, publier » répond oui à chacun d’eux.',
                'failed' => 'En défaut : :label',
            ],
            'pass' => 'Conforme, publier',
            'fail' => 'Non conforme',
            'fail_hint' => 'Cochez chaque point en défaut, puis rejetez l’image.',
            'reject' => 'Rejeter',
            'reject_blocked' => 'Cochez au moins un point en défaut pour rejeter l’image.',
            'cancel' => 'Annuler',
            'sending' => 'Envoi en cours…',
        ],

        'unpublish' => [
            'default_reason' => 'Rejetée en revue, en défaut : :items.',
            'rejected_notice' => 'La revue non conforme est enregistrée. L’image reste en jeu tant que vous ne la dépubliez pas, et figure dans « Rejetées » jusqu’à décision.',
        ],

        'flash' => [
            'published' => 'Revue conforme : l’image est publiée.',
            'rereviewed' => 'Revue conforme : l’image reste en jeu, revue sous la grille courante.',
            'rejected' => 'Revue non conforme enregistrée : l’image rejoint les rejetées.',
        ],

        'stale' => 'L’image a changé depuis son affichage, un nouveau rendu l’a remplacée : aucune revue n’a été enregistrée. Revoyez-la sur son rendu actuel.',
        'grid_version_outdated' => 'La grille d’exclusion a changé depuis l’affichage de cette image : aucune revue n’a été enregistrée. Revoyez-la sous la grille courante.',
        'source_mismatch' => 'La source confirmée n’est pas celle de l’image : aucune revue n’a été enregistrée. Revoyez-la depuis la file à jour.',
        'locked' => 'Cette image ne peut pas être revue : elle est en traitement ou en échec, ou bien elle ou son film est suspendu ou retiré par un administrateur.',
        'level_changed' => 'Le niveau de l’image a changé depuis son affichage, et les points de la grille avec lui : aucune revue n’a été enregistrée. Revoyez-la à son nouveau niveau.',
        'already_reviewed' => 'Cette image a déjà été jugée, ou n’attend plus de revue : aucune nouvelle revue n’a été enregistrée.',
        'answers_invalid' => 'Les réponses envoyées ne correspondent pas à la grille d’exclusion : aucune revue n’a été enregistrée. Revoyez l’image depuis la file à jour.',

        /*
        | Validation en lot des images d’un film (D42 du 30/09, § 7.9), depuis
        | la fiche du film ou un groupe de la file. `:count` : le nombre
        | d’images du lot ; `:version` : la version courante de la grille ;
        | `:title` : le titre original du film. Les refus (`empty`,
        | `stale_list`, `stale`) sont relus sous le verrou et n’écrivent rien.
        */
        'batch' => [
            'action' => 'Tout valider (:count)',
            'action_label' => 'Valider en une fois les :count images en attente de revue du film :title',
            'title' => 'Valider toutes les images en attente ?',
            'description' => ':count image sera validée et publiée.|:count images seront validées et publiées.',
            'grid_notice' => 'Pour chacune, la grille d’exclusion (version :version) est enregistrée comme « rien à signaler » à son niveau, sous votre nom réel : une revue conforme par image, comme en revue individuelle.',
            'excluded_notice' => 'Les images en traitement, en échec, rejetées ou hors jeu ne font pas partie du lot : elles restent en revue individuelle.',
            'publish_notice' => 'Le film, lui, n’est jamais publié automatiquement : la fiche proposera « Publier » si ses conditions sont remplies.',
            'submit' => 'Tout valider',
            'flash' => ':count image validée et publiée.|:count images validées et publiées.',
            'empty' => 'Ce film n’a plus aucune image en attente de revue à valider en lot : rien n’a été enregistré.',
            'stale_list' => 'Les images en attente de ce film ont changé depuis l’affichage : aucune revue n’a été enregistrée. Rechargez la page et vérifiez le nouveau lot.',
            'stale' => 'Une image du lot a changé depuis son affichage, un nouveau rendu l’a remplacée : aucune revue n’a été enregistrée. Rechargez la page et vérifiez le nouveau lot.',
        ],
    ],

    /*
    | Import — les deux voies et leur ASYMÉTRIE, dite avant les formulaires.
    | Un curateur qui ne la comprend pas collera des identifiants sans savoir
    | qu’il marque une exception.
    */
    /*
    | Resynchronisation TMDB (spec 20 § 3.7, L20-24). Écran de différences
    | d'un film, bornées à la liste close de ce qui est écrasable ; lot
    | depuis une page du catalogue. `fields.*` : un libellé par champ comparé.
    | Le filtre d'import n'est jamais réappliqué.
    */
    'resync' => [
        'title' => 'Resynchronisation TMDB',
        'heading' => 'Resynchroniser depuis TMDB',
        'description' => 'Relit les métadonnées TMDB des films choisis. Seules les métadonnées TMDB pures sont réécrites : vos titres et alias corrigés, la difficulté corrigée, les groupes, les thèmes ajoutés ou retirés à la main, la disponibilité, la coche de contenu et toutes les images restent intacts. Le filtre de notoriété n’est jamais réappliqué.',
        'selection' => [
            'heading' => 'Films choisis',
            'description' => ':eligible film(s) seront relus sur :total choisi(s), au plus :max par envoi.',
            'ineligible' => [
                'withdrawn' => 'Retiré : jamais relu',
                'demo' => 'Catalogue de démonstration : jamais relu',
                'no_tmdb' => 'Sans identifiant TMDB',
            ],
        ],
        'preview' => [
            'heading' => 'Différences avec la fiche TMDB actuelle',
            'description' => 'La fiche TMDB vient d’être relue. Les lignes marquées « changé » seront réécrites ; les autres restent identiques.',
            'read_at' => 'Certifications lues précédemment le :date.',
            'never_read' => 'Aucune certification n’avait encore été lue.',
            'no_change' => 'Aucune différence : la fiche TMDB correspond déjà au catalogue.',
            'not_found' => 'TMDB ne connaît plus cet identifiant : rien à relire.',
            'unavailable' => 'TMDB n’a pas répondu : réessayez dans un moment.',
            'not_configured' => 'Aucune clé TMDB n’est configurée sur le serveur : la resynchronisation est indisponible.',
            'column' => [
                'field' => 'Champ',
                'before' => 'Au catalogue',
                'after' => 'Sur TMDB',
                'state' => 'État',
            ],
            'changed' => 'Changé',
            'same' => 'Identique',
            'empty' => 'Aucune valeur',
            'curator_titles' => 'Titres corrigés à la main, jamais réécrits : :locales.',
            'reappeared' => [
                'heading' => 'Alias réapparus',
                'description' => 'Ces alias TMDB avaient été retirés à la main ; la resynchronisation les recréera. Retirez-les de nouveau après coup s’il le faut.',
            ],
            'blocked' => [
                'heading' => 'Certification restrictive découverte',
                'description' => 'La fiche TMDB porte désormais une classification restrictive : après la resynchronisation, le film sortira aussitôt du vivier. Il ne sera pas dépublié pour autant : c’est à vous de le faire.',
                'propose' => 'Dépublier le film ensuite',
            ],
        ],
        'fields' => [
            'title_original' => 'Titre original',
            'title_original_latin' => 'Translittération latine',
            'original_language' => 'Langue originale',
            'release_year' => 'Année de sortie',
            'vote_count' => 'Votes TMDB',
            'adult' => 'Marqué « adulte »',
            'collection_id' => 'Saga TMDB',
            'genres' => 'Genres TMDB',
            'companies' => 'Sociétés de production',
            'movie_certification' => 'Certifications',
            'movie_title' => 'Titres TMDB',
            'alias' => 'Alias TMDB',
            'content_flag' => 'Drapeau de contenu',
        ],
        'submit' => 'Lancer la resynchronisation',
        'submit_batch' => 'Resynchroniser :count film(s)',
        'running' => 'Une resynchronisation est déjà en cours : attendez qu’elle se termine.',
        'running_link' => 'Voir la resynchronisation en cours',
        'deferred_notice' => 'La resynchronisation tourne en tâche de fond : vous suivrez son résumé sur l’écran du balayage.',
        'back' => 'Retour au catalogue',
        'none_eligible' => 'Aucun des films choisis ne peut être resynchronisé.',
        'busy' => 'Une resynchronisation est déjà en cours : attendez qu’elle se termine.',
        'started' => 'Resynchronisation lancée.',
        'catalog_action' => 'Resynchroniser cette page',
    ],

    'import' => [
        'title' => 'Import du catalogue',
        'heading' => 'Import du catalogue',

        /*
        | Clore un balayage suspendu (spec 20 § 3.8, L20-24) : `failed`, et
        | une ligne au journal. Refusé tant que le traitement le tient.
        */
        'abandon' => [
            'action' => 'Clore ce balayage',
            'title' => 'Clore ce balayage',
            'description' => 'Le balayage passe « échoué » et ne se reprendra plus. Les films déjà entrés restent au catalogue, et ses compteurs gardent ce qu’il a fait.',
            'submit' => 'Clore le balayage',
            'done' => 'Balayage clos.',
            'busy' => 'Ce balayage avance encore : il ne se clôt qu’une fois suspendu depuis quelques minutes.',
        ],
        'description' => 'Deux voies d’entrée, et une asymétrie assumée. L’import est un acte d’administration : le jeu, lui, n’appelle jamais TMDB.',

        'asymmetry' => [
            'heading' => 'Ce que chaque voie fait du filtre',
            'discover' => 'Le balayage discover APPLIQUE le filtre de notoriété — seuil de votes, langue originale, année minimale. Un film qui n’y satisfait pas n’entre pas. Élargir un de ces trois axes n’est pas interdit : c’est tracé, le balayage est marqué élargi et les films entrés sont marqués « entrés par exception » avec leur motif.',
            'paste' => 'Le collage d’identifiants IGNORE entièrement le filtre de notoriété et marque CHAQUE film « entré par exception » avec son motif — même un film qui satisfait tout le filtre. C’est la voie qui fait entrer les classiques d’avant 1970 et les films en langue rare.',
            'content_filter' => 'Les filtres de CONTENU, eux, ne sont contournables par AUCUNE des deux voies, et par aucun rôle : contenu marqué « adulte », FR -18, US NC-17 et US X sont refusés dans tous les cas (décision 12).',
        ],

        'deferred_notice' => 'Les deux voies sont DIFFÉRÉES : l’envoi ouvre un balayage, le confie au traitement d’arrière-plan, puis vous redirige vers son détail. Aucun appel TMDB n’a lieu pendant la requête.',
        'disabled' => 'Aucune clé TMDB n’est configurée sur le serveur : les deux formulaires sont désactivés. L’administrateur du site doit la renseigner.',

        'discover' => [
            'heading' => 'Balayage discover',
            'description' => 'La voie ordinaire : elle applique le filtre de notoriété.',
            'min_votes' => [
                'label' => 'Seuil de votes',
                'hint' => 'Défaut du site : :default. Zéro est un filtre légitime — il dit « je prends tout ».',
            ],
            'languages' => [
                'label' => 'Langues originales',
                'hint' => 'Défaut du site : :default. Ajouter une langue hors du défaut élargit le filtre.',
            ],
            'min_year' => [
                'label' => 'Année minimale',
                'hint' => 'Défaut du site : :default. Descendre sous cette année élargit le filtre.',
            ],
            'pages' => [
                'label' => 'Pages TMDB',
                'option' => ':pages page(s) — jusqu’à :movies fiches',
                'hint' => 'Le balayage tourne en tâche de fond, par passages de dix minutes : vous pouvez quitter l’écran. Les fiches déjà au catalogue ou hors filtre ne comptent pas, et un balayage reprend où le précédent s’est arrêté.',
            ],
            'widened_warning' => 'Ce balayage ÉLARGIT le filtre par défaut : il sera marqué élargi, et tous les films qu’il fera entrer seront marqués « entrés par exception » avec leur motif.',
            'submit' => 'Lancer le balayage',
        ],

        'ids' => [
            'heading' => 'Collage d’identifiants',
            'description' => 'La voie d’exception : elle ignore le filtre de notoriété.',
            'list' => [
                'label' => 'Identifiants ou URL TMDB',
                'placeholder' => "550\nhttps://www.themoviedb.org/movie/27205\n# une ligne par film, les commentaires sont ignorés",
                'hint' => 'Un identifiant ou une URL TMDB par ligne, :max au maximum par envoi.',
            ],
            'exception_notice' => 'Tout film entré par cette voie est marqué « entré par exception », même s’il satisfait tout le filtre de notoriété.',
            'submit' => 'Importer ces identifiants',
            'preview' => 'Prévisualiser',
            'preview_hint' => 'L’aperçu à blanc lit chaque fiche sans rien importer et montre le sort de chaque identifiant : c’est le geste normal avant tout import, un identifiant erroné important sinon un autre film en silence.',
        ],

        /*
        | Thèmes choisis au collage (spec 20 § 3.3, D43 du 01/10). `selected`
        | reçoit `:count` et `:max` ; `recap`, `:themes`, la liste déjà jointe.
        */
        'themes' => [
            'heading' => 'Thèmes à appliquer (facultatif)',
            'help' => 'Chaque film importé ou déjà au catalogue de ce collage sera ajouté aux thèmes cochés, signé de votre nom. Un film qu’un curateur a retiré d’un thème n’y est jamais remis ; un film déjà dans un thème n’est pas touché. Les films refusés n’en reçoivent aucun.',
            'selected' => ':count thèmes cochés sur :max au plus',
            'none' => 'Aucun thème ne sera appliqué.',
            'empty' => 'Aucun thème n’existe encore : créez-en depuis l’écran des thèmes.',
            'recap' => 'Thèmes appliqués à ce collage : :themes',
            'separator' => ', ',
            'unpublished' => 'non publié',
            'too_many' => 'Au plus :max thèmes par collage : décochez-en un pour en cocher un autre.',
            'clear' => 'Tout décocher',
        ],

        /*
        | Recherche TMDB et import unitaire (spec 20 § 3.4). `results` reçoit
        | `:count` et `:query` ; `state.in_catalog`, `:availability`, le
        | libellé déjà traduit de la disponibilité du film.
        */
        'search' => [
            'heading' => 'Rechercher un film sur TMDB',
            'description' => 'Pour faire entrer un film précis, un à la fois. Importer un résultat ouvre un collage d’un seul identifiant : le film sera marqué « entré par exception », même s’il satisfait tout le filtre de notoriété.',
            'label' => 'Titre recherché',
            'placeholder' => 'Un titre, dans n’importe quelle langue',
            'hint' => 'Entre :min et :max caractères. Seule la première page de résultats de TMDB est affichée, sans contenu pour adultes.',
            'submit' => 'Rechercher',
            'close' => 'Fermer la recherche',
            'loading' => 'Recherche en cours sur TMDB…',
            'results' => ':count résultats sur TMDB pour « :query »',
            'empty' => 'TMDB ne connaît aucun film sous ce titre. Essayez le titre original, ou une autre graphie.',
            'failed' => 'TMDB n’a pas pu répondre à cette recherche. Réessayez dans un instant ; si l’échec persiste, signalez-le à l’administrateur du site.',
            'retry' => 'Réessayer',
            'live_paused' => 'Le suivi en direct des balayages et de l’aperçu est suspendu pendant la recherche : fermez-la pour le reprendre.',
            'column' => [
                'title' => 'Titre',
                'title_original' => 'Titre original',
                'year' => 'Année',
                'language' => 'Langue originale',
                'votes' => 'Votes',
                'state' => 'Au catalogue',
                'actions' => 'Actions',
            ],
            'state' => [
                'absent' => 'Absent du catalogue',
                'in_catalog' => 'Déjà au catalogue — :availability',
                'withdrawn' => 'Retiré — réimport bloqué',
            ],
            'import' => 'Importer',
            'import_label' => 'Importer « :title »',
            'import_busy' => 'Un collage est déjà ouvert : l’import d’un résultat redevient possible à sa fin.',
            'open_movie' => 'Ouvrir la fiche',
        ],

        /*
        | Aperçu à blanc d'un collage (spec 20 § 3.3). `progress` reçoit
        | `:processed` et `:total` ; `decision.*`, une feuille par sort de
        | `ImportDecision`, les huit, même ceux qu'un aperçu ne produit pas.
        */
        'preview' => [
            'heading' => 'Aperçu à blanc',
            'description' => 'Le sort de chaque identifiant, sans rien importer. L’aperçu est indicatif : l’import réel rejoue toutes les gardes, filtre de contenu compris.',
            'pending' => 'L’aperçu attend le traitement d’arrière-plan : il démarrera de lui-même.',
            'running' => 'Lecture des fiches TMDB en cours…',
            'progress' => ':processed identifiants lus sur :total',
            'failed' => 'L’aperçu n’a pas pu aller au bout : TMDB n’a pas répondu. Les lignes déjà lues restent affichées ; réessayez dans un instant.',
            'retry' => 'Réessayer l’aperçu',
            'import' => 'Importer ces films',
            'import_hint' => 'Ouvre un collage réel de ces identifiants : chaque film entrera « par exception », et les refus seront comptés au balayage.',
            'empty' => 'Aucun identifiant n’a encore été lu.',
            'exception' => 'Entrera par exception',
            'toast' => [
                'queued' => 'Aperçu lancé : le sort de chaque identifiant s’affiche ci-dessous dès qu’il est lu. Rien n’est importé.',
            ],
            'column' => [
                'tmdb_id' => 'Identifiant TMDB',
                'title' => 'Titre original',
                'year' => 'Année',
                'decision' => 'Sort',
                'detail' => 'Motif',
            ],
            'decision' => [
                'simulated' => 'Serait importé',
                'imported' => 'Importé',
                'resynchronized' => 'Relu',
                'duplicate' => 'Déjà au catalogue',
                'skipped_by_filter' => 'Sous le filtre de notoriété',
                'refused_content' => 'Refusé — filtre de contenu',
                'refused_withdrawn' => 'Refusé — film retiré',
                'not_found' => 'Inconnu de TMDB',
            ],
        ],

        /*
        | Liste d'amorçage (spec 20 § 3.5). `total`, `remaining` et `batch`
        | reçoivent `:count`.
        */
        'seed_list' => [
            'heading' => 'Liste d’amorçage',
            'description' => 'La liste versionnée des classiques qui n’entrent que par exception — canon Disney d’avant 1970, classiques non anglophones. Elle s’importe lot par lot, un collage à la fois : chaque clic reprend au premier identifiant absent du catalogue.',
            'caveat' => 'Un identifiant refusé ou inconnu de TMDB n’entre jamais au catalogue et reste donc compté parmi les restants : signalez-le à l’administrateur pour qu’il soit retiré de la liste.',
            'total' => ':count identifiants dans la liste',
            'remaining' => ':count identifiants restent à importer',
            'batch' => 'Le prochain lot porte :count identifiants, dans l’ordre de la liste.',
            'submit' => 'Importer la liste d’amorçage',
            'preview' => 'Prévisualiser le lot suivant',
            'empty' => 'La liste d’amorçage ne contient encore aucun identifiant : le bouton s’activera quand elle sera constituée.',
            'busy' => 'Un collage est en cours : le lot suivant pourra partir à sa fin.',
            'busy_link' => 'Suivre le collage en cours',
            'done' => 'Chaque identifiant de la liste d’amorçage est déjà au catalogue : il n’y a plus rien à importer.',
        ],

        'toast' => [
            'queued' => 'Balayage ouvert : il est confié au traitement d’arrière-plan. Son détail suit son avancement.',
            'resumed' => 'Balayage remis dans la file : il reprendra là où il s’était arrêté.',
            'blocked' => 'Import refusé.',
        ],

        'runs' => [
            'heading' => 'Journal des balayages',
            'description' => 'Chaque balayage garde son filtre figé et ses quatre compteurs : c’est la preuve opposable de ce qu’il a fait.',
            'empty' => 'Aucun balayage enregistré.',
            'column' => [
                'id' => 'N°',
                'kind' => 'Nature',
                'status' => 'État',
                'actor' => 'Auteur',
                'filter' => 'Filtre appliqué',
                'seen' => 'Vus',
                'imported' => 'Importés',
                'skipped' => 'Ignorés',
                'refused' => 'Refusés',
                'started_at' => 'Démarré',
                'finished_at' => 'Terminé',
            ],
            'widened' => 'Filtre élargi',
            'open' => 'Détail',
            'resume' => 'Reprendre',
            'resume_unavailable_paste' => 'Un collage n’est pas reprenable : la liste collée n’est stockée nulle part. Re-collez-la.',
            'resume_unavailable_finished' => 'Ce balayage est terminé : le reprendre relancerait un curseur déjà consommé.',
            'resume_unavailable_queued' => 'Ce balayage n’a pas encore démarré : il attend le traitement d’arrière-plan, il n’y a rien à reprendre.',
            'worker_missing' => 'Ce balayage attend depuis plus d’une minute : le traitement d’arrière-plan ne répond pas. L’administrateur est prévenu ; le balayage partira de lui-même dès la reprise du traitement.',
        ],

        'run' => [
            'title' => 'Détail du balayage',
            'heading' => 'Balayage n° :id',
            'back' => 'Retour à l’import',
            'live' => 'Balayage en cours : cet écran se rafraîchit tout seul.',

            'summary' => [
                'heading' => 'Résumé',
                'kind' => 'Nature',
                'status' => 'État',
                'actor' => 'Auteur',
                'started_at' => 'Démarré le',
                'finished_at' => 'Terminé le',
                'duration' => 'Durée',
            ],

            'filter' => [
                'heading' => 'Filtre appliqué',
                'min_votes' => 'Seuil de votes',
                'languages' => 'Langues originales',
                'min_year' => 'Année minimale',
                'ignored' => 'Ce filtre a été ENREGISTRÉ mais NON APPLIQUÉ : un collage ignore le filtre de notoriété. Il documente le défaut en vigueur au moment du geste, ce qui rend les motifs d’exception relisibles des mois plus tard.',
                'widened' => 'Ce filtre est plus large que le défaut du site : le balayage est marqué élargi.',
            ],

            'counters' => [
                'heading' => 'Compteurs',
                'description' => 'Quatre compteurs distincts : « ignoré » n’est pas « refusé ». Un refus vient du filtre de contenu, jamais du filtre de goût.',
                'seen' => 'Fiches vues',
                'imported' => 'Films importés',
                'skipped' => 'Ignorés',
                'refused' => 'Refusés par le filtre de contenu',
            ],

            'cursor' => [
                'heading' => 'Reprise',
                'position' => 'Position du curseur',
                'none' => 'Aucun curseur enregistré.',
                'last_request_at' => 'Dernier appel TMDB',
            ],

            'themes' => [
                'heading' => 'Thèmes du collage',
                'description' => 'Les thèmes choisis au collage, posés en ajout manuel signé de l’auteur du collage. Un film qu’un curateur avait retiré d’un thème n’y a pas été remis.',
                'none' => 'Aucun thème choisi pour ce collage.',
                'applied' => 'Ajouts posés (film × thème)',
                'kept_removed' => 'Laissés hors du thème par un retrait de curateur',
                'unpublished' => 'non publié',
            ],

            'movies' => [
                'heading' => 'Films entrés par ce balayage',
                'description' => 'La liste exacte de ce que ce balayage a fait entrer au catalogue.',
                'empty' => 'Ce balayage n’a encore fait entrer aucun film.',
            ],
        ],
    ],

    /*
    | Annuaire des comptes (`admin/users/index`, spec 20 § 2.8) — administrateur
    | seul, en lecture seule. `results` : `:total`, un nombre déjà mis en forme.
    | Tous les comptes y figurent, joueurs compris ; les gestes se font depuis
    | la fiche d’un compte ou l’écran des accès.
    */
    /*
    | Écran des thèmes — spec 20 § 9.6, ligne 28 de la matrice (J1 depuis D43
    | du 01/10). Créer un thème de toute nature créable ou sans règle, corriger
    | sa règle, ses libellés et son ordre, le publier sous seuil ou le
    | dépublier. Les refus des gestes sont des clés littérales, jamais
    | construites par concaténation.
    */
    // L'écran « Lots d'images » (spec 20 § 5.10, D57 du 05/10).
    'frame_batch' => [
        'title' => 'Lots d’images',
        'heading' => 'Lots d’images',
        'description' => 'Déposez un lot d’images préparé et validé hors production : chaque image entre en brouillon dans la banque de son film, au niveau et au cadre du lot. La revue et la publication restent à faire, film par film.',
        'upload' => [
            'title' => 'Déposer un lot',
            'description' => 'Un fichier .json au format des lots d’images, de :max Ko au plus. Rien n’est importé avant votre confirmation.',
            'label' => 'Fichier du lot',
            'submit' => 'Voir l’aperçu',
        ],
        'preview' => [
            'title' => 'Aperçu du lot',
            'description' => ':movies film(s), :frames image(s). Les films absents du catalogue et les films suspendus ou retirés ne reçoivent aucune image.',
            'import' => 'Importer :frames image(s)',
            'nothing' => 'Aucun film de ce lot ne peut recevoir d’images.',
        ],
        'progress' => ':done film(s) traité(s) sur :total',
        'state' => [
            'previewed' => 'Aperçu',
            'pending' => 'En attente',
            'running' => 'Import en cours',
            'completed' => 'Terminé',
            'failed' => 'Interrompu',
        ],
        'status' => [
            'ready' => 'Prêt',
            'missing' => 'Absent du catalogue',
            'locked' => 'Suspendu ou retiré',
        ],
        'columns' => [
            'movie' => 'Film',
            'status' => 'État',
            'frames' => 'Images',
            'known' => 'Déjà en banque',
            'result' => 'Résultat',
        ],
        'result' => [
            'waiting' => 'À importer',
            'summary' => ':added ajoutée(s), :skipped déjà présente(s), :refused refusée(s)',
            'none' => '—',
        ],
        'missing' => [
            'title' => 'Films absents du catalogue',
            'description' => 'Importez d’abord ces films par le collage. Une fois le collage terminé, revenez sur cet écran : les films importés deviennent prêts, sans redéposer le lot.',
            'sliced' => 'Un collage importe au plus :max films : :total sont absents. Relancez le bouton une fois chaque collage terminé.',
            'import' => 'Importer :count film(s) absent(s)',
        ],
        'next' => 'Les images ajoutées attendent leur traitement, puis votre revue : ouvrez la file de revue pour les valider film par film.',
        'open_review' => 'Ouvrir la file de revue',
        'new_batch' => 'Déposer un autre lot',
        'empty' => 'Aucun lot déposé récemment.',
        'toast' => [
            'queued' => 'Import du lot lancé : l’avancement s’affiche ci-dessous.',
        ],
        'expired' => 'Ce lot a expiré ou a déjà été importé : déposez-le de nouveau.',
        'failed' => 'L’import du lot s’est interrompu : les films déjà traités le restent. Déposez de nouveau le lot pour reprendre ; les images déjà ajoutées seront reconnues.',
        'snapshot_failed' => 'La sauvegarde préalable a échoué : aucune image n’a été ajoutée.',
        'frame_failed' => 'échec imprévu de l’ajout ; redéposez le lot plus tard.',
        'blocked' => 'visuel suspendu ou retiré dans la banque du film : jamais réajouté par un lot.',
        'invalid' => [
            'required' => 'Choisissez le fichier du lot.',
            'json' => 'Ce fichier n’est pas un lot lisible (JSON attendu).',
            'format' => 'Ce fichier n’est pas un lot d’images de TripleFrames, ou d’une version inconnue.',
            'empty' => 'Ce lot ne contient aucun film.',
            'too_large' => 'Le fichier dépasse :max Ko.',
            'too_many_movies' => 'Un lot compte au plus :max films.',
            'duplicate_movie' => 'Le film TMDB :tmdb_id apparaît deux fois dans le lot.',
            'movie' => 'Le film n° :position du lot est mal formé.',
            'no_frames' => 'Le film TMDB :tmdb_id ne porte aucune image.',
            'too_many_frames' => 'Le film TMDB :tmdb_id porte plus de :max images.',
            'frame' => 'Une image du film TMDB :tmdb_id est mal formée.',
        ],
    ],

    'themes' => [
        'title' => 'Thèmes',
        'heading' => 'Thèmes',
        'description' => 'Chaque thème, publié ou non, avec sa règle, ses œuvres et ses films. Un thème rassemble les films qui satisfont sa règle, plus les ajouts manuels de la fiche film, moins ses retraits manuels.',
        'threshold' => 'Un thème se publie quel que soit son nombre d’œuvres. Sous :min œuvres au réglage par défaut (:frames images par manche), un salon qui le choisirait seul serait bloqué au lancement. Dépublier est toujours permis.',
        'selector_hidden' => 'Le sélecteur de thèmes reste masqué aux joueurs au jalon 1 : publier un thème le prépare, sans le montrer encore.',
        'create' => 'Créer un thème',
        'empty' => 'Aucun thème de cette nature.',
        'none' => 'Aucun thème n’existe encore.',
        'column' => [
            'key' => 'Clé',
            'label_fr' => 'Libellé FR',
            'label_en' => 'Libellé EN',
            'rule' => 'Règle',
            'works' => 'Œuvres',
            'active_films' => 'Films actifs',
            'published' => 'Publié',
            'sort_order' => 'Ordre',
            'actions' => 'Gestes',
        ],
        'works_hint' => 'Œuvres jouables du thème seul, à :frames images par manche.',
        'status' => [
            'published' => 'Publié',
            'unpublished' => 'Non publié',
        ],
        'missing_labels' => 'Libellé manquant : :locales',
        'rule' => [
            'manual' => 'Sans règle : ajouts manuels seulement',
            'negated' => 'Tout sauf',
            'named' => ':name (:value)',
        ],
        'actions' => [
            'edit' => 'Modifier',
            'publish' => 'Publier',
            'unpublish' => 'Dépublier',
        ],
        'a11y' => [
            'edit' => 'Modifier le thème :key',
            'publish' => 'Publier le thème :key',
            'unpublish' => 'Dépublier le thème :key',
            'group' => 'Thèmes de nature :kind',
        ],
        'form' => [
            'create_title' => 'Créer un thème',
            'create_description' => 'Le thème naît non publié. Ses films sont calculés juste après la création, en tâche de fond ; la clé, dérivée du libellé anglais, ne changera plus.',
            'edit_title' => 'Modifier le thème :key',
            'edit_description' => 'La nature et la clé d’un thème ne changent jamais. Corriger la règle ou sa négation recalcule ses films en tâche de fond ; un libellé ou un ordre seuls ne recalculent rien.',
            'kind' => 'Nature',
            'kind_fixed' => 'Nature : :kind (immuable)',
            'manual' => 'Sans règle',
            'manual_help' => 'Le thème ne contiendra que les films ajoutés à la main depuis leur fiche.',
            'rule' => 'Règle',
            'negated' => 'Nier la règle',
            'negated_help' => 'Le thème rassemble alors tous les films qui ne satisfont PAS la règle (un film sans la donnée n’y entre jamais).',
            'label_fr' => 'Libellé français',
            'label_en' => 'Libellé anglais',
            'sort_order' => 'Ordre d’affichage',
            'sort_order_help' => 'Laissé vide, le thème se range en fin de bloc de sa nature.',
            'key_preview' => 'Clé prévue : :key',
            'key_pending' => 'La clé sera dérivée du libellé anglais.',
            'key_fixed' => 'Clé : :key (immuable)',
            'prefill' => 'Saga préremplie depuis la collection « :name ».',
            'submit_create' => 'Créer le thème',
            'submit_update' => 'Enregistrer',
        ],
        'picker' => [
            'loading' => 'Chargement des valeurs présentes au catalogue…',
            'failed' => 'Les valeurs du catalogue n’ont pas pu être chargées.',
            'retry' => 'Réessayer',
            'empty' => 'Aucune valeur de cette nature n’est encore présente au catalogue.',
            'choose' => 'Choisir…',
            'option' => ':label — :count film(s)',
            'taken' => ':label — déjà désignée par :key',
            'genre' => 'Genre :id',
            'current' => ':label (valeur actuelle)',
            'selected' => 'Sociétés retenues',
            'none_selected' => 'Aucune société retenue.',
            'remove' => 'Retirer :label',
            'add' => 'Ajouter',
            'company_id' => 'Identifiant TMDB d’une société',
            'company_id_help' => 'Pour une société encore absente du catalogue (correction d’un thème livré). À la création, chaque société doit être portée par au moins un film.',
            'limit' => 'Au plus :max sociétés par thème.',
            'add_company' => 'Ajouter une société du catalogue',
        ],
        'publish' => [
            'title_publish' => 'Publier le thème :key',
            'title_unpublish' => 'Dépublier le thème :key',
            'description_publish' => 'Le thème compte :works œuvre(s) ; une partie au réglage par défaut en demande :min. Publier ne recalcule rien : seuls ses films actifs y entrent.',
            'description_unpublish' => 'Le thème sort des réglages proposés aux salons ; ses films et ses exceptions manuelles sont conservés.',
            'below_threshold' => 'Moins de :min œuvres : la publication est permise, mais un salon qui choisirait ce thème seul au réglage par défaut serait bloqué au lancement.',
            'notice' => [
                'live_action_japanese' => 'Ce thème rassemble tous les films en japonais, prise de vue réelle comprise. Avant de le publier, retirez-en à la main les films japonais qui ne sont pas des animés : le seuil d’œuvres ne le vérifie pas.',
            ],
            'submit_publish' => 'Publier',
            'submit_unpublish' => 'Dépublier',
        ],
        'flash' => [
            'created' => 'Thème :key créé, non publié. Ses films se calculent en tâche de fond.',
            'updated' => 'Thème :key enregistré.',
            'unchanged' => 'Rien n’a changé : le thème est déjà dans cet état.',
            'published' => 'Thème :key publié.',
            'unpublished' => 'Thème :key dépublié.',
        ],
        'busy' => 'Un autre geste sur les thèmes est en cours : réessayez dans un instant.',
        'kind_forbidden' => 'Les thèmes de difficulté sont livrés avec le site : cette nature ne se crée pas.',
        'kind_immutable' => 'La nature d’un thème ne change jamais : créez un autre thème.',
        'key_immutable' => 'La clé d’un thème ne change jamais.',
        'key_invalid' => 'Ce libellé anglais ne donne aucune clé : utilisez au moins une lettre ou un chiffre.',
        'key_taken' => 'La clé :key existe déjà : choisissez un autre libellé anglais.',
        'collection_taken' => 'Cette collection est déjà la saga du thème :key.',
        'company_taken' => 'Une de ces sociétés est déjà désignée par le thème :key.',
        'company_absent' => 'Aucun film du catalogue ne porte ces sociétés : :ids.',
        'genre_absent' => 'Aucun film du catalogue ne porte ce genre.',
        'decade_invalid' => 'Une décennie s’écrit par son année de début, multiple de 10 (1990).',
        'language_invalid' => 'Une langue s’écrit par son code de deux lettres minuscules (ja, fr).',
        'rule_too_long' => 'La liste des sociétés dépasse :max caractères : retirez-en une.',
        'negation_forbidden' => 'Une saga et un thème sans règle ne se nient pas.',
        'labels_missing' => 'Ce thème n’a pas de libellé dans chaque langue : complétez-les avant de le publier.',
    ],

    'users' => [
        'title' => 'Comptes',
        'heading' => 'Annuaire des comptes',
        'description' => 'Tous les comptes du site, joueurs compris. Cet écran ne modifie rien : le rôle et le nom réel d’un compte se changent depuis sa fiche ou depuis l’écran des accès.',
        'results' => ':total compte(s) au filtre courant',

        'counts' => [
            'total' => 'Comptes actifs',
            'players' => 'Joueurs',
            'curators' => 'Curateurs',
            'admins' => 'Administrateurs',
        ],

        'filters' => [
            'heading' => 'Recherche et filtres',
            'search' => [
                'label' => 'Recherche',
                'placeholder' => 'Adresse, pseudo, nom réel ou identifiant',
                'hint' => 'Cherche dans l’adresse, le pseudo du compte et le nom réel ; une saisie entièrement numérique cherche aussi l’identifiant du compte.',
            ],
            'role' => 'Rôle',
            'state' => [
                'label' => 'État du compte',
                'active' => 'Actifs',
                'anonymized' => 'Anonymisés',
            ],
            'submit' => 'Filtrer',
            'reset' => 'Tout effacer',
        ],

        'sort' => [
            'label' => 'Tri',
            'direction' => 'Sens',
            'created_at' => 'Date de création',
            'last_login_at' => 'Dernière connexion',
            'name' => 'Pseudo',
            'email' => 'Adresse',
        ],

        'list' => [
            'heading' => 'Comptes',
        ],

        'column' => [
            'name' => 'Pseudo',
            'email' => 'Adresse',
            'real_name' => 'Nom réel',
            'role' => 'Rôle',
            'two_factor' => 'Double authentification',
            'last_login_at' => 'Dernière connexion',
            'created_at' => 'Créé le',
            'actions' => 'Actions',
        ],

        'row' => [
            'open' => 'Ouvrir',
        ],

        'empty' => [
            'heading' => 'Aucun compte',
            'filtered' => 'Aucun compte ne correspond à cette recherche. Effacez les filtres pour revoir tout l’annuaire.',
            'no_accounts' => 'Aucun compte n’existe encore.',
        ],
    ],

    /*
    | Fiche d’un compte (`admin/users/show`, spec 20 § 2.8) — administrateur
    | seul. Elle ne montre jamais les configurations sauvegardées, privées y
    | compris d’un administrateur, ni aucun secret, et elle le dit
    | (`private_notice`).
    */
    'account' => [
        'title' => 'Fiche du compte',
        'description' => 'Identité, sécurité, consentements et historique des accès de ce compte.',
        'back' => 'Retour à l’annuaire',
        'never' => 'Jamais',
        'two_factor' => [
            'on' => 'Double authentification active',
            'off' => 'Sans double authentification',
        ],
        'state' => [
            'anonymized' => 'Compte anonymisé',
        ],
        'email' => [
            'unverified' => 'Adresse non vérifiée',
        ],
        'access' => [
            'heading' => 'Rôle et accès',
            'self' => 'C’est votre compte : votre propre rôle ne se change pas ici. Un autre administrateur peut le faire.',
            'anonymized' => 'Ce compte est anonymisé : il ne reçoit plus aucun rôle. Ce qu’il a signé — revues, lignes du journal — reste lisible.',
        ],
        'identity' => [
            'heading' => 'Identité',
            'id' => 'Identifiant',
            'name' => 'Pseudo du compte',
            'real_name' => 'Nom réel',
            'email' => 'Adresse',
            'email_verified_at' => 'Adresse vérifiée le',
            'locale' => 'Langue d’interface',
            'created_at' => 'Créé le',
            'last_login_at' => 'Dernière connexion',
            'anonymized_at' => 'Anonymisé le',
        ],
        'security' => [
            'heading' => 'Sécurité',
            'two_factor' => 'Double authentification confirmée le',
            'passkeys' => 'Clés d’accès enregistrées',
            'providers' => 'Comptes liés',
        ],
        'consents' => [
            'heading' => 'Consentements',
            'terms_version' => 'Version des CGU acceptée',
            'terms_accepted_at' => 'CGU acceptées le',
            'age_confirmed_at' => 'Âge minimum confirmé le',
        ],
        'traces' => [
            'heading' => 'Preuves signées',
            'description' => 'Ce que ce compte a signé. Ces preuves sont conservées même si le compte est un jour anonymisé.',
            'frame_reviews' => 'Revues d’image',
            'admin_actions' => 'Lignes du journal d’administration',
            'import_runs' => 'Balayages d’import lancés',
        ],
        'history' => [
            'heading' => 'Historique des accès',
            'description' => 'Les changements de rôle et de nom réel de ce compte, du plus récent au plus ancien, tels que le journal d’administration les a consignés.',
            'empty' => 'Aucun changement consigné pour ce compte.',
        ],
        'private_notice' => 'Les configurations de salon sauvegardées par ce compte restent privées, y compris d’un administrateur : elles n’apparaissent sur aucun écran du back-office.',
    ],

    /*
    | Gestion des accès (`admin/access/index`, spec 20 § 2.8) — administrateur
    | seul. `second_admin_missing.description` : `:count`, un nombre déjà mis
    | en forme. `history.description` : `:count`, la borne de la liste.
    | `history.role_change` : `:before` et `:after`, deux libellés de rôle.
    | `promote.not_found` : `:email`, l’adresse cherchée. Les deux boîtes de
    | dialogue reçoivent `:name`, le pseudo du compte. `errors.*` : refus
    | relus sous verrou par le serveur, sous leur champ ; `flash.*` : toasts.
    */
    'access' => [
        'title' => 'Accès',
        'heading' => 'Gestion des accès',
        'description' => 'Qui cure et qui administre le projet. Chaque changement de rôle et chaque correction de nom réel sont inscrits au journal d’administration, signés de votre nom réel.',
        'second_admin_missing' => [
            'title' => 'Un second administrateur nominatif manque',
            'description' => 'Le projet compte :count administrateur(s). Deux administrateurs nominatifs sont exigés avant l’ouverture publique du site : si l’unique administrateur perd son accès, personne d’autre ne peut le rétablir depuis cet écran.',
        ],
        'privileged' => [
            'heading' => 'Comptes privilégiés',
            'description' => 'Les curateurs et les administrateurs. Un compte rétrogradé en joueur quitte cette liste ; son nom réel est conservé pour une attribution future.',
            'empty' => 'Aucun compte privilégié.',
        ],
        'without_two_factor' => [
            'heading' => 'Rôle privilégié sans double authentification',
            'description' => 'Ces comptes ont un rôle de curateur ou d’administrateur, mais la porte du back-office leur reste fermée tant qu’ils n’ont pas confirmé leur double authentification.',
            'empty' => 'Aucun : tous les comptes privilégiés ont confirmé leur double authentification.',
            'since' => 'Dernière connexion : :date',
        ],
        'promote' => [
            'heading' => 'Promouvoir un compte existant',
            'description' => 'Saisissez l’adresse exacte du compte. Le compte doit exister et son adresse être vérifiée ; il n’est jamais créé depuis cet écran.',
            'field' => 'Adresse exacte du compte',
            'submit' => 'Chercher',
            'not_found' => 'Aucun compte actif ne porte l’adresse « :email ».',
        ],
        'history' => [
            'heading' => 'Historique des rôles et des noms réels',
            'description' => 'Les :count derniers changements, du plus récent au plus ancien. L’historique complet d’un compte se lit sur sa fiche.',
            'empty' => 'Aucun changement consigné.',
            'role_change' => ':before → :after',
            'column' => [
                'at' => 'Date',
                'subject' => 'Compte',
                'action' => 'Geste',
                'change' => 'Changement',
                'actor' => 'Signé par',
                'reason' => 'Motif',
            ],
            'actor_reserved' => [
                'console' => 'Accès direct au serveur (premier administrateur)',
                'system' => 'Geste automatique (seuil de signalements)',
            ],
        ],
        'column' => [
            'real_name' => 'Nom réel',
            'name' => 'Pseudo',
            'email' => 'Adresse',
            'role' => 'Rôle',
            'two_factor' => 'Double authentification',
            'last_login_at' => 'Dernière connexion',
            'actions' => 'Gestes',
        ],
        'self' => 'Votre compte',
        'actions' => [
            'change_role' => 'Changer le rôle',
            'correct_real_name' => 'Corriger le nom réel',
        ],
        'reason_optional' => 'Motif (facultatif), inscrit au journal',
        'role_dialog' => [
            'title' => 'Changer le rôle de :name',
            'description' => 'Le changement prend effet immédiatement et s’inscrit au journal d’administration, avec le rôle avant et après.',
            'role' => 'Nouveau rôle',
            'unchanged' => 'Choisissez un rôle différent du rôle actuel.',
            'privileged_notice' => 'Un curateur cure, revoit et publie ; un administrateur fait tout cela et gère en plus les accès. Sans double authentification confirmée, la porte du back-office restera fermée à ce compte jusqu’à son enrôlement.',
            'player_notice' => 'Le compte perd tout accès au back-office. Son nom réel est conservé, et ses preuves déjà signées restent intactes.',
            'email_unverified' => 'L’adresse de ce compte n’est pas vérifiée : un rôle privilégié lui sera refusé tant qu’elle ne l’est pas.',
            'real_name' => 'Nom réel de la personne',
            'real_name_hint' => 'Exigé pour un rôle privilégié : il signera ses revues et ses gestes au journal. Jamais un pseudo.',
            'submit' => 'Changer le rôle',
        ],
        'real_name_dialog' => [
            'title' => 'Corriger le nom réel de :name',
            'description' => 'Le nom réel signe les revues d’image et le journal d’administration. La correction y est inscrite.',
            'notice' => 'La correction ne vaut que pour les gestes suivants : les revues et les lignes du journal déjà signées gardent l’ancien nom.',
            'field' => 'Nom réel',
            'unchanged' => 'Saisissez un nom différent du nom actuel.',
            'hint' => 'Le nom complet de la personne, jamais un pseudo.',
            'submit' => 'Corriger le nom réel',
        ],
        'errors' => [
            'self' => 'Vous ne pouvez pas changer votre propre rôle : un autre administrateur doit le faire.',
            'unchanged' => 'Ce compte porte déjà ce rôle.',
            'email_unverified' => 'L’adresse de ce compte n’est pas vérifiée : un rôle privilégié ne peut pas lui être attribué.',
            'real_name_required' => 'Saisissez le nom réel de la personne : il est exigé pour un rôle privilégié.',
            'last_admin' => 'C’est le dernier administrateur du projet : nommez-en un autre avant de lui retirer ce rôle.',
            'real_name_unchanged' => 'Ce nom est déjà le nom réel du compte.',
        ],
        'flash' => [
            'role_changed' => 'Rôle changé et inscrit au journal.',
            'real_name_corrected' => 'Nom réel corrigé et inscrit au journal.',
        ],
    ],

    /*
    | Noms d’attribut des FormRequests d’administration. Ce sont des NOMS, pas
    | des phrases : Laravel compose le message depuis `lang/fr/validation.php`.
    | Les trois clés `ids.*` et `real_name`, elles, sont des messages complets
    | — `real_name` refuse un nom réservé au journal (`RealNameValidationRules`).
    | Les quatre clés `crop.*` aussi : une par cas de
    | `App\Support\Frames\CropViolation`, qui les nomme
    | (`CropViolation::translationKey()`), lues par le contrôleur d’ajout et par
    | le retour immédiat du recadreur (spec 20 § 5.2 et § 6.3). Et
    | `frame_source.dimensions`, le refus d’un visuel ou d’une capture trop
    | étroits ou en portrait avant tout dépôt d’octets (§ 5.3, § 5.4),
    | `:width` étant la largeur d’une image de jeu ; `frame_source.max` et
    | `frame_source.mimetypes`, les refus d’une capture reçue (§ 5.4),
    | `:max` étant le plafond d’un envoi en Ko — le même message couvre un
    | fichier que le serveur a refusé avant toute validation.
    */
    /*
    | Journal d’administration (`admin/journal/index`, spec 20 § 2.2, ligne 41 ;
    | D41 du 30/09) — administrateur seul, en lecture seule. Chaque ligne
    | nomme son auteur par le nom réel qu’il a signé à l’instant du geste.
    | `details.*` : les champs du complément `admin_action.details`, un par
    | clé des constructeurs d’`AdminActionDetails` ; une clé inconnue
    | s’affiche brute.
    */
    'journal' => [
        'title' => 'Journal',
        'heading' => 'Journal d’administration',
        'description' => 'Qui a fait quoi dans le back-office, du plus récent au plus ancien : chaque geste de curation, d’import et d’accès, et chaque consultation de l’annuaire, d’une fiche de compte ou de l’écran des accès. Aucune ligne ne se modifie ni ne se supprime depuis cet écran.',
        'results' => ':total ligne(s) au filtre courant',
        'history' => 'Historique',

        'filters' => [
            'heading' => 'Filtres',
            'actor' => 'Auteur',
            'action' => 'Action',
            'from' => 'Du',
            'to' => 'Au',
            'period_hint' => 'Bornes incluses, jour entier.',
            'subject_type' => 'Type de sujet',
            'subject_id' => 'Numéro du sujet',
            'subject_id_hint' => 'Le numéro affiché dans l’adresse de la fiche : exige un type de sujet.',
            'submit' => 'Filtrer',
            'reset' => 'Tout effacer',
        ],

        'actor' => [
            'system' => 'Système (seuil de signalement)',
            'console' => 'Geste hors back-office, sur le serveur',
        ],

        'subject' => [
            'filtered' => 'Historique de : :subject',
            'numbered' => ':type n° :id',
            'labelled' => ':type n° :id — :label',
            'unknown' => 'Aucun sujet de ce type ne porte ce numéro : le journal filtré est vide ou ne vise qu’un sujet disparu.',
            'clear' => 'Voir tout le journal',
            'with_frames' => 'Les gestes sur ses images sont compris.',
        ],

        'list' => [
            'heading' => 'Lignes du journal',
        ],

        'column' => [
            'created_at' => 'Date',
            'actor' => 'Auteur',
            'action' => 'Action',
            'subject' => 'Sujet',
            'details' => 'Détails',
        ],

        'line' => [
            'reason' => 'Motif',
            'roles' => 'Rôle',
            'reports' => 'Signalements',
            'retention' => 'Conservation',
            'no_details' => 'Aucun complément',
        ],

        'retention' => [
            'permanent' => 'Permanente',
            'rolling_12m' => '12 mois glissants',
        ],

        'details' => [
            'locale' => 'Langue',
            'before' => 'Avant',
            'before_origin' => 'Origine de l’ancien titre',
            'after' => 'Après',
            'title' => 'Titre',
            'alias_id' => 'Alias n°',
            'alias' => 'Alias',
            'origin' => 'Origine',
            'outcome' => 'Issue',
            'group_id' => 'Groupe n°',
            'label' => 'Libellé du groupe',
            'group_label' => 'Libellé du groupe',
            'with_movie_id' => 'Avec le film n°',
            'dissolved' => 'Groupe dissous',
            'source_kind' => 'Voie',
            'frame_level' => 'Niveau',
            'failure' => 'Échec effacé',
            'from' => 'De',
            'to' => 'Vers',
            'review_id' => 'Revue n°',
            'decision' => 'Décision',
            'grid_version' => 'Version de la grille',
            'frame_ids' => 'Images n°',
            'pages' => 'Pages TMDB',
            'tmdb_ids' => 'Identifiants TMDB',
            'key' => 'Clé',
            'kind' => 'Nature',
            'rule_value' => 'Règle',
            'rule_negated' => 'Règle niée',
            'labels' => 'Libellés',
            'sort_order' => 'Ordre',
            'works' => 'Œuvres',
            'theme_key' => 'Thème',
            'theme_ids' => 'Thèmes appliqués (identifiants)',
        ],

        'empty' => [
            'heading' => 'Aucune ligne',
            'filtered' => 'Aucune ligne ne correspond à ces filtres. Effacez-les pour revoir tout le journal.',
            'no_lines' => 'Le journal est vide : aucun geste n’a encore été consigné.',
        ],

        'error' => [
            'heading' => 'Filtres refusés',
            'description' => 'Le journal affiché n’est PAS filtré : corrigez les filtres signalés, puis filtrez de nouveau.',
        ],
    ],

    /*
    | Audience (spec 20 § 12.4, D48 du 01/10) : compteurs quotidiens sans
    | cookie, administrateur seul.
    */
    // L'écran « Avatars » (ligne 45, spec 20 § 12.5, D49 du 01/10).
    'avatars' => [
        'title' => 'Avatars téléversés',
        'description' => 'Images téléversées par les comptes. Deux signalements de sièges distincts masquent une image ; vous seul pouvez lever ou retirer.',
        'counts' => ':uploaded image(s) en ligne, :hidden masquée(s) ou retirée(s)',
        'filter' => [
            'label' => 'Filtre',
            'all' => 'Tous',
            'hidden' => 'Masqués',
            'reported' => 'Signalés',
        ],
        'columns' => [
            'image' => 'Image',
            'account' => 'Compte',
            'state' => 'État',
            'reports' => 'Signalements',
            'last_reported_at' => 'Dernier signalement',
            'actions' => 'Gestes',
        ],
        'state' => [
            'visible' => 'Visible',
            'hidden' => 'Masquée',
            'removed' => 'Retirée',
        ],
        'image_alt' => 'Avatar téléversé de :name',
        'no_image' => 'Aucune image',
        'never' => 'Jamais',
        'empty' => 'Aucun avatar pour ce filtre.',
        'unhide' => 'Lever',
        'unhide_title' => 'Lever la mesure sur l’avatar de :name ?',
        'unhide_body' => 'L’image redevient visible si elle existe encore, le compteur de signalements repart de zéro et le téléversement est rouvert.',
        'remove' => 'Retirer',
        'remove_title' => 'Retirer l’avatar de :name ?',
        'remove_body' => 'Le fichier est supprimé et le téléversement reste bloqué jusqu’à une levée. Le motif est consigné au journal.',
        'reason' => 'Motif',
        'reason_optional' => 'Motif (facultatif)',
        'cancel' => 'Annuler',
        'unhidden' => 'Mesure levée.',
        'removed' => 'Avatar retiré.',
        'errors' => [
            'not_hidden' => 'Cet avatar n’est ni masqué ni retiré.',
            'no_image' => 'Ce compte ne porte aucune image.',
        ],
    ],

    // L'écran « Modération » des pseudos (ligne 35, spec 20 § 11.5 ; règle :
    // spec 40 § 13.3, D66 du 07/10).
    'moderation' => [
        'title' => 'Pseudos masqués',
        'description' => 'Deux signalements de sièges distincts masquent un pseudo pour la vie du siège. Vous seul pouvez lever le masquage ou bannir le pseudo ; un bannissement est définitif.',
        'counts' => ':masked siège(s) masqué(s)',
        'columns' => [
            'nickname' => 'Pseudo',
            'room' => 'Salon',
            'account' => 'Compte',
            'masked_at' => 'Masqué le',
            'reports' => 'Signalements',
            'reporters' => 'Signaleurs',
            'state' => 'État',
            'actions' => 'Gestes',
        ],
        'state' => [
            'masked' => 'Masqué',
            'banned' => 'Banni',
        ],
        'reports_value' => ':current dans la fenêtre courante, :frozen au masquage',
        'reports_frozen_none' => ':current dans la fenêtre courante',
        'reporter' => ':nickname (:count signalement(s) de pseudo)',
        'reporter_erased' => 'Siège n° :id, pseudo effacé (:count signalement(s) de pseudo)',
        'no_reporters' => 'Aucun signalement conservé',
        'erased' => 'Pseudo effacé',
        'solo' => 'Solo',
        'guest' => 'Invité',
        'seat_link' => 'Fiche du siège',
        'empty' => 'Aucun pseudo masqué.',
        'blocklist' => [
            'title' => 'Formes à ajouter à la liste noire',
            'description' => 'Pseudos bannis encore présents en base. Recopiez chaque forme dans resources/moderation/nicknames/banned.txt, au prochain commit, puis déployez : la liste noire est une ressource versionnée, relue par un humain.',
            'form' => 'Forme repliée',
            'nickname' => 'Pseudo',
            'empty' => 'Aucune forme en attente.',
        ],
        'unmask' => 'Lever',
        'unmask_title' => 'Lever le masquage de « :nickname » ?',
        'unmask_body' => 'Le pseudo redevient visible des autres joueurs et le compteur de signalements repart de zéro.',
        'ban' => 'Bannir',
        'ban_title' => 'Bannir le pseudo « :nickname » ?',
        'ban_body' => 'Le pseudo reste masqué pour la vie du siège, sans levée possible. Sa forme est à recopier dans la liste noire au prochain déploiement. Le motif est consigné au journal.',
        'reason' => 'Motif',
        'reason_optional' => 'Motif (facultatif)',
        'cancel' => 'Annuler',
        'unmasked' => 'Masquage levé.',
        'banned' => 'Pseudo banni.',
        'errors' => [
            'not_masked' => 'Ce pseudo n’est pas masqué.',
            'banned' => 'Ce pseudo est banni : le masquage ne se lève plus.',
            'already_banned' => 'Ce pseudo est déjà banni.',
        ],
    ],

    'audience' => [
        'title' => 'Audience',
        'heading' => 'Audience',
        'description' => 'Visiteurs mesurés sans cookie ni outil tiers : compteurs par jour, temps réel, entonnoir de jeu.',
        'disabled' => 'La mesure d’audience est coupée sur ce serveur : rien de nouveau n’est compté.',
        'empty' => 'Aucune donnée sur cette fenêtre.',
        'window' => [
            'label' => 'Fenêtre',
            '7d' => '7 jours',
            '30d' => '30 jours',
            '90d' => '90 jours',
        ],
        'live' => [
            'heading' => 'En ce moment',
            'description' => 'Visiteurs des 5 dernières minutes et leur dernière page ; salons ouverts et parties en cours.',
            'visitors' => 'Visiteurs présents',
            'open_rooms' => 'Salons ouverts',
            'running_games' => 'Parties en salon',
            'running_solo' => 'Parties solo',
        ],
        'totals' => [
            'visitors' => 'Visiteurs',
            'visits' => 'Visites',
            'pageviews' => 'Pages vues',
            'per_visit' => 'Pages par visite',
            'note' => 'Visiteurs = somme des visiteurs de chaque jour : sans identifiant durable, un visiteur revenu deux jours compte deux fois.',
        ],
        'funnel' => [
            'heading' => 'Entonnoir de jeu',
            'description' => 'Sur la fenêtre, depuis les tables du jeu : du visiteur à la partie terminée.',
            'visitors' => 'Visiteurs',
            'rooms_created' => 'Salons créés',
            'games_multiplayer' => 'Parties lancées en salon',
            'games_solo' => 'Parties solo lancées',
            'games_completed' => 'Parties terminées',
            'players_per_game' => 'Joueurs par partie',
        ],
        'daily' => [
            'heading' => 'Jour par jour',
        ],
        'pages' => ['heading' => 'Pages les plus vues'],
        'entries' => ['heading' => 'Pages d’entrée'],
        'exits' => ['heading' => 'Pages de sortie'],
        'referrers' => ['heading' => 'Provenance'],
        'locales' => ['heading' => 'Langues'],
        'devices' => ['heading' => 'Appareils'],
        'device' => [
            'mobile' => 'Mobile',
            'tablet' => 'Tablette',
            'desktop' => 'Ordinateur',
        ],
        'column' => [
            'day' => 'Jour',
            'visitors' => 'Visiteurs',
            'visits' => 'Visites',
            'pageviews' => 'Pages vues',
            'page' => 'Page',
            'referrer' => 'Site d’origine',
            'locale' => 'Langue',
            'device' => 'Appareil',
        ],
    ],

    /*
    | Performances (spec 20 § 12.3, D47 du 01/10) : administrateur seul,
    | aucune donnée personnelle.
    */
    'performance' => [
        'title' => 'Performances',
        'heading' => 'Performances',
        'description' => 'Durée des requêtes et des jobs, requêtes SQL lentes, retards du moteur de partie.',
        'disabled' => 'La mesure est coupée sur ce serveur : rien de nouveau n’est enregistré.',
        'settings' => 'Seuil de requête lente : :ms ms · échantillonnage : :rate',
        'window' => [
            'label' => 'Fenêtre',
            '1h' => 'Dernière heure',
            '24h' => 'Dernières 24 h',
            '7d' => '7 derniers jours',
        ],
        'totals' => [
            'requests' => 'Requêtes',
            'request_errors' => 'Requêtes en erreur (5xx)',
            'jobs' => 'Jobs',
            'job_failures' => 'Jobs en échec',
            'traced_games' => 'Parties tracées',
        ],
        'requests' => [
            'heading' => 'Requêtes HTTP, par route',
            'description' => 'Triées par p95. Le nom de la route, jamais l’URL.',
        ],
        'jobs' => [
            'heading' => 'Jobs, par classe',
            'description' => 'L’attente compte depuis la mise en file, délai d’un job différé compris.',
        ],
        'engine' => [
            'heading' => 'Moteur de partie',
            'description' => 'Retard = instant réel − instant théorique de la frontière. Durée et requêtes : jobs de frontière et soumissions.',
        ],
        'slow_queries' => [
            'heading' => 'Requêtes SQL lentes',
            'description' => 'Regroupées par empreinte, valeurs liées jamais enregistrées.',
        ],
        'column' => [
            'name' => 'Nom',
            'event' => 'Événement',
            'count' => 'Nb',
            'p50' => 'p50',
            'p95' => 'p95',
            'max' => 'max',
            'queries' => 'SQL moy.',
            'query_ms' => 'Temps SQL moy.',
            'memory' => 'Mémoire max',
            'errors' => 'Erreurs',
            'wait' => 'Attente p95',
            'delay_p50' => 'Retard p50',
            'delay_p95' => 'Retard p95',
            'delay_max' => 'Retard max',
            'duration_p95' => 'Durée p95',
            'avg' => 'moy.',
            'context' => 'Contexte',
            'sql' => 'SQL',
            'last' => 'Dernière',
        ],
        'ms' => ':value ms',
        'kb' => ':value Ko',
        'empty' => 'Aucune mesure sur cette fenêtre.',
    ],

    /*
    | Inspection des parties, des sièges et des réponses (spec 20 § 12.2,
    | D46 du 01/10) : administrateur seul, chaque consultation consignée.
    */
    'inspection' => [
        'read_notice' => 'Chaque consultation de cet écran est consignée au journal.',
        'erased' => 'Pseudo effacé',
        'masked' => 'Pseudo masqué',
        'solo' => 'Solo',
        'open' => 'Ouvrir',
        'hidden_round' => 'Manche non révélée : son contenu reste caché tant que la partie est en cours.',
        'enum' => [
            'game_status' => [
                'running' => 'En cours',
                'paused' => 'En pause',
                'completed' => 'Terminée',
                'interrupted' => 'Interrompue',
            ],
            'game_mode' => [
                'multiplayer' => 'Salon',
                'solo' => 'Solo',
            ],
            'input_difficulty' => [
                'easy' => 'Facile',
                'normal' => 'Normal',
                'expert' => 'Expert',
            ],
            'round_status' => [
                'pending' => 'À venir',
                'running' => 'En cours',
                'revealing' => 'Révélation',
                'completed' => 'Terminée',
                'cancelled' => 'Annulée',
            ],
            'input_state' => [
                'open' => 'Saisie ouverte',
                'text_exhausted' => 'Texte épuisé, QCM attendu',
                'locked' => 'A trouvé',
                'qcm_wrong' => 'QCM faux',
                'attempts_exhausted' => 'Tentatives épuisées',
                'revealed' => 'Réponse demandée (solo)',
                'skipped' => 'Manche passée (solo)',
            ],
            'seat_status' => [
                'playing' => 'Joue',
                'left' => 'Parti',
                'kicked' => 'Expulsé',
            ],
            'connection_state' => [
                'connected' => 'Connecté',
                'disconnected' => 'Déconnecté',
                'left' => 'Parti',
            ],
            'source' => [
                'text' => 'Texte',
                'choice' => 'QCM',
            ],
            'match_kind' => [
                'title' => 'Titre',
                'alias' => 'Alias',
                'prefix' => 'Préfixe',
                'subtitle' => 'Sous-titre',
                'choice' => 'Proposition',
            ],
            'incident' => [
                'frame_unavailable' => 'Image indisponible',
                'no_variant_available' => 'Aucune variante disponible',
                'movie_withdrawn' => 'Film retiré',
                'choices_unavailable' => 'Propositions indisponibles',
            ],
        ],
        'games' => [
            'title' => 'Parties',
            'heading' => 'Parties',
            'description' => 'Les parties en cours et l’historique, en salon comme en solo.',
            'counts' => [
                'running' => 'En cours',
                'ended' => 'Terminées',
            ],
            'tabs' => [
                'running' => 'En cours',
                'ended' => 'Historique',
            ],
            'filters' => [
                'heading' => 'Filtres',
                'mode' => 'Mode',
                'room' => 'Code de salon',
                'room_placeholder' => 'ABC123',
                'submit' => 'Filtrer',
                'reset' => 'Réinitialiser',
            ],
            'list' => [
                'heading' => 'Liste',
                'results' => ':total parties',
            ],
            'empty' => [
                'heading' => 'Aucune partie',
                'running' => 'Aucune partie n’est en cours.',
                'ended' => 'Aucune partie terminée.',
                'filtered' => 'Aucune partie ne correspond à ces filtres.',
            ],
            'column' => [
                'game' => 'Partie',
                'mode' => 'Mode',
                'room' => 'Salon',
                'status' => 'Statut',
                'difficulty' => 'Difficulté',
                'progress' => 'Manches',
                'participants' => 'Joueurs',
                'started_at' => 'Lancée',
                'ended_at' => 'Terminée',
            ],
            'number' => 'Partie n° :id',
            'progress' => ':done / :total',
        ],
        'game' => [
            'title' => 'Partie n° :id',
            'back' => 'Toutes les parties',
            'summary' => [
                'heading' => 'Résumé',
                'mode' => 'Mode',
                'room' => 'Salon',
                'status' => 'Statut',
                'difficulty' => 'Difficulté',
                'frames_per_round' => 'Images par manche',
                'rounds' => 'Manches jouées',
                'started_at' => 'Lancement',
                'paused_at' => 'En pause depuis',
                'ended_at' => 'Fin',
            ],
            'settings' => [
                'heading' => 'Réglages figés',
                'round_duration' => 'Durée d’une manche',
                'tier_durations' => 'Durée des paliers',
                'tier_points' => 'Barème des paliers',
                'reveal_duration' => 'Révélation',
                'speed_bonus' => 'Bonus de rapidité',
                'attempts_per_round' => 'Tentatives par manche',
                'max_answer_length' => 'Longueur maximale d’une réponse',
                'capacity' => 'Capacité',
                'allow_late_join' => 'Retardataires admis',
                'versions' => 'Versions (réglages, barème, validation)',
                'yes' => 'Oui',
                'no' => 'Non',
                'seconds' => ':value s',
            ],
            'leaderboard' => [
                'heading' => 'Classement',
                'rank' => 'Rang',
                'player' => 'Joueur',
                'status' => 'Statut',
                'score' => 'Score',
                'correct' => 'Bonnes réponses',
                'played' => 'Manches jouées',
                'empty' => 'Aucun participant.',
            ],
            'rounds' => [
                'heading' => 'Manches',
                'round' => 'Manche :number',
                'reserve' => 'Manche de réserve (tirage n° :index)',
                'found' => ':count ont trouvé',
                'cancelled' => 'Annulée : :reason',
                'tiers' => 'Paliers',
                'tier' => 'Palier :index',
                'tier_detail' => 'niveau :level · :points pts · :duration s',
                'substituted' => 'substituée : :reason',
                'participants' => 'Réponses des joueurs',
                'no_participants' => 'Aucun participant à cette manche.',
                'empty' => 'Aucune manche.',
            ],
        ],
        'trace' => [
            'heading' => 'Chronologie technique',
            'description' => 'Chaque transition, diffusion, job de frontière et soumission, avec son retard sur l’instant prévu, sa durée et ses requêtes SQL. Conservée 14 jours.',
            'empty' => 'Aucune chronologie : partie antérieure à la mesure, purgée, ou mesure coupée.',
            'column' => [
                'at' => 'Instant',
                'event' => 'Événement',
                'round' => 'Manche',
                'tier' => 'Palier',
                'player' => 'Joueur',
                'delay' => 'Retard',
                'duration' => 'Durée',
                'queries' => 'SQL',
                'details' => 'Détails',
            ],
            'ms' => ':value ms',
        ],
        'answers' => [
            'wrong_attempts' => ':count tentatives fausses',
            'correct' => 'Bonne réponse',
            'correct_detail' => 'palier :tier · rang :rank · :total pts (:tier_points + :bonus de bonus) · à :seconds s',
            'match' => 'Appariement : :kind, clé « :key », saisie « :submitted », distance :distance',
            'ambiguous' => 'Ambiguë au moment du match',
            'wrong' => 'Réponses fausses',
            'wrong_line' => ':source · palier :tier · à :seconds s',
            'attempt' => 'tentative :number',
            'no_wrong' => 'Aucune réponse fausse.',
            'add_alias' => 'Ajouter en alias',
            'add_alias_label' => 'Ajouter « :answer » en alias du film',
        ],
        'players' => [
            'title' => 'Joueurs',
            'heading' => 'Joueurs',
            'description' => 'Tous les sièges, invités compris. Le pseudo d’un invité est effacé à l’archivage de son salon.',
            'counts' => [
                'total' => 'Sièges',
                'solo' => 'Sièges solo',
            ],
            'filters' => [
                'heading' => 'Filtres',
                'search' => 'Pseudo ou identifiant public',
                'search_placeholder' => 'Pseudo…',
                'mode' => 'Origine',
                'room' => 'Salon',
                'solo' => 'Solo',
                'submit' => 'Filtrer',
                'reset' => 'Réinitialiser',
            ],
            'list' => [
                'heading' => 'Liste',
                'results' => ':total sièges',
            ],
            'empty' => [
                'heading' => 'Aucun joueur',
                'none' => 'Aucun siège n’a encore été créé.',
                'filtered' => 'Aucun siège ne correspond à ces filtres.',
            ],
            'column' => [
                'nickname' => 'Pseudo',
                'origin' => 'Origine',
                'account' => 'Compte',
                'games' => 'Parties',
                'joined_at' => 'Arrivée',
                'last_seen_at' => 'Dernière présence',
            ],
        ],
        'player' => [
            'title' => 'Joueur :name',
            'back' => 'Tous les joueurs',
            'identity' => [
                'heading' => 'Siège',
                'public_id' => 'Identifiant public',
                'origin' => 'Origine',
                'account' => 'Compte lié',
                'locale' => 'Langue',
                'connection' => 'Présence',
                'joined_at' => 'Arrivée',
                'last_seen_at' => 'Dernière présence',
                'left_at' => 'Départ',
                'kicked_at' => 'Expulsion',
                'device' => 'Appareil',
            ],
            // D62 du 06/10 : le visiteur consentant et ses autres sièges.
            'visitor' => [
                'heading' => 'Visiteur',
                'none' => 'Aucun visiteur reconnu : ce joueur n’a pas accepté d’être reconnu, ou son lien a été anonymisé.',
                'since' => 'Consentement du :consented, premier passage le :first.',
                'no_other' => 'Aucun autre siège pour ce visiteur.',
            ],
            'games' => [
                'heading' => 'Parties',
                'empty' => 'Ce siège n’a joué aucune partie.',
                'score' => 'Score :score · rang :rank',
                'open_game' => 'Ouvrir la partie',
            ],
        ],
    ],

    'validation' => [
        'search' => 'recherche',
        'availability' => 'disponibilité',
        'content_flag' => 'drapeau de contenu',
        'import_source' => 'voie d’entrée',
        'exception' => 'entrées par exception',
        'playable_at' => 'jouable à N images',
        'missing_title' => 'titre manquant',
        'curation_status' => 'état de curation',
        'entry' => 'voie d’entrée',
        'motive' => 'motif d’exception',
        'current_movie' => 'film courant',
        'sort' => 'tri',
        'direction' => 'sens du tri',
        'min_votes' => 'seuil de votes',
        'languages' => 'langues originales',
        'min_year' => 'année minimale',
        'pages' => 'pages TMDB',
        'tmdb_query' => 'recherche TMDB',
        // Thèmes choisis au collage (spec 20 § 3.3, D43 du 01/10).
        'import_themes' => [
            'attribute' => 'thèmes du collage',
            'max' => 'Un collage applique au plus :max thèmes : retirez-en de la sélection.',
        ],
        'ids' => [
            'required' => 'Collez au moins un identifiant ou une URL TMDB.',
            'max' => 'Un envoi accepte au plus :max identifiants : scindez la liste en plusieurs envois.',
            'invalid' => 'Aucun identifiant lisible dans ce collage : attendez un nombre nu ou une URL TMDB par ligne.',
        ],
        'real_name' => 'Ce nom est réservé au journal d’administration : saisissez le nom réel de la personne.',
        'real_name_field' => 'nom réel',
        'role' => 'rôle',
        'account_state' => 'état du compte',
        'game_state' => 'état de la partie',
        'game_mode' => 'mode',
        'room_code' => 'code de salon',
        'perf_window' => 'fenêtre',
        'email' => 'adresse',
        'tmdb_file_path' => 'visuel TMDB',
        'capture_source' => 'capture',
        'source_timecode' => 'minutage',
        'frame_level' => 'niveau',
        'crop_rect' => 'cadre',
        'crop_seconds' => 'temps de recadrage',
        'reason' => 'motif',
        'grid_version' => 'version de la grille',
        'reviewed_hash' => 'empreinte de l’image revue',
        'frames' => 'images du lot',
        'answers' => 'réponses de la grille',
        'declared_source_reference' => 'source déclarée',
        'movie_title' => 'titre',
        'alias' => 'alias',
        'alias_locale' => 'langue de l’alias',
        'group_movie' => 'identifiant de l’autre film',
        'group' => 'groupe',
        'group_leave' => 'retrait du groupe',
        'movie_theme' => 'thème',
        'movie_themes' => 'thèmes',
        'movie_theme_state' => 'exception de thème',
        'catalog_theme' => 'thème',
        'group_label' => 'libellé du groupe',
        // Resynchronisation, difficulté, geste rétroactif (D66 du 07/10).
        'resync_movies' => 'films à resynchroniser',
        'movie_difficulty_override' => 'difficulté corrigée',
        'takedown_reference' => 'référence de la demande de retrait',
        'group_note' => 'note',
        // L'écran des thèmes (spec 20 § 9.6, D43 du 01/10).
        'theme_kind' => 'nature du thème',
        'theme_manual' => 'thème sans règle',
        'theme_collection' => 'collection',
        'theme_companies' => 'sociétés',
        'theme_company' => 'société',
        'theme_rule' => 'valeur de la règle',
        'theme_negated' => 'négation de la règle',
        'theme_labels' => 'libellés',
        'theme_label_fr' => 'libellé français',
        'theme_label_en' => 'libellé anglais',
        'theme_sort_order' => 'ordre',
        'theme_published' => 'publication',
        // Filtres du journal d'administration (ligne 41, D41 du 30/09).
        'journal_actor' => 'auteur',
        'journal_action' => 'action',
        'journal_from' => 'début de période',
        'journal_to' => 'fin de période',
        'journal_subject_type' => 'type de sujet',
        'journal_subject_id_field' => 'numéro du sujet',
        'journal_subject_id' => 'Un numéro de sujet exige un type de sujet qui en porte un : film, image, compte, joueur, demande de retrait ou balayage d’import.',
        'journal_movie_field' => 'film',
        'journal_movie' => 'L’historique d’un film remplace le filtre de sujet : retirez le type et le numéro du sujet, ou le film.',
        'frame_source' => [
            'dimensions' => 'Ce visuel est en portrait, fait moins de :width pixels de large, ou ne laisse place à aucun cadre admis : il ne peut pas donner une image de jeu. Choisissez un autre visuel du film.',
            'max' => 'Cette capture dépasse :max Ko, le poids maximal d’un envoi : aucune image n’a été créée. Rouvrez-la depuis l’écran, qui la prépare sous ce poids avant de l’envoyer.',
            'mimetypes' => 'Cette capture n’est pas au format attendu (WebP) : aucune image n’a été créée. Rouvrez-la depuis l’écran, qui la prépare dans ce format avant de l’envoyer ; si l’échec persiste, utilisez un navigateur récent, sur ordinateur.',
            'animated' => 'Cette capture est une image animée : seule une image fixe peut devenir une image de jeu. Aucune image n’a été créée. Rouvrez-la depuis l’écran, qui la prépare en image fixe.',
            'upload_failed' => 'La capture n’a pas pu être reçue par le serveur : aucune image n’a été créée. Réessayez ; si l’échec persiste, signalez-le à l’administrateur du site.',
        ],
        'crop' => [
            'aspect' => 'Le cadre doit être exactement au format 16:9.',
            'too_wide' => 'Le cadre couvre une trop grande part du visuel : resserrez-le. Une image de jeu ne reprend jamais le visuel presque entier.',
            'too_narrow' => 'Le cadre est trop serré : élargissez-le, l’image de jeu serait trop agrandie.',
            'out_of_bounds' => 'Le cadre dépasse du visuel : ramenez-le entièrement à l’intérieur.',
        ],
    ],

    'error' => [
        'tmdb_disabled' => 'Import TMDB désactivé : aucune clé TMDB n’est configurée sur le serveur. L’administrateur du site doit la renseigner.',
        'import_already_running' => 'Un balayage de cette nature est déjà ouvert. Attendez qu’il finisse, ou reprenez-le depuis son détail.',
        'run_not_resumable' => 'Ce balayage n’est pas reprenable : seuls les balayages discover encore en cours le sont.',

        /*
        | Page `admin/error` (C15 § 2.3) : rendue à la place de la page `error`
        | joueur quand le domaine `admin` était sélectionné avant l'exception.
        | Une feuille par statut rendu, jamais un message d'erreur brut
        | (décision 9).
        */
        'http' => [
            403 => [
                'title' => 'Accès refusé',
                'description' => 'Cet écran ou ce geste est réservé à un autre rôle. Si vous pensez devoir y accéder, adressez-vous à l’administrateur.',
            ],
            404 => [
                'title' => 'Page introuvable',
                'description' => 'Cet écran, ce film ou ce balayage n’existe pas, ou plus. Revenez au tableau de bord pour reprendre.',
            ],
            419 => [
                'title' => 'Page expirée',
                'description' => 'Votre session a expiré pendant que cet écran était ouvert. Revenez à la page précédente, rechargez-la, puis refaites votre geste.',
            ],
            429 => [
                'title' => 'Trop de demandes',
                'description' => 'Ce geste a été répété trop souvent en peu de temps. Patientez une minute, puis réessayez.',
            ],
            500 => [
                'title' => 'Erreur du serveur',
                'description' => 'Le serveur n’a pas pu terminer ce geste. L’incident est consigné ; réessayez dans un instant.',
            ],
            503 => [
                'title' => 'Service indisponible',
                'description' => 'Le site est en maintenance ou momentanément indisponible. Réessayez dans quelques minutes.',
            ],
        ],
        'back' => 'Page précédente',
        'dashboard' => 'Retour au tableau de bord',
    ],

    /*
    | `php artisan admin:first-admin` — la porte d’entrée du tout premier
    | administrateur, les suivants étant nommés par l’écran de gestion des
    | accès (spec 20 § 2.8), et la voie de secours de correction du nom réel.
    | Locale forcée en français par la commande elle-même.
    */
    'console' => [
        // `perf:report` (spec 100 § 10.11, D47 du 01/10).
        'perf' => [
            'summary' => 'Fenêtre : :hours h — :requests requêtes (:request_errors en erreur), :jobs jobs (:job_failures en échec), :games parties tracées.',
            'requests' => 'Requêtes HTTP, par p95',
            'jobs' => 'Jobs, par p95',
            'engine' => 'Moteur de partie : retards (réel − théorique) et durées',
            'slow_queries' => 'Requêtes SQL lentes',
            'column' => [
                'route' => 'Route',
                'job' => 'Job',
                'event' => 'Événement',
                'count' => 'Nb',
                'p50' => 'p50 ms',
                'p95' => 'p95 ms',
                'max' => 'max ms',
                'queries' => 'SQL moy.',
                'errors' => 'Erreurs',
                'delay_p50' => 'retard p50',
                'delay_p95' => 'retard p95',
                'delay_max' => 'retard max',
                'duration_p95' => 'durée p95',
                'avg' => 'moy. ms',
                'context' => 'Contexte',
                'sql' => 'SQL',
            ],
        ],
        /*
        | Commandes d'import lancées à la main (spec 20 § 3.3, spec 100 § 11.4,
        | règle 12). La garde d'instantané précède toute écriture.
        */
        'import' => [
            'snapshot_failed' => 'Instantané refusé : l’import s’arrête sans rien écrire. Corrigez la cause signalée par backup:snapshot, puis relancez la commande.',
            'simulation_resume' => 'Une simulation ne reprend aucun balayage : --dry-run et --preview ne se combinent pas avec --resume.',
            'preview_invalid' => 'Aperçu refusé : --preview attend un jeton de 32 caractères hexadécimaux et --actor l’identifiant numérique de son auteur.',
            'preview_exclusive' => 'Aperçu refusé : --preview ne se combine ni avec --resync ni avec --resume.',
        ],

        'first_admin' => [
            'ask_email' => 'Adresse e-mail du compte à promouvoir',
            'invalid_email' => 'Adresse e-mail invalide.',
            'anonymized' => 'Le compte :email est anonymisé : une pierre tombale ne se promeut pas.',
            'unchanged' => 'Le compte :email est déjà administrateur : rien à faire.',
            'already' => 'Un administrateur existe déjà (:name). Employez --force pour en promouvoir un second.',
            'forced' => 'Un administrateur existe déjà (:name) : promotion forcée.',
            'not_found' => 'Aucun compte pour :email, et la session n’est pas interactive : rien n’a été créé.',
            'create_disabled' => 'Aucun compte pour :email. Cette commande promeut un compte existant ; employez --create pour le créer, ou inscrivez-vous d’abord normalement.',
            'confirm_create' => 'Aucun compte pour :email. Le créer maintenant ?',
            'aborted' => 'Abandon : rien n’a été modifié.',
            'ask_name' => 'Nom affiché',
            'invalid_name' => 'Nom invalide.',
            'ask_password' => 'Mot de passe',
            'ask_password_confirmation' => 'Confirmation du mot de passe',
            'invalid_password' => 'Mot de passe invalide.',
            'created' => 'Compte :email créé.',
            'promoted' => ':name (:email) est désormais administrateur.',
            'real_name_prompt' => 'Nom réel (il signe les revues et le journal d’administration)',
            'real_name_required' => 'Nom réel requis : relancez avec --real-name="Prénom Nom". Rien n’a été modifié.',
            'invalid_real_name' => 'Nom réel invalide.',
            'real_name_updated' => 'Nom réel de :email corrigé. Les revues et les lignes de journal déjà signées gardent l’ancien nom.',
        ],

        /*
        | Instantané de la règle 12 et son élagage (spec 100 § 13.1). Lus par
        | le porteur dans la sortie du hook de déploiement ou d'un geste de
        | console : un échec dit toujours ce qui n'a PAS été fait ensuite.
        */
        'backup' => [
            'snapshot_skipped' => 'Aucune migration en attente : aucun instantané n’est nécessaire.',
            'snapshot_written' => 'Instantané écrit et vérifié : :file',
            'snapshot_failed' => 'Instantané impossible ou invérifiable : aucun fichier n’a été conservé. Ne lancez ni la migration ni le geste prévu tant que cette commande n’a pas réussi.',
            'snapshot_unsafe_dir' => 'Répertoire d’instantanés refusé : BACKUP_SNAPSHOT_DIR doit être un chemin absolu situé hors du répertoire de déploiement. Aucun instantané n’a été pris.',
            'snapshot_driver' => 'Instantané impossible : la connexion par défaut emploie le pilote :driver, et seul un vidage MySQL est pris en charge. Aucun instantané n’a été pris.',
            'pruned' => 'Élagage des instantanés terminé. Instantanés supprimés : :count.',
            // Tier froid et restauration jouée (spec 100 § 13.3 et § 13.5).
            'manifest_unreadable' => 'Manifeste incomplet : :count fichier(s) du disque frames illisible(s). Les autres fichiers sont listés ; la sauvegarde est en échec.',
            'manifest_missing' => 'Tier froid incomplet : :count fichier(s) du périmètre introuvable(s) sur le disque frames. Les fichiers présents sont listés ; lancez backup:verify pour les dérivés publiés.',
            'manifest_altered' => 'Tier froid incomplet : :count dérivé(s) publié(s) dont le condensat diffère de published_hash, non listé(s). Lancez backup:verify.',
            'verify_ok' => 'Vérification réussie : les :count frame(s) publiée(s) ont leur fichier de jeu présent, au condensat publié.',
            'verify_failed' => 'Vérification en échec : :count fichier(s) de jeu manquant(s) ou altéré(s) sur :checked frame(s) publiée(s) (manquants : :missing ; altérés : :altered). Ne rouvrez pas le trafic.',
        ],

        /*
        | Reprojection du catalogue (spec 10 § 3.2, règle 3), jouée à chaque
        | déploiement et après toute restauration.
        */
        'reproject' => [
            'done' => 'Reprojection terminée. Films reprojetés par différence : :movies.',
        ],

        /*
        | Rattrapage des appartenances aux thèmes (spec 30 § 13.2, règle 12) :
        | l'instantané précède toute écriture.
        */
        'themes' => [
            'snapshot_failed' => 'Instantané refusé : aucune difficulté ni aucune appartenance n’a été réévaluée. Corrigez la cause signalée par backup:snapshot, puis relancez catalog:themes.',
            'derived' => 'Difficulté dérivée : :changed films changés.',
            'theme' => 'lignes changées : :changed',
            'done' => 'Réévaluation terminée. Thèmes : :themes ; lignes d’appartenance changées : :changed.',
        ],

        /*
        | Rattrapage des noms de sociétés TMDB (spec 10 § 3.6 bis). N'écrit que
        | `tmdb_company`, hors règle 12.
        */
        'company_names' => [
            'invalid_limit' => 'Option refusée : --limit attend un entier strictement positif. Rien n’a été appelé ni écrit.',
            'dry_run' => 'Simulation : :count société(s) sans nom seraient demandées à TMDB. Rien n’a été appelé ni écrit.',
            'not_configured' => 'Aucune clé TMDB configurée : posez TMDB_API_READ_ACCESS_TOKEN ou TMDB_API_KEY dans .env. Rien n’a été appelé ni écrit.',
            'failed' => 'TMDB a refusé un appel : :reason. Sociétés déjà nommées et conservées : :named. Relancez la commande pour reprendre.',
            'done' => 'Noms de sociétés rattrapés. Nommées : :named ; inconnues de TMDB : :unknown.',
        ],

        /*
        | Rattrapage du titre de la langue originale (spec 10 § 3.4, D52 du
        | 02/10). Écrit `movie_title`, table de la règle 12 : l'instantané
        | précède toute écriture.
        */
        'original_titles' => [
            'dry_run' => 'Simulation : :count film(s) recevraient le titre de leur langue originale. Rien n’a été écrit.',
            'snapshot_failed' => 'Instantané refusé : aucun titre n’a été écrit. Corrigez la cause signalée par backup:snapshot, puis relancez catalog:original-titles.',
            'done' => 'Rattrapage terminé. Titres de langue originale écrits et films reprojetés : :count.',
        ],

        /*
        | Purge de rétention (spec 100 § 14). `:scopes` de `failed` liste les
        | identifiants des périmètres tels que `purge_run` les écrit.
        */
        'purge' => [
            'done' => 'Purge de rétention exécutée. Périmètres : :scopes. Lignes traitées : :rows.',
            'queued' => 'Purge de rétention déposée sur la file default : un worker l’exécutera.',
            'already_queued' => 'Une purge de rétention tient déjà le verrou d’unicité du job (en file, en cours, ou sur un worker arrêté avant la fin de ce verrou) : aucune nouvelle purge déposée.',
            'failed' => 'Périmètres en échec ou avec des lignes en échec : :scopes. Détail dans purge_run et le journal de l’application ; ne rouvrez pas le trafic après une restauration tant que cette commande n’a pas réussi.',
            'suspended' => 'Purge de rétention suspendue : aucun périmètre ne s’exécute jusqu’à purge:resume, et la sonde purge reste en alerte.',
            'resumed' => 'Suspension levée : la purge repart à sa prochaine exécution.',
            'already_suspended' => 'La purge de rétention est déjà suspendue : rien n’a été modifié.',
            'not_suspended' => 'La purge de rétention n’est pas suspendue : rien n’a été modifié.',
        ],

        /*
        | Nettoyage des données synthétiques du test de charge (spec 100
        | § 16.5), joué avant l'archivage des salons. Des nombres seulement :
        | ni pseudo ni code de salon ; `:file` est le chemin passé à la
        | commande.
        */
        'loadtest' => [
            'deleted' => 'Salons synthétiques oubliés (faits de partie, sièges et salon) : :rooms.',
            'dry_run' => 'Simulation : salons synthétiques qui seraient oubliés : :rooms. Rien n’a été supprimé.',
            'skipped_real_seat' => 'Salons écartés parce qu’au moins un de leurs sièges ne porte pas de pseudo synthétique : :count. Rien n’y a été supprimé.',
            'skipped_in_progress' => 'Salons écartés parce qu’une partie y est encore en cours : :count. Relancez la commande une fois ces parties terminées.',
            'not_found' => 'Codes sans salon actif : :count (code inconnu, ou salon déjà archivé : ses pseudos sont effacés, il n’est plus reconnaissable comme synthétique).',
            'missing_file' => 'Liste des codes illisible : :file. Rien n’a été supprimé.',
            'failed' => 'Salons dont l’oubli a échoué : :count. Chacun est resté intact ; le détail est dans le journal de l’application.',
        ],

        /*
        | Drainage de déploiement (spec 100 § 11.3, contrat C18-bis). Lu par le
        | porteur en SSH et dans la sortie du hook. `:until` est un instant
        | ISO-8601 UTC ; `:phase` est rendue par `phase.*`, jamais par la
        | valeur brute de l'enum ; `:option` nomme l'option ou la variable
        | d'environnement fautive, un identifiant et non un mot.
        */
        'deploy' => [
            'started' => 'Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours.',
            'waiting' => 'Parties encore en cours : :count. Relevé suivant dans quelques secondes.',
            'window_open' => 'Fenêtre libre ouverte jusqu’à :until : aucune partie en cours. Vérifiez deploy:guard, puis cliquez « Déployer » dans Plesk.',
            'abandoned' => 'Drainage abandonné : l’échéance est passée sans fenêtre libre, ou le drapeau a été levé ailleurs. Aucune fenêtre libre n’est ouverte : ne déployez pas, et relancez deploy:drain le moment venu.',
            'already_running' => 'Un drainage existe déjà (phase : :phase, échéance :until) : rien n’a été modifié. Attendez son issue, ou levez-le par deploy:release.',
            'guard_ok' => 'Garde franchie : fenêtre libre ouverte et aucune partie en cours.',
            'guard_games_in_progress' => 'Garde refusée : parties en cours : :count. Rien ne doit être migré ni redémarré ; relancez deploy:drain jusqu’à la fenêtre libre.',
            'guard_no_window' => 'Garde refusée : aucune fenêtre libre ouverte. Lancez deploy:drain jusqu’à la fenêtre libre, puis relancez le déploiement.',
            'released' => 'Drapeau de drainage levé : les lancements sont de nouveau permis.',
            'nothing_to_release' => 'Aucun drapeau de drainage : rien à lever.',
            'invalid_minutes' => 'Durée refusée pour :option : un nombre entier de minutes, au moins 1, est attendu. Rien n’a été modifié.',

            'phase' => [
                'draining' => 'drainage',
                'window' => 'fenêtre libre',
            ],
        ],
    ],

    /*
    | Pannes d'un appel TMDB — clés produites par `TmdbErrorKind`. Seules les
    | substitutions toujours fournies sont employées : `:status` pour
    | `unauthorized`, `server_error` et `unexpected_status`. `retry_after` est
    | facultatif côté TMDB, donc jamais écrit ici — il resterait littéral.
    */
    'tmdb' => [
        'error' => [
            'not_configured' => 'Import TMDB désactivé : aucune clé TMDB n’est configurée sur le serveur. Le jeu, lui, n’appelle jamais TMDB.',
            'unauthorized' => 'TMDB a refusé l’authentification (statut :status) : jeton v4 ou clé v3 invalide, révoquée, ou dépourvue du droit demandé.',
            'not_found' => 'TMDB ne connaît pas la ressource demandée : vérifiez l’identifiant avant de relancer.',
            'rate_limited' => 'Quota TMDB atteint : le balayage est suspendu et reprenable depuis le bouton « Reprendre » de son détail s’il s’agit d’un balayage discover ; un collage, lui, se relance en recollant sa liste.',
            'server_error' => 'TMDB est en panne (statut :status), tentatives épuisées : le balayage est suspendu et reprenable depuis le bouton « Reprendre » de son détail s’il s’agit d’un balayage discover ; un collage, lui, se relance en recollant sa liste.',
            'transport' => 'Appel TMDB interrompu (DNS, TLS, délai d’attente ou connexion coupée) : le balayage est suspendu et reprenable depuis le bouton « Reprendre » de son détail s’il s’agit d’un balayage discover ; un collage, lui, se relance en recollant sa liste.',
            'malformed' => 'Réponse TMDB hors contrat : rien n’a été écrit au catalogue, le champ fautif est nommé dans le journal applicatif.',
            'unexpected_status' => 'Statut TMDB inattendu (:status) : aucune reprise automatique, consultez le journal applicatif.',
            'too_large' => 'Le fichier renvoyé par TMDB dépasse le poids maximal accepté : il n’a pas été téléchargé.',
            'rate_limited_interactive' => 'Quota TMDB atteint : patientez quelques secondes, puis réessayez.',
        ],
    ],

];
