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
        'catalog' => 'Catalogue',
        'import' => 'Import',
        'review' => 'Revue',
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
        'open_run' => 'Ouvrir le balayage n° :id',
        'run_status' => 'État du balayage : :status',
        'pagination' => 'Pagination',
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
        /*
        | Journal `admin_action` : une feuille par cas de la liste fermée
        | (`AdminActionType::labelKey()`, points remplacés par `_`) et par
        | sujet (`AdminActionSubject::labelKey()`).
        */
        'admin_action' => [
            'role_changed' => 'Changement de rôle',
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
            'avatar_hidden' => 'Photo de profil masquée',
            'avatar_unhidden' => 'Photo de profil rétablie',
            'nickname_masked' => 'Pseudo masqué',
            'nickname_unmasked' => 'Pseudo rétabli',
            'nickname_banned' => 'Pseudo banni',
            'takedown_decided' => 'Demande de retrait décidée',
            'site_closed' => 'Site fermé',
            'site_reopened' => 'Site rouvert',
        ],
        'admin_action_subject' => [
            'movie' => 'Film',
            'frame' => 'Image',
            'user' => 'Compte',
            'player' => 'Joueur',
            'takedown_request' => 'Demande de retrait',
            'site' => 'Site',
        ],
    ],

    /*
    | Tableau de bord — supervision, aucune écriture. Tout ce qui touche aux
    | images vaut zéro aujourd’hui : aucune ligne `frame` n’existe encore, et
    | c’est la vérité à afficher plutôt qu’un chiffre inventé.
    */
    'dashboard' => [
        'title' => 'Tableau de bord',
        'heading' => 'Tableau de bord de curation',
        'description' => 'L’état du catalogue, du vivier et des derniers balayages. Cet écran ne modifie rien.',

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
            'heading' => 'Vivier par nombre d’images',
            'description' => 'Films publiés, contenu vérifié, couvrant assez de niveaux pour une manche de N images.',
            'frames_per_round' => 'N = :count',
            'movies' => 'films',
            'empty' => 'Aucun film ne rentre encore dans le vivier.',
            'scope_notice' => 'Vivier CATALOGUE : ni thème, ni non-répétition. C’est un plafond, jamais le vivier d’un salon — un lobby affichera toujours un nombre inférieur ou égal.',
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
            'heading' => 'Films non curés',
            'description' => 'Les dix plus anciens brouillons, du plus ancien au plus récent. File sans priorité ni réservation : la spec 20 tranchera.',
            'empty' => 'Aucun brouillon en attente de curation.',
            'see_all' => 'Voir tous les brouillons',
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

    'catalog' => [
        'title' => 'Catalogue',
        'heading' => 'Catalogue des films',
        'description' => 'Liste en lecture seule. Publier, dépublier ou corriger appartient à la spec 20.',
        'results' => ':total film(s) au filtre courant',

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
        'read_only_notice' => 'Fiche en lecture seule. Les images du film se curent dans l’éditeur de la banque d’images.',
        'curate' => 'Curer les images',

        'tabs' => [
            'identity' => 'Identité',
            'titles' => 'Titres et alias',
            'tags' => 'Étiquettes TMDB',
            'projection' => 'Projection',
            'themes' => 'Thèmes',
            'frames' => 'Banque d’images',
            'import' => 'Import',
        ],

        'identity' => [
            'heading' => 'Identité',
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

        'titles' => [
            'heading' => 'Titres affichables',
            'description' => 'Un titre sert à AFFICHER le film dans la langue d’un joueur. Il ne sert jamais à valider une réponse.',
            'column' => [
                'locale' => 'Locale de catalogue',
                'title' => 'Titre',
                'origin' => 'Origine',
                'edited_by' => 'Corrigé par',
            ],
            'empty' => 'Aucun titre localisé.',
        ],

        'aliases' => [
            'heading' => 'Alias acceptés',
            'description' => 'Un alias sert à VALIDER une réponse, jamais à afficher le film. Tous les alias de toutes les langues activées sont acceptés, quel que soit le joueur.',
            'column' => [
                'locale' => 'Locale',
                'alias' => 'Alias',
                'origin' => 'Origine',
            ],
            'empty' => 'Aucun alias.',
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
            'description' => 'Le schéma ne stocke AUCUN libellé : genres et sociétés sont des identifiants TMDB bruts, et c’est délibéré.',
            'genres' => 'Genres',
            'companies' => 'Sociétés',
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
            'column' => [
                'theme' => 'Thème',
                'auto' => 'Règle automatique',
                'manual' => 'Exception manuelle',
                'active' => 'Appartenance effective',
            ],
            'empty' => 'Ce film n’appartient à aucun thème.',
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
        | `duplicate` : même visuel ET même cadre qu’une image déjà dans la
        | banque du film — une même source recadrée autrement reste permise.
        */
        'tmdb' => [
            'not_a_backdrop' => 'Ce visuel ne fait pas partie des visuels de ce film proposés par TMDB : choisissez-en un dans la grille. Une affiche ou un logo ne devient jamais une image de jeu.',
            'download_failed' => 'TMDB n’a pas pu fournir ce visuel (service indisponible ou connexion interrompue) : aucune image n’a été créée. Réessayez dans un instant ; si l’échec persiste, signalez-le à l’administrateur du site.',
            'too_large' => 'Ce visuel est plus lourd que ce que le serveur accepte de télécharger : aucune image n’a été créée. Choisissez un autre visuel du film.',
            'duplicate' => 'Cette image existe déjà dans la banque du film : même visuel, même cadre. Déplacez ou redimensionnez le cadre pour créer une autre variante.',
        ],

        /*
        | Voie capture (spec 20 § 5.4), fermée au jalon 1 faute d’arbitrage sur
        | sa licéité. `disabled` motive le refus du serveur ; `disabled_notice`
        | explique l’attente à la place du bouton absent.
        */
        'capture' => [
            'disabled' => 'L’ajout d’une capture personnelle est fermé : seuls les visuels TMDB du film peuvent devenir des images de jeu.',
            'disabled_notice' => 'L’ajout d’une capture personnelle est désactivé dans l’attente d’un avis juridique sur sa licéité. En attendant, seuls les visuels TMDB du film peuvent devenir des images de jeu ; un film sans visuel exploitable s’écarte.',
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
    | `instructions` décrit l’opérabilité au clavier, toujours affichée sous
    | le cadre : l’alternative non gestuelle du principe 8 ne se cache pas ;
    | `:steps` y est le nombre de pas d’un geste fait avec Maj.
    | Les refus du plancher sont ceux de `validation.crop.*`, communs au
    | recadreur et au serveur.
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
    | Éditeur de la banque d'images (spec 20 § 6) : les visuels TMDB du film,
    | le recadreur et le niveau, la banque du film groupée par niveau avec sa
    | couverture, et la prévisualisation en conditions de jeu. Chaque geste
    | qui fait sortir une image du jeu est confirmé, et l'avertissement de
    | couverture (`frame.unpublish.*`) s'affiche AVANT l'envoi.
    |
    | `backdrop_alt` : `:index` et `:count` situent le visuel dans la grille ;
    | `backdrop_used` : `:levels`, les niveaux des images qui en proviennent ;
    | `list.image` et `list.actions` : `:index` est le rang de l'image dans
    | son niveau. `preview.*` : `:count` est un nombre d'images par manche,
    | `:tier` un rang de palier, `:levels` une liste de niveaux.
    */
    'bank' => [
        'title' => 'Banque d’images',
        'description' => 'Ajoutez des images depuis les visuels TMDB du film, classez-les de 1 à 5, puis suivez leur traitement et la couverture du film. Une image n’entre en jeu qu’après une revue de son rendu final.',
        'back' => 'Retour à la fiche du film',
        'retry' => 'Réessayer',
        'cancel' => 'Annuler',
        'processing_notice' => 'Des images sont en traitement : cet écran se met à jour de lui-même, inutile d’attendre pour continuer.',
        'processing_stalled' => 'Le traitement d’arrière-plan ne répond pas : une image attend son rendu depuis trop longtemps. Vous pouvez continuer à curer ; si l’attente se prolonge, prévenez l’administrateur du site.',
        'refresh_failed' => 'La mise à jour automatique de la banque a échoué.',
        'desktop_required' => 'Le recadreur demande un écran large, celui d’un ordinateur. Le reste de l’écran — la banque, les états, la couverture — reste utilisable ici.',

        'backdrops' => [
            'heading' => 'Visuels TMDB du film',
            'description' => 'Les visuels sans texte viennent d’abord. Une seule tabulation entre dans la grille : les flèches passent d’un visuel à l’autre, Entrée ou Espace ouvre le visuel dans le cadre.',
            'list_label' => 'Visuels TMDB proposés',
            'loading' => 'Chargement des visuels TMDB…',
        ],
        'no_backdrops' => 'Aucun visuel TMDB à proposer pour ce film : TMDB n’en fournit aucun, ou le film n’a pas d’identifiant TMDB (catalogue de démonstration).',
        'backdrops_failed' => 'Les visuels du film n’ont pas pu être obtenus auprès de TMDB (service indisponible ou connexion interrompue). Réessayez dans un instant.',
        'backdrop_alt' => 'Visuel :index sur :count',
        'backdrop_used' => 'Déjà utilisé (niveaux :levels)',
        'backdrop_opened' => 'Ouvert dans le cadre',
        'backdrop_with_language' => 'Peut contenir du texte',

        'cropper' => [
            'heading' => 'Recadrer et classer',
            'description' => 'Le cadre s’ouvre sur le plus grand cadre admis, centré. Choisissez le niveau, puis ajoutez l’image : elle part en traitement et vous passez à un autre visuel sans attendre.',
            'empty' => 'Choisissez un visuel dans la grille pour l’ouvrir dans le cadre.',
            'close' => 'Fermer ce visuel',
            'add' => 'Ajouter à la banque',
            'adding' => 'Ajout en cours…',
            'locked' => 'Ce film est suspendu par un administrateur : aucune image ne peut y être ajoutée.',
            'level_required' => 'Choisissez un niveau avant d’ajouter l’image : aucun n’est coché d’avance.',
        ],

        'list' => [
            'heading' => 'Banque du film',
            'description' => 'Les images du film, groupées par niveau, avec leur état. Une image rejetée en revue peut être re-recadrée ou écartée ; une image écartée ne revient jamais en revue.',
            'empty' => 'Aucune image : ajoutez-en une depuis les visuels TMDB du film.',
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
    ],

    /*
    | Import — les deux voies et leur ASYMÉTRIE, dite avant les formulaires.
    | Un curateur qui ne la comprend pas collera des identifiants sans savoir
    | qu’il marque une exception.
    */
    'import' => [
        'title' => 'Import du catalogue',
        'heading' => 'Import du catalogue',
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
                'hint' => 'Entre :min et :max par envoi. Une page rend une vingtaine de fiches.',
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

            'movies' => [
                'heading' => 'Films entrés par ce balayage',
                'description' => 'La liste exacte de ce que ce balayage a fait entrer au catalogue.',
                'empty' => 'Ce balayage n’a encore fait entrer aucun film.',
            ],
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
    | `frame_source.dimensions`, le refus d’un visuel trop étroit ou en
    | portrait avant tout téléchargement (§ 5.3), `:width` étant la largeur
    | d’une image de jeu.
    */
    'validation' => [
        'search' => 'recherche',
        'availability' => 'disponibilité',
        'content_flag' => 'drapeau de contenu',
        'import_source' => 'voie d’entrée',
        'exception' => 'entrées par exception',
        'playable_at' => 'jouable à N images',
        'sort' => 'tri',
        'direction' => 'sens du tri',
        'min_votes' => 'seuil de votes',
        'languages' => 'langues originales',
        'min_year' => 'année minimale',
        'pages' => 'pages TMDB',
        'ids' => [
            'required' => 'Collez au moins un identifiant ou une URL TMDB.',
            'max' => 'Un envoi accepte au plus :max identifiants : scindez la liste en plusieurs envois.',
            'invalid' => 'Aucun identifiant lisible dans ce collage : attendez un nombre nu ou une URL TMDB par ligne.',
        ],
        'real_name' => 'Ce nom est réservé au journal d’administration : saisissez le nom réel de la personne.',
        'tmdb_file_path' => 'visuel TMDB',
        'frame_level' => 'niveau',
        'crop_rect' => 'cadre',
        'crop_seconds' => 'temps de recadrage',
        'reason' => 'motif',
        'grid_version' => 'version de la grille',
        'reviewed_hash' => 'empreinte de l’image revue',
        'answers' => 'réponses de la grille',
        'declared_source_reference' => 'source déclarée',
        'frame_source' => [
            'dimensions' => 'Ce visuel est en portrait, fait moins de :width pixels de large, ou ne laisse place à aucun cadre admis : il ne peut pas donner une image de jeu. Choisissez un autre visuel du film.',
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
    | `php artisan admin:first-admin` — la seule porte d’entrée du panneau tant
    | que l’écran de gestion des accès de la spec 20 n’existe pas, et la seule
    | voie de correction du nom réel au jalon 1. Locale forcée en français par
    | la commande elle-même.
    */
    'console' => [
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
        ],

        /*
        | Reprojection du catalogue (spec 10 § 3.2, règle 3), jouée à chaque
        | déploiement et après toute restauration.
        */
        'reproject' => [
            'done' => 'Reprojection terminée. Films reprojetés par différence : :movies.',
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
