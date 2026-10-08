<?php

namespace App\Settings;

use App\Casts\RoomSettingsCast;
use App\Enums\InputDifficulty;
use App\Support\Scoring\ScoringRules;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * Réglages d'un salon — value object versionné, seize champs, liste close.
 *
 * Il n'existe AUCUNE table `room_settings` : une table ajouterait une jointure sur
 * l'objet le plus chaud et une question de cycle de vie que rien ne réclame. L'objet
 * est sérialisé par {@see RoomSettingsCast} dans quatre colonnes `json`
 * — `room.settings`, `setting_preset.settings`, `saved_config.settings`,
 * `game.settings_snapshot` — chacune doublée d'une colonne `settings_version` en clair.
 *
 * Le nom est `RoomSettings`, PAS `GameSettings` : la partie fige sa propre copie au
 * lancement, donc deux objets de sens différents porteraient le même nom sur deux
 * tables voisines.
 *
 * `D` n'est PAS un champ : `D = array_sum($tierDurations)`, une seule source
 * ({@see self::roundDuration()}). Garder les deux ouvrirait une divergence
 * silencieuse entre 40 s annoncés et 13 + 13 + 14 matérialisés.
 *
 * `tierGraceMs`, `preloadLeadMs` et `speedBonusMaxPercent` n'y sont PAS : ce sont
 * des constantes d'instance ({@see PlatformLimits}), jamais des réglages d'hôte, et
 * les poster est un refus dur. `B_max` n'est ni un champ ni un curseur : l'hôte n'a
 * que l'interrupteur `speedBonus`.
 *
 * **Deux chemins de lecture, jamais confondus.**
 * - ENTRÉE (formulaire) : {@see self::fromInput()} — seul constructeur produisant une
 *   instance valide depuis des données non fiables. Valide les bornes simples et les
 *   bornes croisées 1 et 2 ensemble, et REFUSE en renvoyant les erreurs indexées par
 *   champ. Les bornes croisées 4 et 5 ne refusent jamais : ce sont des avertissements,
 *   rendus par {@see self::warnings()}.
 * - CHARGEMENT (`saved_config`, preset) : {@see self::normalize()} — chaîne ordonnée de
 *   normaliseurs, qui ne refuse JAMAIS et rend un rapport champ par champ.
 *
 * La normalisation n'a JAMAIS lieu dans le cast : `Model::save()` appelle
 * `mergeAttributesFromCachedCasts()`, qui rappelle `set()` sur l'objet mis en cache
 * par `get()`. Si `get()` normalisait, un simple renommage de `saved_config`
 * réécrirait son JSON écrêté sans confirmation.
 */
final readonly class RoomSettings
{
    /**
     * Version de la disposition des champs.
     *
     * Incrémentée dès qu'un champ est ajouté, retiré ou change de sens, et dès
     * qu'une borne de {@see RoomSettingsBounds} se resserre, avec son pas dans
     * {@see self::upgrade()} : sinon un salon ouvert avant le déploiement garderait
     * une valeur devenue hors bornes et la figerait au lancement. Les bornes lues
     * dans {@see PlatformLimits} n'y passent pas (spec 50 § 2.1, règle 4). Elle est
     * écrite en COLONNE (`settings_version`), jamais dans le JSON : il faut savoir
     * lire la charge utile avant de l'ouvrir (§ 1.6).
     */
    public const int VERSION = 1;

    /**
     * Les seize champs, dans l'ordre de sérialisation. Liste close.
     *
     * `sourceVersion` n'en fait PAS partie : c'est une propriété de provenance, hors
     * charge utile, qui vit en colonne `settings_version` (§ 1.6).
     */
    public const array FIELDS = [
        'themeIds',
        'roundsCount',
        'framesPerRound',
        'tierDurations',
        'tierPoints',
        'revealDuration',
        'inputDifficulty',
        'capacity',
        'allowLateJoin',
        'speedBonus',
        'noRepeatMovies',
        'attemptsPerSecond',
        'attemptsPerRound',
        'maxAnswerLength',
        'disconnectGraceSeconds',
        'advanced',
    ];

    /**
     * Clé d'entrée de l'onglet Simple, et elle seule : `D`.
     *
     * Elle n'est pas un champ de l'objet. Le formulaire Simple la poste, et
     * {@see self::fromInput()} en dérive `tierDurations` par paliers égaux, le
     * dernier absorbant le reste. En Avancé, `tierDurations` est posté et `D`
     * devient leur somme.
     */
    public const string INPUT_ROUND_DURATION = 'roundDuration';

    /**
     * Motifs de modification, en données — liste close des codes du rapport de
     * changements (`RoomSettingsChangeCode`).
     *
     * Les six premiers sont rendus par {@see self::normalize()}.
     */
    public const string CHANGE_DEFAULTED = 'defaulted';

    public const string CHANGE_DROPPED = 'dropped';

    public const string CHANGE_CLAMPED = 'clamped';

    public const string CHANGE_RESIZED = 'resized';

    public const string CHANGE_PRUNED = 'pruned';

    public const string CHANGE_COERCED = 'coerced';

    /** Onglet Simple (D34 du 23/09) : des paliers inégaux sont réégalisés sur `D`. */
    public const string CHANGE_EQUALIZED = 'equalized';

    /** Onglet Simple (D34 du 23/09) : un barème personnalisé revient au défaut quand `N` change. */
    public const string CHANGE_RESET = 'reset';

    /** [J2] Chargement d'une configuration : la capacité est relevée à l'effectif présent. */
    public const string CHANGE_RAISED = 'raised';

    /** [J2] Preset ou chargement : un champ avancé personnalisé est écrasé. */
    public const string CHANGE_OVERWRITTEN = 'overwritten';

    /** Avertissements non bloquants — bornes croisées 4 et 5. */
    public const string WARNING_SHORT_REVEAL = 'short_reveal';

    public const string WARNING_LONG_ROUND = 'long_round';

    public const string WARNING_NON_DECREASING_POINTS = 'non_decreasing_points';

    /**
     * Barème strictement décroissant où, bonus de rapidité compris, l'ouverture
     * d'un palier rapporte plus que la fin du précédent
     * ({@see ScoringRules::waitingPays()}, spec 80 § 3.4 ; n° 44, lot L50-10).
     */
    public const string WARNING_WAITING_PAYS = 'waiting_pays';

    public const string WARNING_ALL_TIERS_ZERO = 'all_tiers_zero';

    /** Préfixe des clés de message : aucune chaîne humaine n'est écrite ici (règle 4). */
    private const string MESSAGE_PREFIX = 'validation.room_settings.';

    /**
     * @param  list<int>  $themeIds  Union (OU) des thèmes ; vide = tout le catalogue.
     * @param  int  $roundsCount  `M`, nombre de manches.
     * @param  int  $framesPerRound  `N`, nombre d'images par manche.
     * @param  list<int>  $tierDurations  `dᵢ`, `N` entrées en secondes entières.
     * @param  list<int>  $tierPoints  Valeur du palier `i`, `N` entrées de 0 à 1000.
     * @param  int  $revealDuration  `R`, durée de la révélation en secondes.
     * @param  InputDifficulty  $inputDifficulty  Difficulté de SAISIE du salon.
     * @param  int  $capacity  Nombre de sièges.
     * @param  bool  $allowLateJoin  Entrée après le lancement, à la manche suivante.
     * @param  bool  $speedBonus  Interrupteur du bonus de rapidité ; son plafond `B_max(N)` vit
     *                            dans {@see PlatformLimits::speedBonusMaxPercent()}.
     * @param  bool  $noRepeatMovies  Non-répétition des films déjà joués par le salon.
     * @param  int  $attemptsPerSecond  Cadence maximale de la saisie libre.
     * @param  int  $attemptsPerRound  Plafond absolu de tentatives en texte libre.
     * @param  int  $maxAnswerLength  Longueur maximale d'une réponse, en caractères.
     * @param  int  $disconnectGraceSeconds  Réglage d'hôte, jamais `tier_grace_ms`.
     * @param  bool  $advanced  Onglet d'édition retenu — deux vues du même objet.
     * @param  int  $sourceVersion  Version DONT L'INSTANCE SORT — pas un des seize champs,
     *                              jamais sérialisée dans la charge utile
     *                              ({@see self::toPayload()}), jamais comparée par
     *                              {@see self::equals()}. {@see self::fromStorage()} y pose la
     *                              version lue en colonne ; tous les autres constructeurs y
     *                              posent {@see self::VERSION}, parce qu'ils sont les seuls à
     *                              avoir réellement produit la disposition courante. C'est
     *                              elle que {@see RoomSettingsCast::set()} réécrit
     *                              en colonne : sans elle, une simple LECTURE suivie d'un
     *                              `touch()` ferait passer une ligne v1 en v2 sans jouer le
     *                              pas de migration de `upgrade()`, qui ne s'applique plus
     *                              jamais ensuite.
     */
    private function __construct(
        public array $themeIds,
        public int $roundsCount,
        public int $framesPerRound,
        public array $tierDurations,
        public array $tierPoints,
        public int $revealDuration,
        public InputDifficulty $inputDifficulty,
        public int $capacity,
        public bool $allowLateJoin,
        public bool $speedBonus,
        public bool $noRepeatMovies,
        public int $attemptsPerSecond,
        public int $attemptsPerRound,
        public int $maxAnswerLength,
        public int $disconnectGraceSeconds,
        public bool $advanced,
        public int $sourceVersion = self::VERSION,
    ) {}

    /**
     * Réglages par défaut du site — chaque valeur vient de {@see RoomSettingsBounds}.
     */
    public static function defaults(): self
    {
        $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
        $roundDuration = RoomSettingsBounds::DEFAULT_ROUND_DURATION;

        return new self(
            themeIds: RoomSettingsBounds::defaultThemeIds(),
            roundsCount: RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
            framesPerRound: $framesPerRound,
            tierDurations: RoomSettingsBounds::defaultTierDurations($framesPerRound, $roundDuration),
            tierPoints: RoomSettingsBounds::defaultTierPoints($framesPerRound),
            revealDuration: RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
            inputDifficulty: RoomSettingsBounds::DEFAULT_INPUT_DIFFICULTY,
            capacity: RoomSettingsBounds::defaultCapacity(),
            allowLateJoin: RoomSettingsBounds::DEFAULT_ALLOW_LATE_JOIN,
            speedBonus: RoomSettingsBounds::DEFAULT_SPEED_BONUS,
            noRepeatMovies: RoomSettingsBounds::DEFAULT_NO_REPEAT_MOVIES,
            attemptsPerSecond: RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND,
            attemptsPerRound: RoomSettingsBounds::defaultAttemptsPerRound($roundDuration),
            maxAnswerLength: RoomSettingsBounds::DEFAULT_ANSWER_LENGTH,
            disconnectGraceSeconds: RoomSettingsBounds::DEFAULT_DISCONNECT_GRACE_SECONDS,
            advanced: RoomSettingsBounds::DEFAULT_ADVANCED,
        );
    }

    /**
     * Seul constructeur produisant une instance valide depuis des données non fiables.
     *
     * Un FormRequest champ par champ laisserait passer 10 s × 5 images ; un
     * `final readonly` qui peut exister dans un état invalide ne vaut rien.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws ValidationException Erreurs indexées par champ.
     */
    public static function fromInput(array $raw): self
    {
        $errors = self::validate($raw);

        if ($errors !== []) {
            $messages = [];

            foreach ($errors as $field => $failures) {
                foreach ($failures as $failure) {
                    $messages[$field][] = self::translate($failure['key'], $failure['replace']);
                }
            }

            throw ValidationException::withMessages($messages);
        }

        $framesPerRound = self::readInt($raw, 'framesPerRound')
            ?? RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

        $tierDurations = self::readIntList($raw, 'tierDurations');

        if ($tierDurations === null) {
            $tierDurations = RoomSettingsBounds::defaultTierDurations(
                $framesPerRound,
                self::readInt($raw, self::INPUT_ROUND_DURATION)
                    ?? RoomSettingsBounds::DEFAULT_ROUND_DURATION,
            );
        }

        $roundDuration = (int) array_sum($tierDurations);

        return new self(
            themeIds: self::readIdList($raw, 'themeIds') ?? RoomSettingsBounds::defaultThemeIds(),
            roundsCount: self::readInt($raw, 'roundsCount') ?? RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
            framesPerRound: $framesPerRound,
            tierDurations: $tierDurations,
            tierPoints: self::readIntList($raw, 'tierPoints')
                ?? RoomSettingsBounds::defaultTierPoints($framesPerRound),
            revealDuration: self::readInt($raw, 'revealDuration') ?? RoomSettingsBounds::DEFAULT_REVEAL_DURATION,
            inputDifficulty: self::readInputDifficulty($raw) ?? RoomSettingsBounds::DEFAULT_INPUT_DIFFICULTY,
            capacity: self::readInt($raw, 'capacity') ?? RoomSettingsBounds::defaultCapacity(),
            allowLateJoin: self::readBool($raw, 'allowLateJoin') ?? RoomSettingsBounds::DEFAULT_ALLOW_LATE_JOIN,
            speedBonus: self::readBool($raw, 'speedBonus') ?? RoomSettingsBounds::DEFAULT_SPEED_BONUS,
            noRepeatMovies: self::readBool($raw, 'noRepeatMovies') ?? RoomSettingsBounds::DEFAULT_NO_REPEAT_MOVIES,
            attemptsPerSecond: self::readInt($raw, 'attemptsPerSecond')
                ?? RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND,
            attemptsPerRound: self::readInt($raw, 'attemptsPerRound')
                ?? RoomSettingsBounds::defaultAttemptsPerRound($roundDuration),
            maxAnswerLength: self::readInt($raw, 'maxAnswerLength') ?? RoomSettingsBounds::DEFAULT_ANSWER_LENGTH,
            disconnectGraceSeconds: self::readInt($raw, 'disconnectGraceSeconds')
                ?? RoomSettingsBounds::DEFAULT_DISCONNECT_GRACE_SECONDS,
            advanced: self::readBool($raw, 'advanced') ?? RoomSettingsBounds::DEFAULT_ADVANCED,
        );
    }

    /**
     * Toutes les violations d'une entrée, indexées par champ, en clés de message.
     *
     * Les messages transitent en DONNÉES (clé + remplacements) et jamais en chaînes
     * pré-formatées côté serveur : chaque joueur les lit dans sa langue.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, list<array{key: string, replace: array<string, int|string>}>>
     */
    public static function validate(array $raw): array
    {
        $errors = [];

        // Toute clé inconnue est un refus dur : c'est ce qui rend `graceMs`,
        // `tierGraceMs` et `preloadLeadMs` structurellement inatteignables.
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::FIELDS, true) && $key !== self::INPUT_ROUND_DURATION) {
                $errors[$key][] = self::failure('unknown_field', ['attribute' => $key]);
            }
        }

        self::checkInteger($raw, $errors, 'roundsCount', RoomSettingsBounds::MIN_ROUNDS_COUNT, RoomSettingsBounds::MAX_ROUNDS_COUNT);
        self::checkInteger($raw, $errors, 'framesPerRound', RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND);
        self::checkInteger($raw, $errors, 'revealDuration', RoomSettingsBounds::MIN_REVEAL_DURATION, RoomSettingsBounds::MAX_REVEAL_DURATION);
        self::checkInteger($raw, $errors, 'capacity', RoomSettingsBounds::minCapacity(), RoomSettingsBounds::maxCapacity());
        self::checkInteger($raw, $errors, 'attemptsPerSecond', RoomSettingsBounds::MIN_ATTEMPTS_PER_SECOND, RoomSettingsBounds::MAX_ATTEMPTS_PER_SECOND);
        self::checkInteger($raw, $errors, 'attemptsPerRound', RoomSettingsBounds::MIN_ATTEMPTS_PER_ROUND, RoomSettingsBounds::MAX_ATTEMPTS_PER_ROUND);
        self::checkInteger($raw, $errors, 'maxAnswerLength', RoomSettingsBounds::MIN_ANSWER_LENGTH, RoomSettingsBounds::MAX_ANSWER_LENGTH);
        self::checkInteger($raw, $errors, 'disconnectGraceSeconds', RoomSettingsBounds::MIN_DISCONNECT_GRACE_SECONDS, RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS);

        foreach (['allowLateJoin', 'speedBonus', 'noRepeatMovies', 'advanced'] as $field) {
            if (array_key_exists($field, $raw) && self::readBool($raw, $field) === null) {
                $errors[$field][] = self::failure('boolean', ['attribute' => $field]);
            }
        }

        if (array_key_exists('themeIds', $raw) && self::readIdList($raw, 'themeIds') === null) {
            $errors['themeIds'][] = self::failure('theme_ids', ['attribute' => 'themeIds']);
        }

        if (array_key_exists('inputDifficulty', $raw) && self::readInputDifficulty($raw) === null) {
            $errors['inputDifficulty'][] = self::failure('enum', ['attribute' => 'inputDifficulty']);
        }

        $framesPerRound = RoomSettingsBounds::clampFramesPerRound(
            self::readInt($raw, 'framesPerRound') ?? RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        );

        $roundDuration = self::validateRoundDuration($raw, $errors, $framesPerRound);
        self::validateTierDurations($raw, $errors, $framesPerRound, $roundDuration);
        self::validateTierPoints($raw, $errors, $framesPerRound);

        return $errors;
    }

    /**
     * Chargement d'une charge utile persistée : ne refuse JAMAIS, et rend un rapport.
     *
     * Champ ajouté = défaut, champ retiré = abandonné, borne resserrée = écrêtage,
     * thème disparu = retiré, paliers réégalisés si `N` a changé. Refuser au
     * chargement rendrait vingt configurations inutilisables à la première borne
     * resserrée, ce que la règle « jamais réécrite en base » interdit de corriger
     * par migration.
     *
     * Le résultat n'est JAMAIS réinjecté dans le modèle : c'est un appel explicite
     * de l'action de chargement, dans un lobby, pas un geste du cast.
     *
     * @param  array<string, mixed>  $raw  Charge utile telle que persistée.
     * @param  int  $version  Version sous laquelle elle a été écrite (colonne en clair).
     * @param  list<int>|null  $availableThemeIds  Thèmes encore publiés ; `null` = pas d'élagage.
     * @return array{settings: self, changes: array<string, string>}
     */
    public static function normalize(array $raw, int $version, ?array $availableThemeIds = null): array
    {
        $changes = [];
        $defaults = self::defaults();
        $raw = self::upgrade($raw, $version);

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::FIELDS, true)) {
                $changes[$key] = self::CHANGE_DROPPED;
            }
        }

        $framesPerRound = self::normalizeInt(
            $raw, $changes, 'framesPerRound', $defaults->framesPerRound,
            RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        );

        $tierDurations = self::normalizeTierDurations($raw, $changes, $framesPerRound);
        $roundDuration = (int) array_sum($tierDurations);
        $tierPoints = self::normalizeTierPoints($raw, $changes, $framesPerRound);
        $themeIds = self::normalizeThemeIds($raw, $changes, $availableThemeIds);

        $settings = new self(
            themeIds: $themeIds,
            roundsCount: self::normalizeInt(
                $raw, $changes, 'roundsCount', $defaults->roundsCount,
                RoomSettingsBounds::MIN_ROUNDS_COUNT, RoomSettingsBounds::MAX_ROUNDS_COUNT,
            ),
            framesPerRound: $framesPerRound,
            tierDurations: $tierDurations,
            tierPoints: $tierPoints,
            revealDuration: self::normalizeInt(
                $raw, $changes, 'revealDuration', $defaults->revealDuration,
                RoomSettingsBounds::MIN_REVEAL_DURATION, RoomSettingsBounds::MAX_REVEAL_DURATION,
            ),
            inputDifficulty: self::normalizeInputDifficulty($raw, $changes, $defaults->inputDifficulty),
            capacity: self::normalizeInt(
                $raw, $changes, 'capacity', $defaults->capacity,
                RoomSettingsBounds::minCapacity(), RoomSettingsBounds::maxCapacity(),
            ),
            allowLateJoin: self::normalizeBool($raw, $changes, 'allowLateJoin', $defaults->allowLateJoin),
            speedBonus: self::normalizeBool($raw, $changes, 'speedBonus', $defaults->speedBonus),
            noRepeatMovies: self::normalizeBool($raw, $changes, 'noRepeatMovies', $defaults->noRepeatMovies),
            attemptsPerSecond: self::normalizeInt(
                $raw, $changes, 'attemptsPerSecond', $defaults->attemptsPerSecond,
                RoomSettingsBounds::MIN_ATTEMPTS_PER_SECOND, RoomSettingsBounds::MAX_ATTEMPTS_PER_SECOND,
            ),
            attemptsPerRound: self::normalizeInt(
                $raw, $changes, 'attemptsPerRound',
                RoomSettingsBounds::defaultAttemptsPerRound($roundDuration),
                RoomSettingsBounds::MIN_ATTEMPTS_PER_ROUND, RoomSettingsBounds::MAX_ATTEMPTS_PER_ROUND,
            ),
            maxAnswerLength: self::normalizeInt(
                $raw, $changes, 'maxAnswerLength', $defaults->maxAnswerLength,
                RoomSettingsBounds::MIN_ANSWER_LENGTH, RoomSettingsBounds::MAX_ANSWER_LENGTH,
            ),
            disconnectGraceSeconds: self::normalizeInt(
                $raw, $changes, 'disconnectGraceSeconds', $defaults->disconnectGraceSeconds,
                RoomSettingsBounds::MIN_DISCONNECT_GRACE_SECONDS, RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS,
            ),
            advanced: self::normalizeBool($raw, $changes, 'advanced', $defaults->advanced),
        );

        return ['settings' => $settings, 'changes' => $changes];
    }

    /**
     * Chaîne ORDONNÉE de normaliseurs, jouée de `$version` à {@see self::VERSION}
     * AVANT la passe de défauts, d'écrêtage et de réégalisation.
     *
     * Elle ne refuse jamais, pas même une charge utile écrite par un code plus
     * récent : les champs inconnus sont abandonnés par la passe suivante.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function upgrade(array $raw, int $version): array
    {
        if ($version === self::VERSION) {
            return $raw;
        }

        // Aucun pas n'existe encore : la version 1 est la première disposition
        // publiée. Chaque incrément de VERSION ajoute ici son pas, dans l'ordre.
        return $raw;
    }

    /**
     * Hydratation FIDÈLE d'une charge utile persistée, pour le cast et lui seul.
     *
     * N'écrête rien, ne réégalise rien, ne retire aucun thème : c'est la condition
     * pour qu'un `touch()` laisse la colonne `settings` inchangée octet pour octet.
     * Un champ structurellement absent prend son défaut — une propriété typée ne
     * peut pas rester indéfinie —, et c'est exactement ce que
     * {@see self::normalize()} rapporte comme `defaulted` au chargement.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws UnexpectedValueException Charge utile illisible : l'échec est bruyant.
     */
    public static function fromStorage(array $raw, int $version): self
    {
        if ($version > self::VERSION) {
            throw new UnexpectedValueException(
                "Charge utile de réglages en version {$version}, supérieure à la version courante ".self::VERSION.'.',
            );
        }

        $defaults = self::defaults();

        return new self(
            themeIds: self::hydrateIdList($raw, 'themeIds', $defaults->themeIds),
            roundsCount: self::hydrateInt($raw, 'roundsCount', $defaults->roundsCount),
            framesPerRound: self::hydrateInt($raw, 'framesPerRound', $defaults->framesPerRound),
            tierDurations: self::hydrateIntList($raw, 'tierDurations', $defaults->tierDurations),
            tierPoints: self::hydrateIntList($raw, 'tierPoints', $defaults->tierPoints),
            revealDuration: self::hydrateInt($raw, 'revealDuration', $defaults->revealDuration),
            inputDifficulty: self::hydrateInputDifficulty($raw, $defaults->inputDifficulty),
            capacity: self::hydrateInt($raw, 'capacity', $defaults->capacity),
            allowLateJoin: self::hydrateBool($raw, 'allowLateJoin', $defaults->allowLateJoin),
            speedBonus: self::hydrateBool($raw, 'speedBonus', $defaults->speedBonus),
            noRepeatMovies: self::hydrateBool($raw, 'noRepeatMovies', $defaults->noRepeatMovies),
            attemptsPerSecond: self::hydrateInt($raw, 'attemptsPerSecond', $defaults->attemptsPerSecond),
            attemptsPerRound: self::hydrateInt($raw, 'attemptsPerRound', $defaults->attemptsPerRound),
            maxAnswerLength: self::hydrateInt($raw, 'maxAnswerLength', $defaults->maxAnswerLength),
            disconnectGraceSeconds: self::hydrateInt($raw, 'disconnectGraceSeconds', $defaults->disconnectGraceSeconds),
            advanced: self::hydrateBool($raw, 'advanced', $defaults->advanced),
            sourceVersion: $version,
        );
    }

    /**
     * `D` — durée d'une manche : la somme des paliers, et rien d'autre.
     */
    public function roundDuration(): int
    {
        return (int) array_sum($this->tierDurations);
    }

    /**
     * Instant d'ouverture du palier `i` (1-indexé), en millisecondes depuis
     * `round.started_at`. Arithmétique entière : identique dans les deux moteurs,
     * immune à tout fuseau.
     */
    public function tierStartOffsetMs(int $tierIndex): int
    {
        $offset = 0;

        for ($index = 1; $index < $tierIndex; $index++) {
            $offset += ($this->tierDurations[$index - 1] ?? 0) * 1000;
        }

        return $offset;
    }

    /**
     * Avertissements non bloquants, cumulables — bornes croisées 4 et 5, dans
     * cet ordre : révélation courte, manche longue, barème non strictement
     * décroissant, « attendre paie » (bonus actif seulement, et jamais en plus
     * de `non_decreasing_points`), barème entièrement à zéro.
     *
     * Rendus en codes, jamais en phrases : la mise en mots appartient au client.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->revealDuration < RoomSettingsBounds::RECOMMENDED_MIN_REVEAL_DURATION) {
            $warnings[] = self::WARNING_SHORT_REVEAL;
        }

        if ($this->roundDuration() > RoomSettingsBounds::LONG_ROUND_WARNING_DURATION) {
            $warnings[] = self::WARNING_LONG_ROUND;
        }

        $previous = null;
        $nonDecreasing = false;

        foreach ($this->tierPoints as $points) {
            if ($previous !== null && $points >= $previous) {
                $nonDecreasing = true;

                break;
            }

            $previous = $points;
        }

        if ($nonDecreasing) {
            $warnings[] = self::WARNING_NON_DECREASING_POINTS;
        }

        // « Attendre paie » (spec 80 § 3.4) : seulement bonus actif, et
        // seulement quand `non_decreasing_points` ne le dit pas déjà — un
        // barème non décroissant fait toujours payer l'attente, et deux
        // avertissements pour un même fait n'apprendraient rien à l'hôte.
        if (! $nonDecreasing
            && $this->speedBonus
            && ScoringRules::waitingPays($this->tierPoints, $this->framesPerRound)) {
            $warnings[] = self::WARNING_WAITING_PAYS;
        }

        if (array_sum($this->tierPoints) === 0) {
            $warnings[] = self::WARNING_ALL_TIERS_ZERO;
        }

        return $warnings;
    }

    /**
     * Charge utile sérialisable — ordre des clés FIXE, pour que le round-trip d'une
     * valeur inchangée redonne exactement les mêmes octets.
     *
     * La version n'y figure pas : elle vit en colonne (§ 1.6).
     *
     * @return array{themeIds: list<int>, roundsCount: int, framesPerRound: int, tierDurations: list<int>, tierPoints: list<int>, revealDuration: int, inputDifficulty: string, capacity: int, allowLateJoin: bool, speedBonus: bool, noRepeatMovies: bool, attemptsPerSecond: int, attemptsPerRound: int, maxAnswerLength: int, disconnectGraceSeconds: int, advanced: bool}
     */
    public function toPayload(): array
    {
        return [
            'themeIds' => $this->themeIds,
            'roundsCount' => $this->roundsCount,
            'framesPerRound' => $this->framesPerRound,
            'tierDurations' => $this->tierDurations,
            'tierPoints' => $this->tierPoints,
            'revealDuration' => $this->revealDuration,
            'inputDifficulty' => $this->inputDifficulty->value,
            'capacity' => $this->capacity,
            'allowLateJoin' => $this->allowLateJoin,
            'speedBonus' => $this->speedBonus,
            'noRepeatMovies' => $this->noRepeatMovies,
            'attemptsPerSecond' => $this->attemptsPerSecond,
            'attemptsPerRound' => $this->attemptsPerRound,
            'maxAnswerLength' => $this->maxAnswerLength,
            'disconnectGraceSeconds' => $this->disconnectGraceSeconds,
            'advanced' => $this->advanced,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toPayload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Deux jeux de réglages sont égaux si leurs charges utiles le sont.
     */
    public function equals(self $other): bool
    {
        return $this->toPayload() === $other->toPayload();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, list<array{key: string, replace: array<string, int|string>}>>  $errors
     */
    private static function checkInteger(array $raw, array &$errors, string $field, int $min, int $max): void
    {
        if (! array_key_exists($field, $raw)) {
            return;
        }

        $value = self::readInt($raw, $field);

        if ($value === null) {
            $errors[$field][] = self::failure('integer', ['attribute' => $field]);

            return;
        }

        if ($value < $min || $value > $max) {
            $errors[$field][] = self::failure('between', ['attribute' => $field, 'min' => $min, 'max' => $max]);
        }
    }

    /**
     * Borne croisée 1 : `D ≥ 5 s × N`, et `D` dans ses propres bornes.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, list<array{key: string, replace: array<string, int|string>}>>  $errors
     */
    private static function validateRoundDuration(array $raw, array &$errors, int $framesPerRound): ?int
    {
        if (! array_key_exists(self::INPUT_ROUND_DURATION, $raw)) {
            return null;
        }

        $value = self::readInt($raw, self::INPUT_ROUND_DURATION);

        if ($value === null) {
            $errors[self::INPUT_ROUND_DURATION][] = self::failure('integer', ['attribute' => self::INPUT_ROUND_DURATION]);

            return null;
        }

        $min = RoomSettingsBounds::minRoundDuration($framesPerRound);

        if ($value < $min || $value > RoomSettingsBounds::MAX_ROUND_DURATION) {
            $errors[self::INPUT_ROUND_DURATION][] = self::failure('round_duration', [
                'attribute' => self::INPUT_ROUND_DURATION,
                'min' => $min,
                'max' => RoomSettingsBounds::MAX_ROUND_DURATION,
                'frames' => $framesPerRound,
            ]);

            return null;
        }

        return $value;
    }

    /**
     * Borne croisée 2 : chaque `dᵢ ≥ 5 s`, et `Σ dᵢ = D` DANS les bornes de `D`.
     *
     * Sans cette seconde moitié, 5 s + 500 s à N=2 produirait une manche de 505 s
     * parfaitement hors bornes.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, list<array{key: string, replace: array<string, int|string>}>>  $errors
     */
    private static function validateTierDurations(array $raw, array &$errors, int $framesPerRound, ?int $roundDuration): void
    {
        if (! array_key_exists('tierDurations', $raw)) {
            return;
        }

        $values = self::readIntList($raw, 'tierDurations');

        if ($values === null) {
            $errors['tierDurations'][] = self::failure('integer_list', ['attribute' => 'tierDurations']);

            return;
        }

        if (count($values) !== $framesPerRound) {
            $errors['tierDurations'][] = self::failure('list_size', [
                'attribute' => 'tierDurations',
                'size' => $framesPerRound,
            ]);

            return;
        }

        $max = RoomSettingsBounds::maxTierDuration($framesPerRound);

        foreach ($values as $index => $value) {
            if ($value < RoomSettingsBounds::MIN_TIER_DURATION || $value > $max) {
                $errors['tierDurations.'.$index][] = self::failure('tier_duration', [
                    'attribute' => 'tierDurations',
                    'tier' => $index + 1,
                    'min' => RoomSettingsBounds::MIN_TIER_DURATION,
                    'max' => $max,
                ]);
            }
        }

        $sum = (int) array_sum($values);
        $min = RoomSettingsBounds::minRoundDuration($framesPerRound);

        if ($sum < $min || $sum > RoomSettingsBounds::MAX_ROUND_DURATION) {
            $errors['tierDurations'][] = self::failure('sum_between', [
                'attribute' => 'tierDurations',
                'min' => $min,
                'max' => RoomSettingsBounds::MAX_ROUND_DURATION,
            ]);
        }

        if ($roundDuration !== null && $sum !== $roundDuration) {
            $errors['tierDurations'][] = self::failure('duration_mismatch', [
                'attribute' => 'tierDurations',
                'sum' => $sum,
                'duration' => $roundDuration,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, list<array{key: string, replace: array<string, int|string>}>>  $errors
     */
    private static function validateTierPoints(array $raw, array &$errors, int $framesPerRound): void
    {
        if (! array_key_exists('tierPoints', $raw)) {
            return;
        }

        $values = self::readIntList($raw, 'tierPoints');

        if ($values === null) {
            $errors['tierPoints'][] = self::failure('integer_list', ['attribute' => 'tierPoints']);

            return;
        }

        if (count($values) !== $framesPerRound) {
            $errors['tierPoints'][] = self::failure('list_size', [
                'attribute' => 'tierPoints',
                'size' => $framesPerRound,
            ]);

            return;
        }

        foreach ($values as $index => $value) {
            if ($value < RoomSettingsBounds::MIN_TIER_POINTS || $value > RoomSettingsBounds::MAX_TIER_POINTS) {
                $errors['tierPoints.'.$index][] = self::failure('between', [
                    'attribute' => 'tierPoints',
                    'min' => RoomSettingsBounds::MIN_TIER_POINTS,
                    'max' => RoomSettingsBounds::MAX_TIER_POINTS,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     */
    private static function normalizeInt(array $raw, array &$changes, string $field, int $default, int $min, int $max): int
    {
        if (! array_key_exists($field, $raw)) {
            $changes[$field] = self::CHANGE_DEFAULTED;

            return $default;
        }

        $value = self::readInt($raw, $field);

        if ($value === null) {
            $changes[$field] = self::CHANGE_COERCED;

            return $default;
        }

        $clamped = RoomSettingsBounds::clamp($value, $min, $max);

        if ($clamped !== $value) {
            $changes[$field] = self::CHANGE_CLAMPED;
        }

        return $clamped;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     */
    private static function normalizeBool(array $raw, array &$changes, string $field, bool $default): bool
    {
        if (! array_key_exists($field, $raw)) {
            $changes[$field] = self::CHANGE_DEFAULTED;

            return $default;
        }

        $value = self::readBool($raw, $field);

        if ($value === null) {
            $changes[$field] = self::CHANGE_COERCED;

            return $default;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     */
    private static function normalizeInputDifficulty(array $raw, array &$changes, InputDifficulty $default): InputDifficulty
    {
        if (! array_key_exists('inputDifficulty', $raw)) {
            $changes['inputDifficulty'] = self::CHANGE_DEFAULTED;

            return $default;
        }

        $value = self::readInputDifficulty($raw);

        if ($value === null) {
            $changes['inputDifficulty'] = self::CHANGE_COERCED;

            return $default;
        }

        return $value;
    }

    /**
     * Paliers réégalisés si `N` a changé, écrêtés si une borne s'est resserrée.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     * @return list<int>
     */
    private static function normalizeTierDurations(array $raw, array &$changes, int $framesPerRound): array
    {
        $values = self::readIntList($raw, 'tierDurations');

        if ($values === null) {
            $changes['tierDurations'] = array_key_exists('tierDurations', $raw)
                ? self::CHANGE_COERCED
                : self::CHANGE_DEFAULTED;

            return RoomSettingsBounds::defaultTierDurations(
                $framesPerRound,
                RoomSettingsBounds::DEFAULT_ROUND_DURATION,
            );
        }

        if (count($values) !== $framesPerRound) {
            $changes['tierDurations'] = self::CHANGE_RESIZED;

            return RoomSettingsBounds::defaultTierDurations($framesPerRound, (int) array_sum($values));
        }

        $max = RoomSettingsBounds::maxTierDuration($framesPerRound);
        $clamped = [];
        $changed = false;

        foreach ($values as $value) {
            $bounded = RoomSettingsBounds::clamp($value, RoomSettingsBounds::MIN_TIER_DURATION, $max);
            $changed = $changed || $bounded !== $value;
            $clamped[] = $bounded;
        }

        $sum = (int) array_sum($clamped);
        $min = RoomSettingsBounds::minRoundDuration($framesPerRound);

        if ($sum < $min || $sum > RoomSettingsBounds::MAX_ROUND_DURATION) {
            $changes['tierDurations'] = self::CHANGE_RESIZED;

            return RoomSettingsBounds::defaultTierDurations($framesPerRound, $sum);
        }

        if ($changed) {
            $changes['tierDurations'] = self::CHANGE_CLAMPED;
        }

        return $clamped;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     * @return list<int>
     */
    private static function normalizeTierPoints(array $raw, array &$changes, int $framesPerRound): array
    {
        $values = self::readIntList($raw, 'tierPoints');

        if ($values === null) {
            $changes['tierPoints'] = array_key_exists('tierPoints', $raw)
                ? self::CHANGE_COERCED
                : self::CHANGE_DEFAULTED;

            return RoomSettingsBounds::defaultTierPoints($framesPerRound);
        }

        if (count($values) !== $framesPerRound) {
            $changes['tierPoints'] = self::CHANGE_RESIZED;

            return RoomSettingsBounds::defaultTierPoints($framesPerRound);
        }

        $clamped = [];
        $changed = false;

        foreach ($values as $value) {
            $bounded = RoomSettingsBounds::clamp(
                $value,
                RoomSettingsBounds::MIN_TIER_POINTS,
                RoomSettingsBounds::MAX_TIER_POINTS,
            );
            $changed = $changed || $bounded !== $value;
            $clamped[] = $bounded;
        }

        if ($changed) {
            $changes['tierPoints'] = self::CHANGE_CLAMPED;
        }

        return $clamped;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $changes
     * @param  list<int>|null  $availableThemeIds
     * @return list<int>
     */
    private static function normalizeThemeIds(array $raw, array &$changes, ?array $availableThemeIds): array
    {
        $values = self::readIdList($raw, 'themeIds');

        if ($values === null) {
            $changes['themeIds'] = array_key_exists('themeIds', $raw)
                ? self::CHANGE_COERCED
                : self::CHANGE_DEFAULTED;

            return RoomSettingsBounds::defaultThemeIds();
        }

        if ($availableThemeIds === null) {
            return $values;
        }

        $kept = array_values(array_filter(
            $values,
            static fn (int $id): bool => in_array($id, $availableThemeIds, true),
        ));

        if (count($kept) !== count($values)) {
            $changes['themeIds'] = self::CHANGE_PRUNED;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function hydrateInt(array $raw, string $field, int $default): int
    {
        if (! array_key_exists($field, $raw)) {
            return $default;
        }

        $value = self::readInt($raw, $field);

        if ($value === null) {
            throw new UnexpectedValueException("Réglage [{$field}] illisible : un entier était attendu.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function hydrateBool(array $raw, string $field, bool $default): bool
    {
        if (! array_key_exists($field, $raw)) {
            return $default;
        }

        $value = self::readBool($raw, $field);

        if ($value === null) {
            throw new UnexpectedValueException("Réglage [{$field}] illisible : un booléen était attendu.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<int>  $default
     * @return list<int>
     */
    private static function hydrateIntList(array $raw, string $field, array $default): array
    {
        if (! array_key_exists($field, $raw)) {
            return $default;
        }

        $values = self::readIntList($raw, $field);

        if ($values === null) {
            throw new UnexpectedValueException("Réglage [{$field}] illisible : une liste d'entiers était attendue.");
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<int>  $default
     * @return list<int>
     */
    private static function hydrateIdList(array $raw, string $field, array $default): array
    {
        if (! array_key_exists($field, $raw)) {
            return $default;
        }

        $values = self::readIdList($raw, $field);

        if ($values === null) {
            throw new UnexpectedValueException("Réglage [{$field}] illisible : une liste d'identifiants était attendue.");
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function hydrateInputDifficulty(array $raw, InputDifficulty $default): InputDifficulty
    {
        if (! array_key_exists('inputDifficulty', $raw)) {
            return $default;
        }

        $value = self::readInputDifficulty($raw);

        if ($value === null) {
            throw new UnexpectedValueException('Réglage [inputDifficulty] illisible : valeur hors de l\'énumération.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function readInt(array $raw, string $field): ?int
    {
        $value = $raw[$field] ?? null;

        if (is_int($value)) {
            return $value;
        }

        // Un formulaire HTTP poste des chaînes : `"30"` est un entier, `"30.5"` non.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function readBool(array $raw, string $field): ?bool
    {
        $value = $raw[$field] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        if (is_string($value) && in_array($value, ['0', '1', 'true', 'false'], true)) {
            return $value === '1' || $value === 'true';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<int>|null
     */
    private static function readIntList(array $raw, string $field): ?array
    {
        $value = $raw[$field] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $values = [];

        foreach ($value as $item) {
            if (is_int($item)) {
                $values[] = $item;

                continue;
            }

            if (is_string($item) && preg_match('/^-?\d+$/', $item) === 1) {
                $values[] = (int) $item;

                continue;
            }

            return null;
        }

        return $values;
    }

    /**
     * Liste d'identifiants : entiers strictement positifs, dédoublonnée et réindexée.
     *
     * @param  array<string, mixed>  $raw
     * @return list<int>|null
     */
    private static function readIdList(array $raw, string $field): ?array
    {
        $values = self::readIntList($raw, $field);

        if ($values === null) {
            return null;
        }

        foreach ($values as $value) {
            if ($value < 1) {
                return null;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function readInputDifficulty(array $raw): ?InputDifficulty
    {
        $value = $raw['inputDifficulty'] ?? null;

        if ($value instanceof InputDifficulty) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return InputDifficulty::tryFrom($value);
    }

    /**
     * @param  array<string, int|string>  $replace
     * @return array{key: string, replace: array<string, int|string>}
     */
    private static function failure(string $key, array $replace): array
    {
        return ['key' => self::MESSAGE_PREFIX.$key, 'replace' => $replace];
    }

    /**
     * Résout un message de borne croisée dans la langue de l'hôte.
     *
     * `:attribute` est résolu ici, et pas par le validateur : ces bornes ne
     * passent par aucune règle Laravel, donc rien ne remplacerait le nom de
     * champ brut par son libellé. Sans ce geste, un hôte francophone lirait
     * « Le réglage tierDurations… » (spec 05 § Dictionnaires serveur). Un champ
     * inconnu — le seul cas où la valeur vient de l'entrée — retombe sur son
     * propre nom.
     *
     * Une clé absente du dictionnaire est renvoyée telle quelle par le
     * traducteur : c'est la comparaison à la clé, et non un `is_string()`, qui
     * le détecte.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function translate(string $key, array $replace): string
    {
        if (array_key_exists('attribute', $replace)) {
            $replace['attribute'] = self::attributeLabel((string) $replace['attribute']);
        }

        $message = trans($key, $replace);

        return is_string($message) && $message !== $key ? $message : $key;
    }

    /**
     * Libellé lisible d'un réglage, pris dans le bloc `attributes` partagé avec
     * tous les messages de validation du dépôt.
     */
    private static function attributeLabel(string $field): string
    {
        $label = trans('validation.attributes.'.$field);

        return is_string($label) && $label !== 'validation.attributes.'.$field ? $label : $field;
    }
}
