<?php

namespace App\Settings;

use Illuminate\Validation\ValidationException;

/**
 * Éditeur des réglages de salon, par onglet (contrat C0, spec 50 § 3).
 *
 * Fonction pure : il transforme une charge postée par l'onglet Simple ou Avancé en
 * entrée complète de {@see RoomSettings::fromInput()}, composée avec l'état
 * courant du salon. Les deux onglets sont deux vues d'un SEUL objet de réglages.
 * Il ne lit ni la base ni l'horloge : les thèmes publiés lui sont passés par
 * l'appelant (`PoolQuery::publishedThemeIdsByKey()`, contrat C2), et la seule
 * valeur de plateforme qu'il consulte est le plafond de capacité
 * ({@see PlatformLimits::roomSeats()}).
 *
 * **Règles de l'entrée (§ 3.1).**
 * - Toute clé hors de la liste de l'onglet est refusée par
 *   `validation.room_settings.not_editable`, sous la clé du champ : sans ce
 *   refus, un client scripté poserait depuis l'onglet Simple un barème que
 *   l'interface n'y montre pas, puisque `fromInput()` accepte les seize champs.
 *   Un champ posté en snake_case, ou `themeIds`, est une clé hors liste comme
 *   une autre ; `roundDuration` l'est pour l'onglet Avancé, où `D` est la
 *   somme des paliers.
 * - `themeKeys` est traduit en `themeIds` ; une clé inconnue ou dépubliée donne
 *   `validation.room_settings.theme_keys`. Aucun identifiant de thème ne vient
 *   du client (spec 10 § 1.1).
 * - L'éditeur ne coerce rien : une valeur non entière est transmise telle
 *   quelle, et `fromInput()` la refuse. Il n'ajuste jamais `D` ni `N` : quand
 *   `N` augmente, c'est le client qui remonte `D` et l'annonce (§ 4.3).
 *
 * **Règle Simple (D34 du 23/09, § 3.2).** Une valeur dérivée — découpage des
 * paliers, barème, `attemptsPerRound` — suit `N` et `D` tant qu'elle vaut encore
 * le défaut dérivé de l'ancien couple ; sinon elle est conservée, sauf le
 * barème quand `N` change (sa taille change) et les paliers, toujours
 * réégalisés. Ces deux derniers cas sont rapportés (`reset`, `equalized`).
 *
 * **Onglet Avancé (§ 3.3, lot L50-10).** Rien n'y est dérivé : quand `N`
 * change, le client poste les deux listes redimensionnées, et une liste de
 * mauvaise taille est refusée (`list_size`). Repasser en Simple
 * (`advanced: false`) réégalise les paliers et conserve tout le reste.
 */
final readonly class RoomSettingsEditor
{
    /** Clés postées par l'onglet Simple, seul onglet au J1. `themeKeys` devient `themeIds`. */
    public const array SIMPLE_KEYS = [
        'themeKeys',
        'roundsCount',
        'framesPerRound',
        'roundDuration',
        'revealDuration',
        'inputDifficulty',
        'capacity',
        'allowLateJoin',
        'advanced',
    ];

    /** Clés postées par l'onglet Avancé (L50-10) : `D = Σ tierDurations`, donc pas de `roundDuration`. */
    public const array ADVANCED_KEYS = [
        'themeKeys',
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
     * Onglet Avancé livré ou non — constante de CODE, jamais un drapeau de
     * configuration : elle est passée à `true` par un commit du lot L50-10
     * [J2], jamais à l'exécution. Ce n'est donc pas un « feature flag ».
     */
    public const bool ADVANCED_TAB_AVAILABLE = true;

    /** Clé client de la sélection de thèmes ; le stockage reste en `themeIds`. */
    public const string THEME_KEYS = 'themeKeys';

    /** Clé de stockage de la sélection de thèmes, jamais exposée au client (E10-11). */
    private const string THEME_IDS = 'themeIds';

    /** Sélecteur d'onglet, champ n° 16 du value object. */
    private const string ADVANCED = 'advanced';

    /**
     * Onglet Simple : la charge postée, composée avec l'état courant selon la
     * règle D34 du 23/09.
     *
     * ```
     * input = current.toPayload() privé de themeIds et de tierDurations
     *       ⊕ posted (hors themeKeys)
     *       ⊕ { themeIds: map(posted.themeKeys) ?? current.themeIds, advanced: false,
     *           roundDuration: D₁, tierPoints: …, attemptsPerRound: … }
     * ```
     *
     * Seul ajout au calcul du contrat (§ 3.2, § 10) : une capacité NON postée
     * au-dessus de {@see PlatformLimits::roomSeats()} — plafond de plateforme
     * abaissé par configuration — est ramenée à ce plafond et rapportée
     * `clamped`. Sans ce rattrapage, `fromInput()` refuserait sous `capacity`
     * toute écriture de réglages du salon.
     *
     * @param  array<string, mixed>  $posted  Corps de la requête, clés camelCase.
     * @param  array<string, int>  $publishedThemeIdsByKey  clé de thème publiée → id, fourni par PoolQuery::publishedThemeIdsByKey() (C2)
     * @return array{input: array<string, mixed>, changes: array<string, string>}
     *
     * @throws ValidationException Clé hors de l'onglet, `advanced: true`, clé de thème inconnue.
     */
    public static function simple(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array
    {
        $errors = [];

        foreach (array_keys($posted) as $key) {
            $field = (string) $key;

            if (! in_array($field, self::SIMPLE_KEYS, true)) {
                $errors[$field][] = self::notEditable($field);
            }
        }

        // L'onglet Simple ne sait poser que `advanced: false` : `true` est une
        // bascule vers l'onglet Avancé, que l'aiguillage (`edit()`) route vers
        // `advanced()` : elle n'atteint `simple()` que par un appel direct.
        if (array_key_exists(self::ADVANCED, $posted) && self::readBool($posted[self::ADVANCED]) === true) {
            $errors[self::ADVANCED][] = self::notEditable(self::ADVANCED);
        }

        $themeIds = null;

        if (array_key_exists(self::THEME_KEYS, $posted)) {
            $themeIds = self::themeIds($posted[self::THEME_KEYS], $publishedThemeIdsByKey);

            if ($themeIds === null) {
                $errors[self::THEME_KEYS][] = self::message(
                    'validation.room_settings.theme_keys',
                    self::THEME_KEYS,
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $framesBefore = $current->framesPerRound;
        $durationBefore = $current->roundDuration();

        // N₁ et D₁ LUS pour les dérivations seulement ; l'entrée reçoit la valeur
        // postée telle quelle. Une valeur illisible n'a pas de dérivé : le champ
        // dérivé est alors omis, et `fromInput()` ne refuse que le champ fautif.
        $framesAfter = array_key_exists('framesPerRound', $posted)
            ? self::readInt($posted['framesPerRound'])
            : $framesBefore;
        $durationAfter = array_key_exists(RoomSettings::INPUT_ROUND_DURATION, $posted)
            ? self::readInt($posted[RoomSettings::INPUT_ROUND_DURATION])
            : $durationBefore;

        $input = $current->toPayload();
        unset($input[self::THEME_IDS], $input['tierDurations']);

        foreach ($posted as $key => $value) {
            if ((string) $key !== self::THEME_KEYS) {
                $input[(string) $key] = $value;
            }
        }

        $input[self::THEME_IDS] = $themeIds ?? $current->themeIds;

        // `advanced: false`, sauf une valeur illisible, transmise pour être refusée.
        $input[self::ADVANCED] = array_key_exists(self::ADVANCED, $posted) && self::readBool($posted[self::ADVANCED]) === null
            ? $posted[self::ADVANCED]
            : false;

        // `fromInput()` dérive le découpage égal de (N₁, D₁), le dernier palier
        // absorbant le reste : les paliers sont toujours réégalisés en Simple.
        $input[RoomSettings::INPUT_ROUND_DURATION] = array_key_exists(RoomSettings::INPUT_ROUND_DURATION, $posted)
            ? $posted[RoomSettings::INPUT_ROUND_DURATION]
            : $durationBefore;

        $defaultPointsBefore = RoomSettingsBounds::defaultTierPoints($framesBefore);

        if ($framesAfter === null) {
            unset($input['tierPoints']);
        } else {
            $input['tierPoints'] = $framesAfter !== $framesBefore || $current->tierPoints === $defaultPointsBefore
                ? RoomSettingsBounds::defaultTierPoints($framesAfter)
                : $current->tierPoints;
        }

        if ($durationAfter === null) {
            unset($input['attemptsPerRound']);
        } else {
            $input['attemptsPerRound'] = $current->attemptsPerRound === RoomSettingsBounds::defaultAttemptsPerRound($durationBefore)
                ? RoomSettingsBounds::defaultAttemptsPerRound($durationAfter)
                : $current->attemptsPerRound;
        }

        $changes = [];

        if ($current->tierDurations !== RoomSettingsBounds::defaultTierDurations($framesBefore, $durationBefore)) {
            $changes['tierDurations'] = RoomSettings::CHANGE_EQUALIZED;
        }

        if ($framesAfter !== null && $framesAfter !== $framesBefore && $current->tierPoints !== $defaultPointsBefore) {
            $changes['tierPoints'] = RoomSettings::CHANGE_RESET;
        }

        if (! array_key_exists('capacity', $posted) && $current->capacity > PlatformLimits::roomSeats()) {
            $input['capacity'] = PlatformLimits::roomSeats();
            $changes['capacity'] = RoomSettings::CHANGE_CLAMPED;
        }

        return ['input' => $input, 'changes' => $changes];
    }

    /**
     * Onglet Avancé (§ 3.3, lot L50-10) : même signature et même traduction des
     * thèmes que {@see self::simple()}, mais **rien n'y est dérivé**.
     *
     * ```
     * input = current.toPayload() privé de themeIds
     *       ⊕ posted (hors themeKeys)
     *       ⊕ { themeIds: map(posted.themeKeys) ?? current.themeIds, advanced: true }
     * changes:
     *   tierPoints ↦ 'reset'  si N₁ ≠ N₀ ∧ current.tierPoints ≠ defaultTierPoints(N₀)
     * ```
     *
     * - `roundDuration` y est hors liste (`not_editable`) : `D` devient
     *   `Σ tierDurations` (10 § 6.1).
     * - Quand `N` change, c'est le client qui poste les deux listes
     *   redimensionnées ; une liste de mauvaise taille est refusée par
     *   `fromInput()` (`list_size`) : le serveur n'invente aucune durée.
     * - `advanced: false` n'y arrive jamais par l'aiguillage
     *   ({@see self::edit()} le route vers {@see self::simple()}) ; posté
     *   directement, il est refusé (`not_editable`), comme `advanced: true`
     *   par l'onglet Simple.
     * - Même rattrapage de capacité que l'onglet Simple : une capacité NON
     *   postée au-dessus du plafond de plateforme abaissé est ramenée à ce
     *   plafond et rapportée `clamped`.
     *
     * @param  array<string, mixed>  $posted
     * @param  array<string, int>  $publishedThemeIdsByKey
     * @return array{input: array<string, mixed>, changes: array<string, string>}
     *
     * @throws ValidationException Clé hors de l'onglet, `advanced: false`, clé de thème inconnue.
     */
    public static function advanced(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array
    {
        $errors = [];

        foreach (array_keys($posted) as $key) {
            $field = (string) $key;

            if (! in_array($field, self::ADVANCED_KEYS, true)) {
                $errors[$field][] = self::notEditable($field);
            }
        }

        if (array_key_exists(self::ADVANCED, $posted) && self::readBool($posted[self::ADVANCED]) === false) {
            $errors[self::ADVANCED][] = self::notEditable(self::ADVANCED);
        }

        $themeIds = null;

        if (array_key_exists(self::THEME_KEYS, $posted)) {
            $themeIds = self::themeIds($posted[self::THEME_KEYS], $publishedThemeIdsByKey);

            if ($themeIds === null) {
                $errors[self::THEME_KEYS][] = self::message(
                    'validation.room_settings.theme_keys',
                    self::THEME_KEYS,
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $framesBefore = $current->framesPerRound;
        $framesAfter = array_key_exists('framesPerRound', $posted)
            ? self::readInt($posted['framesPerRound'])
            : $framesBefore;

        $input = $current->toPayload();
        unset($input[self::THEME_IDS]);

        foreach ($posted as $key => $value) {
            if ((string) $key !== self::THEME_KEYS) {
                $input[(string) $key] = $value;
            }
        }

        $input[self::THEME_IDS] = $themeIds ?? $current->themeIds;

        // `advanced: true`, sauf une valeur illisible, transmise pour être refusée.
        $input[self::ADVANCED] = array_key_exists(self::ADVANCED, $posted) && self::readBool($posted[self::ADVANCED]) === null
            ? $posted[self::ADVANCED]
            : true;

        $changes = [];

        if ($framesAfter !== null && $framesAfter !== $framesBefore
            && $current->tierPoints !== RoomSettingsBounds::defaultTierPoints($framesBefore)) {
            $changes['tierPoints'] = RoomSettings::CHANGE_RESET;
        }

        if (! array_key_exists('capacity', $posted) && $current->capacity > PlatformLimits::roomSeats()) {
            $input['capacity'] = PlatformLimits::roomSeats();
            $changes['capacity'] = RoomSettings::CHANGE_CLAMPED;
        }

        return ['input' => $input, 'changes' => $changes];
    }

    /**
     * Les réglages propres à l'onglet Avancé (`ADVANCED_KEYS` hors
     * `SIMPLE_KEYS`) qui s'écartent de leur défaut, dans l'ordre de `FIELDS`.
     *
     * « Défaut » veut dire le défaut DÉRIVÉ du couple courant `(N, D)` pour les
     * champs dérivés — découpage égal des paliers, barème par défaut,
     * `attemptsPerRound` dérivé de `D` —, et le défaut constant de
     * {@see RoomSettingsBounds} pour les autres (§ 3.3, § 5.3).
     *
     * Deux lecteurs : le bandeau `room.settings.advanced_active` de l'onglet
     * Simple (`advancedActive` de `RoomSettingsState`, § 2.6) et le rapport
     * `overwritten` d'un preset ({@see self::overwritten()}).
     *
     * @return list<string>
     */
    public static function customizedAdvancedFields(RoomSettings $settings): array
    {
        $framesPerRound = $settings->framesPerRound;
        $roundDuration = $settings->roundDuration();
        $defaults = [
            'tierDurations' => RoomSettingsBounds::defaultTierDurations($framesPerRound, $roundDuration),
            'tierPoints' => RoomSettingsBounds::defaultTierPoints($framesPerRound),
            'speedBonus' => RoomSettingsBounds::DEFAULT_SPEED_BONUS,
            'noRepeatMovies' => RoomSettingsBounds::DEFAULT_NO_REPEAT_MOVIES,
            'attemptsPerSecond' => RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND,
            'attemptsPerRound' => RoomSettingsBounds::defaultAttemptsPerRound($roundDuration),
            'maxAnswerLength' => RoomSettingsBounds::DEFAULT_ANSWER_LENGTH,
            'disconnectGraceSeconds' => RoomSettingsBounds::DEFAULT_DISCONNECT_GRACE_SECONDS,
        ];
        $payload = $settings->toPayload();
        $customized = [];

        foreach (self::advancedOnlyKeys() as $field) {
            if (array_key_exists($field, $defaults) && $payload[$field] !== $defaults[$field]) {
                $customized[] = $field;
            }
        }

        return $customized;
    }

    /**
     * Rapport `overwritten` d'une écriture qui remplace tout l'objet (preset,
     * § 5.3 ; configuration chargée, § 18.3) : chaque réglage propre à l'onglet
     * Avancé, personnalisé dans l'état courant et changé par la nouvelle
     * valeur. Les champs de l'onglet Simple sont visibles à l'écran et ne sont
     * jamais rapportés ; sans ce rapport, l'écrasement d'un réglage invisible
     * serait silencieux.
     *
     * @return array<string, string>
     */
    public static function overwritten(RoomSettings $current, RoomSettings $next): array
    {
        $before = $current->toPayload();
        $after = $next->toPayload();
        $changes = [];

        foreach (self::customizedAdvancedFields($current) as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = RoomSettings::CHANGE_OVERWRITTEN;
            }
        }

        return $changes;
    }

    /**
     * L'onglet que choisit une charge postée : la clé `advanced` du corps, ou
     * l'onglet courant quand elle est absente (§ 3.1). `advanced: true` part
     * vers {@see self::advanced()} ; `advanced: false` — la bascule Avancé →
     * Simple — vers {@see self::simple()}, qui réégalise les paliers. Une
     * valeur illisible part vers l'onglet Simple, qui la transmet pour
     * qu'elle soit refusée (`boolean`).
     *
     * @param  array<string, mixed>  $posted
     * @param  array<string, int>  $publishedThemeIdsByKey
     * @return array{input: array<string, mixed>, changes: array<string, string>}
     *
     * @throws ValidationException
     */
    public static function edit(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array
    {
        $advanced = array_key_exists(self::ADVANCED, $posted)
            ? self::readBool($posted[self::ADVANCED]) === true
            : $current->advanced;

        return $advanced && self::advancedTabAvailable()
            ? self::advanced($current, $posted, $publishedThemeIdsByKey)
            : self::simple($current, $posted, $publishedThemeIdsByKey);
    }

    /**
     * `fromInput()` sur l'entrée composée, les erreurs de `themeIds` réindexées
     * sous `themeKeys` (§ 3.1) : le client n'a jamais posté `themeIds`, et une
     * erreur sous une clé qu'il ne connaît pas ne se lierait à aucun champ.
     * Les erreurs par palier restent `tierDurations.{i}` et `tierPoints.{i}`.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function toSettings(array $input): RoomSettings
    {
        try {
            return RoomSettings::fromInput($input);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = $field === self::THEME_IDS || str_starts_with($field, self::THEME_IDS.'.')
                    ? self::THEME_KEYS.substr($field, strlen(self::THEME_IDS))
                    : $field;

                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Lecture de {@see self::ADVANCED_TAB_AVAILABLE} derrière un type `bool` :
     * une condition écrite sur la constante seule serait lue comme toujours
     * vraie par l'analyse statique.
     */
    private static function advancedTabAvailable(): bool
    {
        return self::ADVANCED_TAB_AVAILABLE;
    }

    /**
     * Clés propres à l'onglet Avancé, dans l'ordre de `FIELDS`.
     *
     * @return list<string>
     */
    private static function advancedOnlyKeys(): array
    {
        return array_values(array_diff(self::ADVANCED_KEYS, self::SIMPLE_KEYS));
    }

    /**
     * `themeKeys` → `themeIds`, dans l'ordre posté et sans doublon ; `null` si la
     * valeur n'est pas une liste de clés publiées. Une liste vide vide la
     * sélection (remède `clear_themes`, § 9.2).
     *
     * @param  array<string, int>  $publishedThemeIdsByKey
     * @return list<int>|null
     */
    private static function themeIds(mixed $keys, array $publishedThemeIdsByKey): ?array
    {
        if (! is_array($keys) || ! array_is_list($keys)) {
            return null;
        }

        $ids = [];

        foreach ($keys as $key) {
            if (! is_string($key) || ! array_key_exists($key, $publishedThemeIdsByKey)) {
                return null;
            }

            $ids[] = $publishedThemeIdsByKey[$key];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Même lecture qu'`RoomSettings::fromInput()` : un entier, ou sa forme
     * décimale postée par un formulaire (`"30"`, jamais `"30.5"`).
     */
    private static function readInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Même lecture qu'`RoomSettings::fromInput()` : booléen, `0`/`1`, `"0"`,
     * `"1"`, `"true"`, `"false"` ; `null` pour toute autre valeur.
     */
    private static function readBool(mixed $value): ?bool
    {
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

    private static function notEditable(string $field): string
    {
        return self::message('validation.room_settings.not_editable', $field);
    }

    /**
     * Message à destinataire unique, résolu dans la langue de la requête de
     * l'hôte (05 § Erreurs, validation et messages à destinataire unique) ;
     * `:attribute` reçoit le libellé du champ (`validation.attributes.<champ>`),
     * ou son nom brut pour une clé qui n'en a pas.
     */
    private static function message(string $key, string $field): string
    {
        $label = trans('validation.attributes.'.$field);
        $message = trans($key, [
            'attribute' => is_string($label) && $label !== 'validation.attributes.'.$field ? $label : $field,
        ]);

        return is_string($message) ? $message : $key;
    }
}
