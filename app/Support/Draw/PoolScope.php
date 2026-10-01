<?php

namespace App\Support\Draw;

use App\Models\Game;
use App\Models\Movie;
use App\Models\Room;
use App\Models\Round;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Les entrées figées du prédicat de vivier (spec 30 § 3.2, contrat C2).
 *
 * Un `PoolScope` est construit **une fois** et passé tel quel : c'est ce qui
 * garantit que la garde et le tirage d'un même lancement lisent la même fenêtre
 * de mémoire (§ 6.1). Il ne lit rien d'autre que ce qu'il fige — la fenêtre de
 * {@see RoomMemoryWindow} au constructeur de salon, les manches démarrées de la
 * partie au constructeur des leurres — et n'interroge **jamais** le JSON des
 * réglages : `themeIds` est lu dans le value object, désérialisé en PHP
 * (spec 10 § 1.6).
 *
 * Deux viviers, une fonction (§ 3.1) :
 * - **vivier du salon** ({@see self::forRoom()}, {@see self::forGame()}) : thèmes,
 *   `N` et non-répétition du salon, mémoire du salon ;
 * - **vivier catalogue** ({@see self::catalogue()}) : thèmes et `N` seulement,
 *   **aucune clause de salon** — supervision, sélecteur et solo ;
 * - **mesure d'un thème** ({@see self::themeProbe()}) : vivier catalogue d'un
 *   seul thème, publié ou non — l'écran des thèmes du back-office seul.
 *
 * Invariants gardés au constructeur, jamais écrêtés : `memorySince` et
 * `playedUntil` sont non nuls si et seulement si `roomId` l'est ; la
 * non-répétition exige un salon ; un `N` non nul est dans les bornes de
 * {@see RoomSettingsBounds}.
 */
final readonly class PoolScope
{
    /**
     * @param  list<int>  $themeIds  Thèmes demandés, NON filtrés : l'intersection avec
     *                               les thèmes publiés se fait à la lecture ({@see PoolQuery}).
     * @param  int|null  $framesPerRound  `N` ; nul = aucune clause de `N` (complément des leurres seulement).
     * @param  int|null  $roomId  Axe salon ; nul en solo et pour le vivier catalogue.
     * @param  CarbonImmutable|null  $memorySince  Borne basse de la mémoire du salon ; non nulle ssi `roomId` l'est.
     * @param  CarbonImmutable|null  $playedUntil  `= $now`, borne haute de « joué » (`T₁` franchi) ; non nulle ssi `roomId` l'est.
     * @param  bool  $noRepeatMovies  Clause de non-répétition active (exige `roomId`).
     * @param  list<int>  $excludedMovieIds  Films exclus, croissants et sans doublon.
     * @param  list<int>  $excludedGroupIds  `movie_group` exclus, croissants et sans doublon.
     * @param  bool  $themesUnpublishedIncluded  Vrai pour {@see self::themeProbe()} seul : les thèmes
     *                                           demandés ne sont PAS intersectés avec les publiés (§ 3.3 clause 3).
     *
     * @throws InvalidArgumentException Un invariant du périmètre est violé.
     */
    private function __construct(
        public array $themeIds,
        public ?int $framesPerRound,
        public ?int $roomId,
        public ?CarbonImmutable $memorySince,
        public ?CarbonImmutable $playedUntil,
        public bool $noRepeatMovies,
        public array $excludedMovieIds,
        public array $excludedGroupIds,
        public bool $themesUnpublishedIncluded = false,
    ) {
        if ($framesPerRound !== null
            && ($framesPerRound < RoomSettingsBounds::MIN_FRAMES_PER_ROUND
                || $framesPerRound > RoomSettingsBounds::MAX_FRAMES_PER_ROUND)) {
            throw new InvalidArgumentException(sprintf(
                'PoolScope : frames_per_round = %d hors de [%d, %d], jamais écrêté.',
                $framesPerRound,
                RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            ));
        }

        $roomAxis = $roomId !== null;

        if (($memorySince !== null) !== $roomAxis || ($playedUntil !== null) !== $roomAxis) {
            throw new InvalidArgumentException(
                'PoolScope : memorySince et playedUntil sont non nuls si et seulement si roomId l’est.',
            );
        }

        if ($noRepeatMovies && ! $roomAxis) {
            throw new InvalidArgumentException('PoolScope : la non-répétition exige un salon.');
        }

        if ($themesUnpublishedIncluded && ($roomAxis || count($themeIds) !== 1)) {
            throw new InvalidArgumentException(
                'PoolScope : les thèmes non publiés ne sont comptés que par la mesure d’un thème seul, hors salon.',
            );
        }
    }

    /**
     * Vivier du salon au lobby, à la garde et au tirage multijoueur, et au grisage
     * des presets (`$settings` = le preset, la mémoire restant celle du salon).
     *
     * La fenêtre de mémoire est calculée **ici, une fois**, à `$now`.
     */
    public static function forRoom(Room $room, RoomSettings $settings, CarbonImmutable $now): self
    {
        return new self(
            themeIds: $settings->themeIds,
            framesPerRound: $settings->framesPerRound,
            roomId: $room->id,
            memorySince: RoomMemoryWindow::since($room->id, $now),
            playedUntil: $now,
            noRepeatMovies: $settings->noRepeatMovies,
            excludedMovieIds: [],
            excludedGroupIds: [],
        );
    }

    /**
     * Vivier d'une partie lancée, depuis l'instantané FIGÉ `game.settings_snapshot`
     * et `game.room_id` — jamais `room.settings`, que l'hôte a pu changer depuis.
     *
     * Solo (`room_id` nul) : aucune clause de salon, `noRepeatMovies` ignoré — le
     * snapshot garde sa valeur (§ 9).
     */
    public static function forGame(Game $game, CarbonImmutable $now): self
    {
        $settings = $game->settings_snapshot;

        if ($game->room_id === null) {
            return self::catalogue($settings->themeIds, $settings->framesPerRound);
        }

        return new self(
            themeIds: $settings->themeIds,
            framesPerRound: $settings->framesPerRound,
            roomId: $game->room_id,
            memorySince: RoomMemoryWindow::since($game->room_id, $now),
            playedUntil: $now,
            noRepeatMovies: $settings->noRepeatMovies,
            excludedMovieIds: [],
            excludedGroupIds: [],
        );
    }

    /**
     * Vivier des leurres d'une manche (§ 10.2, D21 du 23/09) : {@see self::forGame()}
     * moins, **toujours**, le film cible, son `movie_group` et les films des
     * manches **démarrées** de la partie (`started_at <= $now`, § 1.3).
     *
     * **Jamais les films des manches futures, programmées comprises** : les
     * exclure retirerait du QCM exactement les films que le tirage a retenus, et
     * un joueur attentif lirait le tirage dans ce qui manque aux leurres.
     *
     * `$now` est l'instant THÉORIQUE de composition passé par la spec 70, jamais
     * l'heure d'exécution du job : un job rattrapé en retard compose les mêmes
     * leurres.
     */
    public static function forDecoys(Round $round, CarbonImmutable $now): self
    {
        // L'instant au format de la colonne (`timestamp(3)`), jamais celui de la
        // grammaire, qui tronque à la seconde : voir RoomMemoryWindow.
        $startedMovieIds = Round::query()
            ->where('game_id', $round->game_id)
            ->where('started_at', '<=', (new Round)->fromDateTime($now))
            ->pluck('movie_id')
            ->map(static fn (mixed $movieId): int => (int) $movieId)
            ->values()
            ->all();

        $targetGroupId = Movie::query()->whereKey($round->movie_id)->value('group_id');

        return self::forGame($round->game, $now)->excluding(
            [$round->movie_id, ...$startedMovieIds],
            $targetGroupId === null ? [] : [(int) $targetGroupId],
        );
    }

    /**
     * Vivier catalogue : fonction de (thèmes, `N`) seulement — aucun salon, aucune
     * mémoire, non-répétition coupée, aucune exclusion.
     *
     * @param  list<int>  $themeIds
     */
    public static function catalogue(array $themeIds, ?int $framesPerRound): self
    {
        return new self(
            themeIds: $themeIds,
            framesPerRound: $framesPerRound,
            roomId: null,
            memorySince: null,
            playedUntil: null,
            noRepeatMovies: false,
            excludedMovieIds: [],
            excludedGroupIds: [],
        );
    }

    /**
     * Mesure d'un thème avant sa publication (§ 12.3, L30-11a, J1 depuis D43 du
     * 01/10) : vivier catalogue restreint à **ce seul thème, publié ou non**, au
     * `N` par défaut, sans clause de salon ni exclusion.
     *
     * Seule entrée qui ne s'intersecte pas avec les thèmes publiés : un appel à
     * {@see self::catalogue()} avec un thème non publié retomberait sur la branche
     * sans thème et compterait tout le catalogue. Supervision seule — aucun
     * salon, aucun tirage ni aucun leurre ne construit ce périmètre.
     */
    public static function themeProbe(int $themeId): self
    {
        return new self(
            themeIds: [$themeId],
            framesPerRound: RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
            roomId: null,
            memorySince: null,
            playedUntil: null,
            noRepeatMovies: false,
            excludedMovieIds: [],
            excludedGroupIds: [],
            themesUnpublishedIncluded: true,
        );
    }

    /**
     * Même périmètre, autres thèmes demandés (`[]` = branche sans thème).
     *
     * @param  list<int>  $themeIds
     */
    public function withThemeIds(array $themeIds): self
    {
        return $this->copy(themeIds: $themeIds);
    }

    /**
     * Même périmètre, autre `N` (nul = aucune clause de `N`).
     */
    public function withFramesPerRound(?int $framesPerRound): self
    {
        return new self(
            themeIds: $this->themeIds,
            framesPerRound: $framesPerRound,
            roomId: $this->roomId,
            memorySince: $this->memorySince,
            playedUntil: $this->playedUntil,
            noRepeatMovies: $this->noRepeatMovies,
            excludedMovieIds: $this->excludedMovieIds,
            excludedGroupIds: $this->excludedGroupIds,
            themesUnpublishedIncluded: $this->themesUnpublishedIncluded,
        );
    }

    /**
     * Même périmètre sans la clause de non-répétition — au diagnostic du rapport
     * seulement (§ 4.3), **jamais aux leurres** (§ 10.1). La mémoire du salon est
     * conservée : elle sert encore la préférence de variante.
     */
    public function withoutNoRepeat(): self
    {
        return $this->copy(noRepeatMovies: false);
    }

    /**
     * Même périmètre, exclusions **ajoutées** à celles qu'il porte déjà.
     *
     * @param  list<int>  $movieIds
     * @param  list<int>  $groupIds
     */
    public function excluding(array $movieIds, array $groupIds): self
    {
        return $this->copy(
            excludedMovieIds: self::idSet([...$this->excludedMovieIds, ...$movieIds]),
            excludedGroupIds: self::idSet([...$this->excludedGroupIds, ...$groupIds]),
        );
    }

    /**
     * Copie à champs remplacés, le constructeur regardant chaque invariant.
     *
     * @param  list<int>|null  $themeIds
     * @param  list<int>|null  $excludedMovieIds
     * @param  list<int>|null  $excludedGroupIds
     */
    private function copy(
        ?array $themeIds = null,
        ?bool $noRepeatMovies = null,
        ?array $excludedMovieIds = null,
        ?array $excludedGroupIds = null,
    ): self {
        return new self(
            themeIds: $themeIds ?? $this->themeIds,
            framesPerRound: $this->framesPerRound,
            roomId: $this->roomId,
            memorySince: $this->memorySince,
            playedUntil: $this->playedUntil,
            noRepeatMovies: $noRepeatMovies ?? $this->noRepeatMovies,
            excludedMovieIds: $excludedMovieIds ?? $this->excludedMovieIds,
            excludedGroupIds: $excludedGroupIds ?? $this->excludedGroupIds,
            themesUnpublishedIncluded: $this->themesUnpublishedIncluded,
        );
    }

    /**
     * Ensemble d'identifiants, croissant et sans doublon : la requête est la même
     * quel que soit l'ordre dans lequel les exclusions ont été ajoutées.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function idSet(array $ids): array
    {
        $unique = array_values(array_unique($ids));
        sort($unique);

        return $unique;
    }
}
