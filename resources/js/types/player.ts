/**
 * Identité affichée d'un siège (spec 40 § 7, contrat C5) : miroir client
 * EXACT de `App\Support\Identity\PlayerIdentity::toArray()` et
 * d'`App\Avatars\AvatarRef::toArray()`.
 *
 * Seule déclaration de ces types côté client (R-27) : `SeatView` de la
 * spec 60 étend `PlayerIdentity`, le podium de la spec 80 l'embarque ; aucun
 * ne les redéclare.
 *
 * Aucun identifiant interne : un siège s'adresse par son `publicId`, jamais
 * par `player.id`. Aucune chaîne traduite non plus : `altKey` est une CLÉ
 * `common.avatar.alt.*`, rendue par `avatarAltKey()` (`lib/game/avatar-keys`).
 */

/**
 * Avatar effectif, résolu par l'accesseur serveur unique : prédéfini, puis
 * initiales (`kind` nul). `initials` est toujours rempli — contenu de repli
 * de l'image —, et vaut le caractère neutre pour un siège masqué.
 */
export type AvatarData = {
    kind: 'preset' | 'provider' | null;
    url: string | null;
    altKey: string;
    initials: string;
};

/**
 * L'identité d'un siège, diffusée au salon, identique pour tous. Au jalon 1,
 * `masked` vaut toujours `false` ; `nickname` nul = pseudo masqué ou effacé
 * par l'archivage.
 */
export type PlayerIdentity = {
    /** `char(12)` base32, jamais dérivé de l'identifiant de base. */
    publicId: string;
    nickname: string | null;
    masked: boolean;
    avatar: AvatarData;
};

/**
 * Clés du catalogue des avatars prédéfinis, miroir d'`AvatarPresetCatalog::keys()`
 * aux valeurs par défaut. `AvatarPresetTest` compare cette union au catalogue
 * serveur, et `tsc` exige une ligne par clé dans `AVATAR_PRESET_LABEL_KEYS` :
 * une clé ajoutée d'un seul côté casse la CI.
 */
export type AvatarPresetKey =
    | 'preset-01'
    | 'preset-02'
    | 'preset-03'
    | 'preset-04'
    | 'preset-05'
    | 'preset-06'
    | 'preset-07'
    | 'preset-08'
    | 'preset-09'
    | 'preset-10'
    | 'preset-11'
    | 'preset-12'
    | 'preset-13'
    | 'preset-14'
    | 'preset-15'
    | 'preset-16'
    | 'preset-17'
    | 'preset-18'
    | 'preset-19'
    | 'preset-20'
    | 'preset-21'
    | 'preset-22'
    | 'preset-23'
    | 'preset-24';

/**
 * Une option du sélecteur, miroir d'`AvatarPresetCatalog::options()`.
 * `labelKey` est une chaîne du serveur : le client nomme l'option par
 * `AVATAR_PRESET_LABEL_KEYS[key]`, typée, jamais par une clé reçue.
 */
export type AvatarPresetOption = {
    key: AvatarPresetKey;
    url: string;
    labelKey: string;
};
