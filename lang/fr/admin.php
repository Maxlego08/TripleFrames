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
                'duplicate' => 'Ignoré : ce film est déjà au catalogue. Employez --resync pour le remettre à jour.',
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

        'deferred_notice' => 'Les deux voies sont DIFFÉRÉES : l’envoi ouvre un balayage et le confie à un worker, puis vous redirige vers son détail. Aucun appel TMDB n’a lieu pendant la requête.',
        'disabled' => 'Aucune clé TMDB n’est configurée : les deux formulaires sont désactivés. Renseignez TMDB_API_READ_ACCESS_TOKEN ou TMDB_API_KEY.',

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
                'hint' => 'Un identifiant ou une URL TMDB par ligne, :max au maximum par envoi. La console, elle, n’a pas cette borne.',
            ],
            'exception_notice' => 'Tout film entré par cette voie est marqué « entré par exception », même s’il satisfait tout le filtre de notoriété.',
            'submit' => 'Importer ces identifiants',
        ],

        'toast' => [
            'queued' => 'Balayage ouvert : il est confié au worker. Son détail suit son avancement.',
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
            'resume_unavailable_queued' => 'Ce balayage n’a pas encore démarré : il attend un worker, il n’y a rien à reprendre.',
            'worker_missing' => 'Ce balayage attend depuis plus d’une minute sans qu’aucun worker ne le prenne. Lancez « php artisan queue:listen --timeout=900 », ou « composer dev » qui le fait pour vous.',
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
    | Les trois clés `ids.*`, elles, sont des messages complets.
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
            'max' => 'Un envoi accepte au plus :max identifiants. La console, elle, avale une liste entière.',
            'invalid' => 'Aucun identifiant lisible dans ce collage : attendez un nombre nu ou une URL TMDB par ligne.',
        ],
    ],

    'error' => [
        'tmdb_disabled' => 'Import TMDB désactivé : ni TMDB_API_READ_ACCESS_TOKEN ni TMDB_API_KEY n’est renseignée.',
        'import_already_running' => 'Un balayage de cette nature est déjà ouvert. Attendez qu’il finisse, ou reprenez-le depuis son détail.',
        'run_not_resumable' => 'Ce balayage n’est pas reprenable : seuls les balayages discover encore en cours le sont.',
    ],

    /*
    | `php artisan admin:first-admin` — la seule porte d’entrée du panneau tant
    | que l’écran de gestion des accès de la spec 20 n’existe pas. Locale forcée
    | en français par la commande elle-même.
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
            'not_configured' => 'Import TMDB désactivé : ni TMDB_API_READ_ACCESS_TOKEN ni TMDB_API_KEY n’est renseignée. Le jeu, lui, n’appelle jamais TMDB.',
            'unauthorized' => 'TMDB a refusé l’authentification (statut :status) : jeton v4 ou clé v3 invalide, révoquée, ou dépourvue du droit demandé.',
            'not_found' => 'TMDB ne connaît pas la ressource demandée : vérifiez l’identifiant avant de relancer.',
            'rate_limited' => 'Quota TMDB atteint : le balayage est suspendu et reprenable avec --resume.',
            'server_error' => 'TMDB est en panne (statut :status), tentatives épuisées : le balayage est suspendu et reprenable avec --resume.',
            'transport' => 'Appel TMDB interrompu (DNS, TLS, délai d’attente ou connexion coupée) : le balayage est suspendu et reprenable avec --resume.',
            'malformed' => 'Réponse TMDB hors contrat : rien n’a été écrit au catalogue, le champ fautif est nommé dans le journal applicatif.',
            'unexpected_status' => 'Statut TMDB inattendu (:status) : aucune reprise automatique, consultez le journal applicatif.',
        ],
    ],

];
