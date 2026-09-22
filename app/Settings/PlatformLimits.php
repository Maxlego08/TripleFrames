<?php

namespace App\Settings;

use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Config;

/**
 * Plafonds de plateforme — jamais réglables par un hôte.
 *
 * Domicile unique des valeurs que le produit impose et que personne ne négocie :
 * elles sont construites depuis `config/`, jamais depuis une colonne, et jamais
 * écrites en littéral dans une migration ni dans un FormRequest (règle 2).
 *
 * Pourquoi `tierGraceMs`, `preloadLeadMs` et `speedBonusMaxFraction` vivent ici et
 * non dans {@see RoomSettings} : l'unique constructeur valide de `RoomSettings` est
 * alimenté par le formulaire de l'hôte. Un hôte qui posterait `graceMs: 120000`
 * s'achèterait le palier 1 pour toute la manche, et la borne ne tiendrait plus que
 * par un validateur au lieu d'être structurellement inatteignable. Les trois autres
 * porteurs du value object (`room`, `setting_preset`, `saved_config`) sont des objets
 * d'hôte éditables et sauvegardables : y loger une constante serveur la rendrait
 * réglable par la voie du JSON. Leur domicile de persistance est une colonne de
 * `game`, écrite au lancement (`game.tier_grace_ms`, `game.preload_lead_ms`).
 *
 * Ces valeurs ne sont pas « sans version » : `guess.points_bonus` est écrit sous
 * `speedBonusMaxFraction` et `guess.tier_index` sous `tierGraceMs`, tous deux
 * conservés douze mois. Toute modification de l'une d'elles incrémente
 * `game.scoring_version`.
 */
final readonly class PlatformLimits
{
    /** Configurations sauvegardées par compte (§ 6.3). */
    public const int DEFAULT_SAVED_CONFIGS_PER_USER = 20;

    /** Sièges d'un salon : plafond absolu dont dérive la borne haute de `capacity`. */
    public const int DEFAULT_ROOM_SEATS = 12;

    /** Avatars prédéfinis livrés en statique dans `public/`. */
    public const int DEFAULT_AVATAR_PRESETS = 24;

    /** Fenêtre glissante des faits de partie, en mois. */
    public const int DEFAULT_HISTORY_WINDOW_MONTHS = 12;

    /** Manches minimales avant d'afficher un taux de réussite. */
    public const int DEFAULT_SUCCESS_RATE_MIN_ROUNDS = 20;

    /** `B_max` : fraction maximale du palier attribuable au bonus de rapidité. */
    public const float DEFAULT_SPEED_BONUS_MAX_FRACTION = 0.50;

    /** Tolérance aux frontières de palier, en millisecondes (constante serveur). */
    public const int DEFAULT_TIER_GRACE_MS = 300;

    /** Avance de signature d'une URL d'image sur l'ouverture de son palier. */
    public const int DEFAULT_PRELOAD_LEAD_MS = 2000;

    /** Borne basse recommandée de `preloadLeadMs` (§ 10). */
    public const int MIN_PRELOAD_LEAD_MS = 1500;

    /** Borne haute recommandée de `preloadLeadMs` — jamais la durée d'un palier. */
    public const int MAX_PRELOAD_LEAD_MS = 2500;

    /**
     * Plafond d'ENTRÉE d'un téléversement d'image, en kilooctets.
     *
     * Très en dessous d'`upload_max_filesize = 2M`, pour qu'un dépassement soit
     * toujours une erreur de validation traduite et jamais un 419 muet : dépasser
     * `post_max_size` vide `$_POST`, donc le jeton CSRF. Distinct des deux plafonds
     * de SORTIE, qui sont une propriété du préfixe de stockage
     * ({@see FrameStoragePrefix}).
     */
    public const int DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES = 1536;

    /** Préfixe de configuration sous lequel chaque plafond est surchargeable. */
    private const string CONFIG_PREFIX = 'game.platform.';

    /**
     * @param  int  $savedConfigsPerUser  Configurations sauvegardées par compte.
     * @param  int  $roomSeats  Sièges d'un salon.
     * @param  int  $avatarPresets  Avatars prédéfinis livrés par le site.
     * @param  int  $historyWindowMonths  Fenêtre glissante des faits de partie, en mois.
     * @param  int  $successRateMinRounds  Manches minimales avant d'afficher un taux.
     * @param  float  $speedBonusMaxFraction  `B_max`, fraction du palier.
     * @param  int  $tierGraceMs  Tolérance aux frontières de palier, en millisecondes.
     * @param  int  $preloadLeadMs  Avance de signature d'une URL d'image, en millisecondes.
     * @param  int  $frameUploadMaxKilobytes  Plafond d'entrée d'un téléversement d'image.
     */
    public function __construct(
        public int $savedConfigsPerUser,
        public int $roomSeats,
        public int $avatarPresets,
        public int $historyWindowMonths,
        public int $successRateMinRounds,
        public float $speedBonusMaxFraction,
        public int $tierGraceMs,
        public int $preloadLeadMs,
        public int $frameUploadMaxKilobytes,
    ) {}

    /**
     * Instance complète, pour l'injection et pour le partage en prop Inertia.
     */
    public static function fromConfig(): self
    {
        return new self(
            savedConfigsPerUser: self::savedConfigsPerUser(),
            roomSeats: self::roomSeats(),
            avatarPresets: self::avatarPresets(),
            historyWindowMonths: self::historyWindowMonths(),
            successRateMinRounds: self::successRateMinRounds(),
            speedBonusMaxFraction: self::speedBonusMaxFraction(),
            tierGraceMs: self::tierGraceMs(),
            preloadLeadMs: self::preloadLeadMs(),
            frameUploadMaxKilobytes: self::frameUploadMaxKilobytes(),
        );
    }

    public static function savedConfigsPerUser(): int
    {
        return self::integer('saved_configs_per_user', self::DEFAULT_SAVED_CONFIGS_PER_USER);
    }

    public static function roomSeats(): int
    {
        return self::integer('room_seats', self::DEFAULT_ROOM_SEATS);
    }

    public static function avatarPresets(): int
    {
        return self::integer('avatar_presets', self::DEFAULT_AVATAR_PRESETS);
    }

    public static function historyWindowMonths(): int
    {
        return self::integer('history_window_months', self::DEFAULT_HISTORY_WINDOW_MONTHS);
    }

    public static function successRateMinRounds(): int
    {
        return self::integer('success_rate_min_rounds', self::DEFAULT_SUCCESS_RATE_MIN_ROUNDS);
    }

    /**
     * `B_max` : l'hôte n'a qu'un interrupteur on/off, jamais un curseur.
     */
    public static function speedBonusMaxFraction(): float
    {
        return Config::float(
            self::CONFIG_PREFIX.'speed_bonus_max_fraction',
            self::DEFAULT_SPEED_BONUS_MAX_FRACTION,
        );
    }

    /**
     * Constante serveur figée au lancement dans `game.tier_grace_ms`.
     *
     * À ne jamais confondre avec `disconnectGraceSeconds`, réglage d'hôte de 15 à
     * 180 s : quatre ordres de grandeur les séparent.
     */
    public static function tierGraceMs(): int
    {
        return self::integer('tier_grace_ms', self::DEFAULT_TIER_GRACE_MS);
    }

    /**
     * Constante serveur figée au lancement dans `game.preload_lead_ms`.
     *
     * Jamais la durée d'un palier : à `lead = dᵢ`, le tricheur obtient un palier
     * entier d'avance et l'image du palier 1 reste lisible pendant toute la
     * révélation précédente.
     */
    public static function preloadLeadMs(): int
    {
        return self::integer('preload_lead_ms', self::DEFAULT_PRELOAD_LEAD_MS);
    }

    public static function frameUploadMaxKilobytes(): int
    {
        return self::integer('frame_upload_max_kilobytes', self::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES);
    }

    /**
     * Représentation en données, pour une prop Inertia ou un export.
     *
     * @return array{savedConfigsPerUser: int, roomSeats: int, avatarPresets: int, historyWindowMonths: int, successRateMinRounds: int, speedBonusMaxFraction: float, tierGraceMs: int, preloadLeadMs: int, frameUploadMaxKilobytes: int}
     */
    public function toArray(): array
    {
        return [
            'savedConfigsPerUser' => $this->savedConfigsPerUser,
            'roomSeats' => $this->roomSeats,
            'avatarPresets' => $this->avatarPresets,
            'historyWindowMonths' => $this->historyWindowMonths,
            'successRateMinRounds' => $this->successRateMinRounds,
            'speedBonusMaxFraction' => $this->speedBonusMaxFraction,
            'tierGraceMs' => $this->tierGraceMs,
            'preloadLeadMs' => $this->preloadLeadMs,
            'frameUploadMaxKilobytes' => $this->frameUploadMaxKilobytes,
        ];
    }

    private static function integer(string $key, int $default): int
    {
        return Config::integer(self::CONFIG_PREFIX.$key, $default);
    }
}
