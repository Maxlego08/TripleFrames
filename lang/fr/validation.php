<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lignes de langue : validation
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/validation.php` : mêmes clés, mêmes
    | `:placeholder`. Les deux symétries sont vérifiées en CI (spec 05
    | § Couverture des clés) — un `:min` oublié ici produirait une phrase fausse
    | en production sans que personne ne le voie à la relecture.
    |
    */

    'accepted' => 'Le champ :attribute doit être accepté.',
    'accepted_if' => 'Le champ :attribute doit être accepté quand :other vaut :value.',
    'active_url' => 'Le champ :attribute doit être une URL valide.',
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'alpha' => 'Le champ :attribute ne peut contenir que des lettres.',
    'alpha_dash' => 'Le champ :attribute ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ :attribute ne peut contenir que des lettres et des chiffres.',
    'any_of' => 'Le champ :attribute est invalide.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'array_keys' => 'Le champ :attribute ne peut contenir que les clés suivantes : :values.',
    'ascii' => 'Le champ :attribute ne peut contenir que des caractères alphanumériques et des symboles sur un octet.',
    'base64' => 'Le champ :attribute doit être une chaîne Base64 valide.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le champ :attribute doit peser entre :min et :max kilooctets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit valoir vrai ou faux.',
    'can' => 'Le champ :attribute contient une valeur non autorisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'contains' => 'Il manque une valeur obligatoire au champ :attribute.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'date_equals' => 'Le champ :attribute doit être une date égale au :date.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'decimal' => 'Le champ :attribute doit comporter :decimal décimales.',
    'declined' => 'Le champ :attribute doit être refusé.',
    'declined_if' => 'Le champ :attribute doit être refusé quand :other vaut :value.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit comporter :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit comporter entre :min et :max chiffres.',
    'dimensions' => 'Les dimensions de l’image :attribute sont invalides.',
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'doesnt_contain' => 'Le champ :attribute ne doit contenir aucune des valeurs suivantes : :values.',
    'doesnt_end_with' => 'Le champ :attribute ne doit pas se terminer par l’une des valeurs suivantes : :values.',
    'doesnt_start_with' => 'Le champ :attribute ne doit pas commencer par l’une des valeurs suivantes : :values.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'encoding' => 'Le champ :attribute doit être encodé en :encoding.',
    'ends_with' => 'Le champ :attribute doit se terminer par l’une des valeurs suivantes : :values.',
    'enum' => 'La valeur sélectionnée pour :attribute est invalide.',
    'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
    'extensions' => 'Le champ :attribute doit porter l’une des extensions suivantes : :values.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'gt' => [
        'array' => 'Le champ :attribute doit contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit peser plus de :value kilooctets.',
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'array' => 'Le champ :attribute doit contenir au moins :value éléments.',
        'file' => 'Le champ :attribute doit peser au moins :value kilooctets.',
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au moins :value caractères.',
    ],
    'hex_color' => 'Le champ :attribute doit être une couleur hexadécimale valide.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'in_array' => 'Le champ :attribute doit exister dans :other.',
    'in_array_keys' => 'Le champ :attribute doit contenir au moins l’une des clés suivantes : :values.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'ip' => 'Le champ :attribute doit être une adresse IP valide.',
    'ipv4' => 'Le champ :attribute doit être une adresse IPv4 valide.',
    'ipv6' => 'Le champ :attribute doit être une adresse IPv6 valide.',
    'json' => 'Le champ :attribute doit être une chaîne JSON valide.',
    'list' => 'Le champ :attribute doit être une liste.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',
    'lt' => [
        'array' => 'Le champ :attribute doit contenir moins de :value éléments.',
        'file' => 'Le champ :attribute doit peser moins de :value kilooctets.',
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
        'string' => 'Le champ :attribute doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit peser au plus :value kilooctets.',
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au plus :value caractères.',
    ],
    'mac_address' => 'Le champ :attribute doit être une adresse MAC valide.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file' => 'Le champ :attribute ne doit pas peser plus de :max kilooctets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne doit pas contenir plus de :max caractères.',
    ],
    'max_digits' => 'Le champ :attribute ne doit pas comporter plus de :max chiffres.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'mimetypes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le champ :attribute doit peser au moins :min kilooctets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'min_digits' => 'Le champ :attribute doit comporter au moins :min chiffres.',
    'missing' => 'Le champ :attribute doit être absent.',
    'missing_if' => 'Le champ :attribute doit être absent quand :other vaut :value.',
    'missing_unless' => 'Le champ :attribute doit être absent sauf si :other vaut :value.',
    'missing_with' => 'Le champ :attribute doit être absent quand :values est présent.',
    'missing_with_all' => 'Le champ :attribute doit être absent quand :values sont présents.',
    'multiple_of' => 'Le champ :attribute doit être un multiple de :value.',
    'not_in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'not_regex' => 'Le format du champ :attribute est invalide.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Le champ :attribute est apparu dans une fuite de données. Choisissez un autre :attribute.',
    ],
    'present' => 'Le champ :attribute doit être présent.',
    'present_if' => 'Le champ :attribute doit être présent quand :other vaut :value.',
    'present_unless' => 'Le champ :attribute doit être présent sauf si :other vaut :value.',
    'present_with' => 'Le champ :attribute doit être présent quand :values est présent.',
    'present_with_all' => 'Le champ :attribute doit être présent quand :values sont présents.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'prohibited_if' => 'Le champ :attribute est interdit quand :other vaut :value.',
    'prohibited_if_accepted' => 'Le champ :attribute est interdit quand :other est accepté.',
    'prohibited_if_declined' => 'Le champ :attribute est interdit quand :other est refusé.',
    'prohibited_unless' => 'Le champ :attribute est interdit sauf si :other est dans :values.',
    'prohibits' => 'Le champ :attribute interdit la présence de :other.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_array_keys' => 'Le champ :attribute doit contenir des entrées pour : :values.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_if_accepted' => 'Le champ :attribute est obligatoire quand :other est accepté.',
    'required_if_declined' => 'Le champ :attribute est obligatoire quand :other est refusé.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other est dans :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_with_all' => 'Le champ :attribute est obligatoire quand :values sont présents.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values est absent.',
    'required_without_all' => 'Le champ :attribute est obligatoire quand aucun des :values n’est présent.',
    'same' => 'Le champ :attribute doit correspondre à :other.',
    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le champ :attribute doit peser :size kilooctets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'starts_with' => 'Le champ :attribute doit commencer par l’une des valeurs suivantes : :values.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'timezone' => 'Le champ :attribute doit être un fuseau horaire valide.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'uploaded' => 'Le téléversement du champ :attribute a échoué.',
    'uppercase' => 'Le champ :attribute doit être en majuscules.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'ulid' => 'Le champ :attribute doit être un ULID valide.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',

    /*
    |--------------------------------------------------------------------------
    | Bornes croisées des réglages de salon
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/validation.php` § `room_settings`. Émises par
    | `App\Settings\RoomSettings` : l’hôte qui règle une manche de 10 s avec
    | 5 images doit lire le minimum calculé, pas une clé brute. `not_editable`
    | et `theme_keys` viennent de `App\Settings\RoomSettingsEditor`,
    | `capacity_below_headcount` de la garde de capacité (spec 50 § 3.1, § 10).
    |
    */

    'room_settings' => [
        'between' => 'Le réglage :attribute doit être compris entre :min et :max.',
        'boolean' => 'Le réglage :attribute doit être activé ou désactivé.',
        'capacity_below_headcount' => 'Le salon compte déjà :count joueurs : les places ne peuvent pas descendre en dessous.',
        'duration_mismatch' => 'Le réglage :attribute totalise :sum secondes alors que la manche en dure :duration.',
        'enum' => 'Le réglage :attribute ne fait pas partie des valeurs autorisées.',
        'integer' => 'Le réglage :attribute doit être un nombre entier.',
        'integer_list' => 'Le réglage :attribute doit être une liste de nombres entiers.',
        'list_size' => 'Le réglage :attribute doit compter exactement :size valeurs, une par image.',
        'not_editable' => 'Le réglage :attribute ne peut pas être modifié depuis cet onglet.',
        'round_duration' => 'Le réglage :attribute doit être compris entre :min et :max secondes pour :frames images par manche.',
        'sum_between' => 'Le réglage :attribute doit totaliser entre :min et :max secondes.',
        'theme_ids' => 'Le réglage :attribute doit être une liste d’identifiants de thèmes.',
        'theme_keys' => 'Le réglage :attribute contient un thème inconnu ou retiré du site.',
        'tier_duration' => 'Le palier :tier du réglage :attribute doit être compris entre :min et :max secondes.',
        'unknown_field' => 'Le réglage :attribute n’existe pas.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pseudo d’un siège
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/validation.php` § `nickname`. Bâties par
    | `App\Rules\ValidNickname` depuis ses constantes `KEY_*` (spec 40 § 5.9,
    | contrat C5) : un seul message par envoi, le premier échec l’emporte.
    | `taken` est émise par la prise de siège sous le verrou du salon, jamais
    | par la règle. `blocked` ne cite jamais le mot et ne distingue jamais un
    | nom réservé d’une grossièreté : le dire enseignerait la liste.
    |
    */

    'nickname' => [
        'length' => 'Le :attribute doit compter entre :min et :max caractères.',
        'script' => 'Le :attribute ne peut utiliser que l’alphabet latin, accents compris, des chiffres, des espaces, « - » et « _ ».',
        'characters' => 'Ce pseudo contient un caractère non autorisé : symbole, émoji ou caractère invisible.',
        'alnum' => 'Le pseudo doit contenir au moins une lettre ou un chiffre.',
        'normalized_length' => 'Ce pseudo est trop long une fois ses lettres spéciales développées (ß, æ, œ…). Raccourcissez-le.',
        'blocked' => 'Ce pseudo n’est pas disponible. Choisissez-en un autre.',
        'taken' => 'Ce pseudo est déjà pris dans ce salon.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages de validation personnalisés
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Noms lisibles des champs
    |--------------------------------------------------------------------------
    |
    | Sans ce bloc, un joueur francophone lit le nom de colonne anglais au
    | milieu d’une phrase française (spec 05 § Dictionnaires serveur). Toute
    | clé ajoutée ici doit l’être aussi dans `lang/en/validation.php`, la
    | symétrie des clés portant sur ce fichier comme sur les autres.
    |
    */

    'attributes' => [
        'advanced' => 'onglet avancé',
        'allowLateJoin' => 'entrée en cours de partie',
        'answer' => 'réponse',
        'attemptsPerRound' => 'tentatives par manche',
        'attemptsPerSecond' => 'tentatives par seconde',
        'avatar' => 'avatar',
        'capacity' => 'nombre de sièges',
        'choice' => 'proposition',
        'code' => 'code d’authentification',
        'current_password' => 'mot de passe actuel',
        'disconnectGraceSeconds' => 'délai de grâce à la déconnexion',
        'email' => 'adresse e-mail',
        'framesPerRound' => 'nombre d’images par manche',
        'inputDifficulty' => 'difficulté de saisie',
        'locale' => 'langue',
        'maxAnswerLength' => 'longueur maximale d’une réponse',
        'name' => 'nom',
        'nickname' => 'pseudo',
        'noRepeatMovies' => 'non-répétition des films',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'real_name' => 'nom réel',
        'recovery_code' => 'code de récupération',
        'remember' => 'se souvenir de moi',
        'revealDuration' => 'durée de révélation',
        'roundDuration' => 'durée d’une manche',
        'roundsCount' => 'nombre de manches',
        'speedBonus' => 'bonus de rapidité',
        'themeIds' => 'thèmes',
        'themeKeys' => 'thèmes',
        'tierDurations' => 'durées des paliers',
        'tierPoints' => 'valeurs des paliers',
        'token' => 'jeton',
    ],

];
