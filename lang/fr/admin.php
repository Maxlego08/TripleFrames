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
        'read_only_notice' => 'Fiche en lecture seule. L’éditeur de la banque d’images, le recadreur et le workflow de publication appartiennent à la spec 20.',

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
            'editor_pending' => 'L’éditeur de banque d’images, le recadreur et le classement sur l’échelle 1-5 appartiennent à la spec 20, qui n’est pas encore écrite.',
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
