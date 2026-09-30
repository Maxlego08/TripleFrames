<?php

namespace App\Settings;

use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Plafonds de plateforme — jamais réglables par un hôte (contrat C0, spec 50 § 2.3).
 *
 * Domicile unique des valeurs que le produit impose et que personne ne négocie :
 * elles sont construites depuis `config/game.php › platform`, section lue
 * EXCLUSIVEMENT ici, jamais depuis une colonne, et jamais écrites en littéral dans
 * une migration ni dans un FormRequest (règle 2).
 *
 * **Constantes d'instance, jamais résolues par compte.** Aucun accesseur ne reçoit
 * un `User`, un plan ou un siège (décision 2, neutralité de plan) : un plan payant
 * futur ne trouverait ici aucun point d'accroche, et c'est voulu.
 *
 * **Une seule garde, sur tous les chemins.** Chaque accesseur statique délègue à
 * l'instance construite par {@see self::fromConfig()}, mémoïsée DANS LE CONTENEUR
 * (`scoped`, `AppServiceProvider`) et jamais dans une propriété statique : une
 * propriété statique survivrait d'un test à l'autre dans le même processus Pest,
 * et un test qui pose `config()->set()` lirait la valeur d'un test précédent. Le
 * constructeur refuse toute valeur hors bornes (`InvalidArgumentException`) : une
 * configuration fautive fait donc échouer tout accesseur, jamais un seul en silence.
 *
 * Familles (quinze valeurs sans argument, plus `B_max` fonction de `N`) :
 * - **confort** (surchargeable) : configurations sauvegardées, sièges, avatars,
 *   fenêtre d'historique, seuil du taux de réussite, plafond de téléversement ;
 * - **règle figée sur `game` au lancement, NON surchargeable** : `tierGraceMs` et
 *   `preloadLeadMs`. Elles entrent dans le constructeur par leur constante, jamais
 *   par la configuration : une surcharge changerait le palier retenu, la fenêtre
 *   de service et donc le score sans qu'aucune version de règle ne le trace. Elles
 *   sont figées par partie dans `game.tier_grace_ms` et `game.preload_lead_ms` ;
 * - **règle non persistée, NON surchargeable** : `B_max(N)`, pourcentage entier
 *   ({@see self::speedBonusMaxPercent()}, D22 du 23/09). L'hôte n'a qu'un
 *   interrupteur (`speedBonus`), jamais un curseur ; la formule du bonus appartient
 *   à la spec 80 ;
 * - **tirage et mémoire** (surchargeable, valeurs déclarées par la spec 30) : leur
 *   effet est matérialisé en lignes `round` ou recalculé à l'identique ;
 * - **lobby** (surchargeable) : anti-rebond de la diffusion de l'état du salon ;
 * - **curation** (surchargeable, valeurs déclarées par la spec 20) : plancher de
 *   recadrage de D6 du 23/09. Jamais exposés en prop joueur (R-07).
 *
 * **Ces valeurs ne sont pas « sans version ».** `guess.points_bonus` est écrit sous
 * `speedBonusMaxPercent(N)`, `guess.tier_index` sous `tierGraceMs`, et un palier est
 * servi sous `preloadLeadMs` — trois faits conservés douze mois. Tout changement de
 * la table `B_max(N)` ou du DÉFAUT de `tierGraceMs` ou de `preloadLeadMs` incrémente
 * la version de la règle de score (`ScoringRules::VERSION`, donc
 * `game.scoring_version`, spec 80 § 6.1). Les valeurs de tirage et de mémoire
 * n'incrémentent aucune version.
 */
final readonly class PlatformLimits
{
    /** Configurations sauvegardées par compte (10 § 6.3). */
    public const int DEFAULT_SAVED_CONFIGS_PER_USER = 20;

    /** Sièges d'un salon : plafond absolu dont dérive la borne haute de `capacity`. */
    public const int DEFAULT_ROOM_SEATS = 12;

    /** Avatars prédéfinis livrés en statique dans `public/`. */
    public const int DEFAULT_AVATAR_PRESETS = 24;

    /**
     * Fenêtre glissante des faits de partie, en mois.
     *
     * C'est aussi le PLAFOND de la surcharge : la durée de conservation publiée est
     * un plafond, jamais un plancher (décision 19). Une surcharge ne peut que
     * raccourcir la fenêtre.
     */
    public const int DEFAULT_HISTORY_WINDOW_MONTHS = 12;

    /** Manches minimales avant d'afficher un taux de réussite. */
    public const int DEFAULT_SUCCESS_RATE_MIN_ROUNDS = 20;

    /**
     * Plafond d'ENTRÉE d'un téléversement d'image, en kilooctets.
     *
     * Très en dessous d'`upload_max_filesize = 2M`, pour qu'un dépassement soit
     * toujours une erreur de validation traduite et jamais un 419 muet : dépasser
     * `post_max_size` vide `$_POST`, donc le jeton CSRF. C'est aussi le PLAFOND de
     * la surcharge. Distinct des deux plafonds de SORTIE, qui sont une propriété du
     * préfixe de stockage ({@see FrameStoragePrefix}).
     */
    public const int DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES = 1536;

    /**
     * Tolérance aux frontières de palier, en millisecondes — constante de code.
     *
     * Non surchargeable : un changement de ce défaut incrémente la version de score.
     */
    public const int DEFAULT_TIER_GRACE_MS = 300;

    /**
     * Avance de signature d'une URL d'image sur l'ouverture de son palier, en
     * millisecondes — constante de code.
     *
     * Non surchargeable : un changement de ce défaut incrémente la version de score.
     */
    public const int DEFAULT_PRELOAD_LEAD_MS = 2000;

    /** Borne basse de `preloadLeadMs` (10 § 10). */
    public const int MIN_PRELOAD_LEAD_MS = 1500;

    /**
     * Borne haute de `preloadLeadMs` — jamais la durée d'un palier, et toujours sous
     * la révélation minimale (`RoomSettingsBounds::MIN_REVEAL_DURATION`).
     */
    public const int MAX_PRELOAD_LEAD_MS = 2500;

    /** Plafond de `B_max`, en pourcentage entier de la valeur du palier (D22 du 23/09). */
    public const int SPEED_BONUS_MAX_PERCENT_CAP = 50;

    /** L'unité : 100 %. Pas une valeur de jeu, c'est aussi la base du calcul du bonus. */
    public const int FULL_PERCENT = 100;

    /** Marge de films de réserve au-delà de `M`, comptée en œuvres (spec 30). */
    public const int DEFAULT_DRAW_SUBSTITUTE_MARGIN = 3;

    /**
     * Plus grand `round.sequence_index` représentable : la colonne est un
     * `unsignedTinyInteger` (10 § 7.4). Fait de schéma, pas une valeur de jeu.
     */
    public const int MAX_ROUND_SEQUENCE_INDEX = 255;

    /**
     * Borne haute de la marge : `M + marge` lignes `round` doivent tenir dans
     * `sequence_index`, sans quoi `MaterializeDraw` échouerait sous MySQL strict sur
     * un catalogue de plus de 255 œuvres, donc tout lancement.
     */
    public const int MAX_DRAW_SUBSTITUTE_MARGIN = self::MAX_ROUND_SEQUENCE_INDEX - RoomSettingsBounds::MAX_ROUNDS_COUNT;

    /**
     * Borne de fait de la marge sous la garde de clôture après pause du moteur
     * (garde-fou croisé, spec 60 § 19.1 et spec 50 § 2.7) :
     * `⌊pauseTimeoutMs ÷ launchCountdownMs⌋ − MAX_ROUNDS_COUNT`, aux défauts de
     * {@see EngineConstants} (150 aujourd'hui, sous les 225 de
     * {@see self::MAX_DRAW_SUBSTITUTE_MARGIN}).
     *
     * Chaque reprise après pause ajoute un décompte de lancement que
     * `total_paused_ms` ne compte pas ; la clôture après pause doit couvrir ceux de
     * toutes les manches, réserve comprise. Sans cette borne, une marge légale pour
     * la plateforme ferait lever tous les accesseurs du moteur. Lue aux DÉFAUTS et
     * non à la configuration du moteur, dont la garde lit elle-même cette marge :
     * une surcharge du moteur reste jugée par sa propre garde. Division entière
     * écrite par le reste, `intdiv()` n'étant pas admis dans une expression
     * constante.
     */
    public const int MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE = (EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS
        - EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS % EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS)
        / EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS
        - RoomSettingsBounds::MAX_ROUNDS_COUNT;

    /** Fenêtre de la mémoire du salon, en jours (spec 30, `RoomMemoryWindow`). */
    public const int DEFAULT_ROOM_MEMORY_WINDOW_DAYS = 90;

    /** Fenêtre de la mémoire du salon, en manches démarrées (spec 30, `RoomMemoryWindow`). */
    public const int DEFAULT_ROOM_MEMORY_WINDOW_ROUNDS = 500;

    /** Vivier catalogue minimal au-dessus duquel le sélecteur de thèmes s'affiche (spec 30). */
    public const int DEFAULT_THEME_SELECTOR_MIN_POOL = 150;

    /** Anti-rebond de la diffusion de l'état du lobby, en millisecondes. */
    public const int DEFAULT_LOBBY_BROADCAST_DEBOUNCE_MS = 300;

    public const int MAX_LOBBY_BROADCAST_DEBOUNCE_MS = 1000;

    /** Plancher de recadrage (D6 du 23/09) : largeur maximale du cadre, en % du master. */
    public const int DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT = 80;

    public const int MIN_FRAME_CROP_MAX_WIDTH_PERCENT = 70;

    public const int MAX_FRAME_CROP_MAX_WIDTH_PERCENT = 90;

    /** Largeur minimale d'un cadre de recadrage, en pixels du master. */
    public const int DEFAULT_FRAME_CROP_MIN_WIDTH_PX = 640;

    public const int MIN_FRAME_CROP_MIN_WIDTH_PX = 320;

    public const int MAX_FRAME_CROP_MIN_WIDTH_PX = 1280;

    /**
     * Pas de la largeur d'un cadre, en pixels : un multiple de 16 garde une hauteur
     * entière au ratio 16:9.
     */
    public const int FRAME_CROP_WIDTH_MULTIPLE_PX = 16;

    /** Préfixe de configuration sous lequel chaque valeur surchargeable est lue. */
    private const string CONFIG_PREFIX = 'game.platform.';

    /** Borne basse d'un compte, d'un seuil ou d'une fenêtre qui ne peut être nul. */
    private const int MIN_POSITIVE = 1;

    /** Borne basse d'une marge, d'un seuil ou d'un délai qui peut être nul. */
    private const int MIN_NON_NEGATIVE = 0;

    /** Millisecondes par seconde : convertit `MIN_TIER_DURATION`, exprimée en secondes. */
    private const int MILLISECONDS_PER_SECOND = 1000;

    /**
     * Les quinze valeurs sans argument, gardées ici et nulle part ailleurs.
     *
     * @param  int  $savedConfigsPerUser  Configurations sauvegardées par compte.
     * @param  int  $roomSeats  Sièges d'un salon, dans `[MIN_CAPACITY, avatarPresets]`.
     * @param  int  $avatarPresets  Avatars prédéfinis livrés par le site.
     * @param  int  $historyWindowMonths  Fenêtre glissante des faits de partie, en mois.
     * @param  int  $successRateMinRounds  Manches minimales avant d'afficher un taux.
     * @param  int  $frameUploadMaxKilobytes  Plafond d'entrée d'un téléversement d'image.
     * @param  int  $tierGraceMs  Tolérance aux frontières de palier, en millisecondes.
     * @param  int  $preloadLeadMs  Avance de signature d'une URL d'image, en millisecondes.
     * @param  int  $drawSubstituteMargin  Films de réserve au-delà de `M`, en œuvres.
     * @param  int  $roomMemoryWindowDays  Fenêtre de la mémoire du salon, en jours.
     * @param  int  $roomMemoryWindowRounds  Fenêtre de la mémoire du salon, en manches.
     * @param  int  $themeSelectorMinPool  Seuil d'affichage du sélecteur de thèmes.
     * @param  int  $lobbyBroadcastDebounceMs  Anti-rebond de la diffusion du lobby.
     * @param  int  $frameCropMaxWidthPercent  Largeur maximale d'un cadre, en % du master.
     * @param  int  $frameCropMinWidthPx  Largeur minimale d'un cadre, en pixels du master.
     *
     * @throws InvalidArgumentException Une valeur hors de ses bornes.
     */
    public function __construct(
        public int $savedConfigsPerUser,
        public int $roomSeats,
        public int $avatarPresets,
        public int $historyWindowMonths,
        public int $successRateMinRounds,
        public int $frameUploadMaxKilobytes,
        public int $tierGraceMs,
        public int $preloadLeadMs,
        public int $drawSubstituteMargin,
        public int $roomMemoryWindowDays,
        public int $roomMemoryWindowRounds,
        public int $themeSelectorMinPool,
        public int $lobbyBroadcastDebounceMs,
        public int $frameCropMaxWidthPercent,
        public int $frameCropMinWidthPx,
    ) {
        self::assertAtLeast('savedConfigsPerUser', $savedConfigsPerUser, self::MIN_POSITIVE);
        self::assertAtLeast('avatarPresets', $avatarPresets, self::MIN_POSITIVE);
        // Un siège sans avatar prédéfini distinct ferait partager un avatar à deux
        // joueurs du même salon (spec 40, contrat C5 I5.7).
        self::assertBetween('roomSeats', $roomSeats, RoomSettingsBounds::MIN_CAPACITY, $avatarPresets);
        self::assertBetween('historyWindowMonths', $historyWindowMonths, self::MIN_POSITIVE, self::DEFAULT_HISTORY_WINDOW_MONTHS);
        self::assertAtLeast('successRateMinRounds', $successRateMinRounds, self::MIN_POSITIVE);
        self::assertBetween('frameUploadMaxKilobytes', $frameUploadMaxKilobytes, self::MIN_POSITIVE, self::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES);
        self::assertTierGrace($tierGraceMs);
        self::assertBetween('preloadLeadMs', $preloadLeadMs, self::MIN_PRELOAD_LEAD_MS, self::MAX_PRELOAD_LEAD_MS);
        // Une seule garde, sous la plus basse des deux bornes : le message d'une
        // marge fautive annonce la borne qui gouverne réellement.
        self::assertBetween(
            'drawSubstituteMargin',
            $drawSubstituteMargin,
            self::MIN_NON_NEGATIVE,
            min(self::MAX_DRAW_SUBSTITUTE_MARGIN, self::MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE),
        );
        self::assertAtLeast('roomMemoryWindowDays', $roomMemoryWindowDays, self::MIN_POSITIVE);
        self::assertAtLeast('roomMemoryWindowRounds', $roomMemoryWindowRounds, self::MIN_POSITIVE);
        self::assertAtLeast('themeSelectorMinPool', $themeSelectorMinPool, self::MIN_NON_NEGATIVE);
        self::assertBetween('lobbyBroadcastDebounceMs', $lobbyBroadcastDebounceMs, self::MIN_NON_NEGATIVE, self::MAX_LOBBY_BROADCAST_DEBOUNCE_MS);
        self::assertBetween('frameCropMaxWidthPercent', $frameCropMaxWidthPercent, self::MIN_FRAME_CROP_MAX_WIDTH_PERCENT, self::MAX_FRAME_CROP_MAX_WIDTH_PERCENT);
        self::assertBetween('frameCropMinWidthPx', $frameCropMinWidthPx, self::MIN_FRAME_CROP_MIN_WIDTH_PX, self::MAX_FRAME_CROP_MIN_WIDTH_PX);
        self::assertMultipleOf('frameCropMinWidthPx', $frameCropMinWidthPx, self::FRAME_CROP_WIDTH_MULTIPLE_PX);
    }

    /**
     * Instance complète depuis `config/game.php › platform`.
     *
     * `tierGraceMs` et `preloadLeadMs` y entrent par leur constante : aucune clé
     * `game.platform.tier_grace_ms` ni `game.platform.preload_lead_ms` n'est lue,
     * et celle qu'un déploiement poserait est ignorée.
     *
     * @throws InvalidArgumentException Une valeur configurée hors de ses bornes.
     */
    public static function fromConfig(): self
    {
        return new self(
            savedConfigsPerUser: self::configured('saved_configs_per_user', self::DEFAULT_SAVED_CONFIGS_PER_USER),
            roomSeats: self::configured('room_seats', self::DEFAULT_ROOM_SEATS),
            avatarPresets: self::configured('avatar_presets', self::DEFAULT_AVATAR_PRESETS),
            historyWindowMonths: self::configured('history_window_months', self::DEFAULT_HISTORY_WINDOW_MONTHS),
            successRateMinRounds: self::configured('success_rate_min_rounds', self::DEFAULT_SUCCESS_RATE_MIN_ROUNDS),
            frameUploadMaxKilobytes: self::configured('frame_upload_max_kilobytes', self::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES),
            tierGraceMs: self::DEFAULT_TIER_GRACE_MS,
            preloadLeadMs: self::DEFAULT_PRELOAD_LEAD_MS,
            drawSubstituteMargin: self::configured('draw_substitute_margin', self::DEFAULT_DRAW_SUBSTITUTE_MARGIN),
            roomMemoryWindowDays: self::configured('room_memory_window_days', self::DEFAULT_ROOM_MEMORY_WINDOW_DAYS),
            roomMemoryWindowRounds: self::configured('room_memory_window_rounds', self::DEFAULT_ROOM_MEMORY_WINDOW_ROUNDS),
            themeSelectorMinPool: self::configured('theme_selector_min_pool', self::DEFAULT_THEME_SELECTOR_MIN_POOL),
            lobbyBroadcastDebounceMs: self::configured('lobby_broadcast_debounce_ms', self::DEFAULT_LOBBY_BROADCAST_DEBOUNCE_MS),
            frameCropMaxWidthPercent: self::configured('frame_crop_max_width_percent', self::DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT),
            frameCropMinWidthPx: self::configured('frame_crop_min_width_px', self::DEFAULT_FRAME_CROP_MIN_WIDTH_PX),
        );
    }

    /**
     * L'instance du conteneur, construite une fois par cycle de vie (requête, job).
     *
     * Jamais une propriété statique de classe (voir le docblock de la classe).
     */
    public static function current(): self
    {
        return app(self::class);
    }

    public static function savedConfigsPerUser(): int
    {
        return self::current()->savedConfigsPerUser;
    }

    public static function roomSeats(): int
    {
        return self::current()->roomSeats;
    }

    public static function avatarPresets(): int
    {
        return self::current()->avatarPresets;
    }

    public static function historyWindowMonths(): int
    {
        return self::current()->historyWindowMonths;
    }

    public static function successRateMinRounds(): int
    {
        return self::current()->successRateMinRounds;
    }

    public static function frameUploadMaxKilobytes(): int
    {
        return self::current()->frameUploadMaxKilobytes;
    }

    /**
     * Constante d'instance figée au lancement dans `game.tier_grace_ms`.
     *
     * À ne jamais confondre avec `disconnectGraceSeconds`, réglage d'hôte de 15 à
     * 180 s : quatre ordres de grandeur les séparent. Jamais lue en configuration.
     */
    public static function tierGraceMs(): int
    {
        return self::current()->tierGraceMs;
    }

    /**
     * Constante d'instance figée au lancement dans `game.preload_lead_ms`.
     *
     * Jamais la durée d'un palier : à `lead = dᵢ`, le tricheur obtient un palier
     * entier d'avance et l'image du palier 1 reste lisible pendant toute la
     * révélation précédente. Jamais lue en configuration.
     */
    public static function preloadLeadMs(): int
    {
        return self::current()->preloadLeadMs;
    }

    /**
     * `B_max(N)`, en pourcentage entier de la valeur du palier (D22 du 23/09).
     *
     * `min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))`, soit 50, 50,
     * 33 et 25 pour `N` = 2 à 5 : au barème par défaut, attendre le palier suivant
     * ne rapporte jamais strictement plus (spec 80 § 3.3). Calculé par `intdiv`,
     * jamais en flottant, pour que le rejeu soit exact.
     *
     * Jamais lu en configuration, jamais dans `room_settings`. Un `N` hors bornes
     * lève au lieu d'être écrêté : un `N` écrêté masquerait une partie invalide et
     * fausserait le rejeu.
     *
     * @throws InvalidArgumentException `N` hors de `[MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`.
     */
    public static function speedBonusMaxPercent(int $framesPerRound): int
    {
        if ($framesPerRound < RoomSettingsBounds::MIN_FRAMES_PER_ROUND
            || $framesPerRound > RoomSettingsBounds::MAX_FRAMES_PER_ROUND) {
            throw new InvalidArgumentException(sprintf(
                'B_max est défini pour N dans [%d, %d] ; N = %d reçu, jamais écrêté.',
                RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
                $framesPerRound,
            ));
        }

        return min(self::SPEED_BONUS_MAX_PERCENT_CAP, intdiv(self::FULL_PERCENT, $framesPerRound - 1));
    }

    public static function drawSubstituteMargin(): int
    {
        return self::current()->drawSubstituteMargin;
    }

    public static function roomMemoryWindowDays(): int
    {
        return self::current()->roomMemoryWindowDays;
    }

    public static function roomMemoryWindowRounds(): int
    {
        return self::current()->roomMemoryWindowRounds;
    }

    public static function themeSelectorMinPool(): int
    {
        return self::current()->themeSelectorMinPool;
    }

    public static function lobbyBroadcastDebounceMs(): int
    {
        return self::current()->lobbyBroadcastDebounceMs;
    }

    public static function frameCropMaxWidthPercent(): int
    {
        return self::current()->frameCropMaxWidthPercent;
    }

    public static function frameCropMinWidthPx(): int
    {
        return self::current()->frameCropMinWidthPx;
    }

    /**
     * Représentation en données : une prop de PAGE, jamais une prop partagée globale.
     *
     * `speedBonusMaxPercent` est calculé pour chaque `N` des bornes, jamais écrit à la
     * main. `tierGraceMs`, `preloadLeadMs`, `frameUploadMaxKilobytes` et les plafonds
     * de recadrage n'y figurent pas (R-07) : aucun écran joueur n'en a besoin, et le
     * back-office compose sa propre prop depuis les accesseurs.
     *
     * @return array{savedConfigsPerUser: int, roomSeats: int, avatarPresets: int, historyWindowMonths: int, successRateMinRounds: int, speedBonusMaxPercent: array<int, int>}
     */
    public function toArray(): array
    {
        $speedBonusMaxPercent = [];

        for ($framesPerRound = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $framesPerRound <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $framesPerRound++) {
            $speedBonusMaxPercent[$framesPerRound] = self::speedBonusMaxPercent($framesPerRound);
        }

        return [
            'savedConfigsPerUser' => $this->savedConfigsPerUser,
            'roomSeats' => $this->roomSeats,
            'avatarPresets' => $this->avatarPresets,
            'historyWindowMonths' => $this->historyWindowMonths,
            'successRateMinRounds' => $this->successRateMinRounds,
            'speedBonusMaxPercent' => $speedBonusMaxPercent,
        ];
    }

    private static function configured(string $key, int $default): int
    {
        return Config::integer(self::CONFIG_PREFIX.$key, $default);
    }

    /**
     * Deux tolérances de frontière — une de chaque côté — tiennent toujours sous le
     * palier le plus court : sinon la fenêtre du palier entier deviendrait du
     * hasard réseau.
     */
    private static function assertTierGrace(int $tierGraceMs): void
    {
        self::assertAtLeast('tierGraceMs', $tierGraceMs, self::MIN_NON_NEGATIVE);

        if (2 * $tierGraceMs >= RoomSettingsBounds::MIN_TIER_DURATION * self::MILLISECONDS_PER_SECOND) {
            throw new InvalidArgumentException(sprintf(
                'PlatformLimits : deux tierGraceMs (%d ms) doivent rester sous la durée minimale de palier (%d s).',
                $tierGraceMs,
                RoomSettingsBounds::MIN_TIER_DURATION,
            ));
        }
    }

    private static function assertAtLeast(string $name, int $value, int $min): void
    {
        if ($value < $min) {
            throw new InvalidArgumentException(sprintf(
                'PlatformLimits : %s vaut %d, sous sa borne basse %d.',
                $name,
                $value,
                $min,
            ));
        }
    }

    private static function assertBetween(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf(
                'PlatformLimits : %s vaut %d, hors de ses bornes [%d, %d].',
                $name,
                $value,
                $min,
                $max,
            ));
        }
    }

    private static function assertMultipleOf(string $name, int $value, int $step): void
    {
        if ($value % $step !== 0) {
            throw new InvalidArgumentException(sprintf(
                'PlatformLimits : %s vaut %d, qui n\'est pas un multiple de %d.',
                $name,
                $value,
                $step,
            ));
        }
    }
}
