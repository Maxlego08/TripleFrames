<?php

namespace App\Support\Identity;

use App\Avatars\AvatarRef;
use App\Models\GamePlayer;
use App\Models\Player;
use LogicException;

/**
 * L'identité affichée d'un siège — contrat C5 (spec 40 § 7).
 *
 * **Seule sérialisation** de ce qu'un joueur montre aux autres : un
 * `public_id`, un pseudo et un avatar, rien d'autre. Elle est diffusée au
 * salon, identique pour tous — `SeatView` de `60` l'étend (C7), le podium de
 * `80` l'embarque (C13) —, et ne se construit jamais par `Player::toArray()`.
 * **Jamais sérialisés** : `id`, `room_id`, `user_id`, `nickname_normalized`,
 * `kicked_at`, le hash du jeton, `active_seat_token` (E10-34). Aucune donnée
 * de manche : rien, ici, ne peut approcher la bonne réponse (§ 1.4, règle 3).
 *
 * **L'avatar passe par l'accesseur serveur unique** de sa ligne,
 * {@see Player::avatarRef()} au lobby et {@see GamePlayer::avatarRef()} en
 * partie (10 § 1.3) : image téléversée VISIBLE du compte rattaché (spec 40
 * § 11.5, D49 du 01/10, `user_id` chargé avec le siège), puis prédéfini, puis
 * initiales, sans branche
 * `provider` pour un siège (aucune copie provider sur `player`, jamais gelée
 * dans `game_player`, 10 § 7.3). La charge est {@see AvatarRef::toArray()} :
 * des données — une URL, une CLÉ d'`alt` du domaine `common`, des initiales —,
 * jamais une chaîne traduite côté serveur (règle 4).
 *
 * **Masquage** (I5.10 : forme au J1, règle au J2). Un siège dont
 * `player.nickname_masked_at` est posé rend `nickname: null`, `masked: true`
 * et `avatar.initials = AvatarRef::FALLBACK_INITIAL`, pour que les initiales ne
 * trahissent pas le pseudo ; son avatar prédéfini, contenu du site et non
 * signalable (§ 6.6), reste affiché. Le masquage est lu sur le siège VIVANT,
 * y compris pour l'affichage gelé d'une partie. Au J1, aucun geste ne pose
 * `nickname_masked_at` : `masked` vaut toujours `false` et `nickname` n'est
 * jamais `null` — sauf après l'archivage, qui efface le pseudo (10 § 11.1).
 *
 * **Lecture stricte de la colonne de masquage.** Un siège lu par une requête
 * qui n'aurait pas sélectionné `nickname_masked_at` le croirait non masqué et
 * publierait son pseudo : la construction refuse alors bruyamment
 * ({@see LogicException}), au lieu de lire `null` en silence. Seul un siège
 * qui vient d'être inséré en est dispensé — sa ligne porte le défaut SQL,
 * NULL.
 */
final readonly class PlayerIdentity
{
    /**
     * Colonnes du siège qu'exige {@see self::fromGamePlayer()} sur la relation
     * `player` chargée (C5 : « exige player:id,public_id,nickname_masked_at
     * chargé ») ; `id` n'y figure que pour qu'Eloquent rattache la relation.
     *
     * @var list<string>
     */
    public const array FROZEN_SEAT_COLUMNS = ['id', 'public_id', 'nickname_masked_at', 'user_id'];

    /**
     * @param  array{kind: string|null, url: string|null, altKey: string, initials: string}  $avatar
     */
    private function __construct(
        private string $publicId,
        private ?string $nickname,
        private bool $masked,
        private array $avatar,
    ) {}

    /**
     * Identité d'un siège au lobby : pseudo et avatar COURANTS de la ligne
     * `player`.
     *
     * @throws LogicException siège lu sans `nickname_masked_at`.
     */
    public static function fromSeat(Player $seat): self
    {
        return self::resolve(
            publicId: $seat->public_id,
            nickname: $seat->nickname,
            masked: self::isMasked($seat),
            avatar: $seat->avatarRef(),
        );
    }

    /**
     * Identité d'un siège pendant une partie et au podium : pseudo et avatar
     * GELÉS au lancement dans `game_player.display_*` (C6, O6 ; § 7.2), on les
     * gèle ensemble ou pas du tout. `public_id` et l'état de masquage sont lus
     * sur le siège, qui doit être chargé avec au moins
     * {@see self::FROZEN_SEAT_COLUMNS} — `->load('player:id,public_id,nickname_masked_at')`.
     *
     * @throws LogicException relation `player` absente, ou chargée sans
     *                        `public_id` ou sans `nickname_masked_at`.
     */
    public static function fromGamePlayer(GamePlayer $participation): self
    {
        $seat = $participation->relationLoaded('player') ? $participation->getRelation('player') : null;

        if (! $seat instanceof Player) {
            throw new LogicException(
                'PlayerIdentity::fromGamePlayer() exige la relation `player` chargée ('.implode(', ', self::FROZEN_SEAT_COLUMNS).').',
            );
        }

        if (! array_key_exists('public_id', $seat->getAttributes())) {
            throw new LogicException('PlayerIdentity::fromGamePlayer() exige `player.public_id` chargé.');
        }

        return self::resolve(
            publicId: $seat->public_id,
            nickname: $participation->display_nickname,
            masked: self::isMasked($seat),
            avatar: $participation->avatarRef(),
        );
    }

    /**
     * La charge diffusée, identique pour tous les joueurs du salon, miroir de
     * `PlayerIdentity` dans `resources/js/types/player.ts`.
     *
     * @return array{publicId: string, nickname: string|null, masked: bool, avatar: array{kind: string|null, url: string|null, altKey: string, initials: string}}
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'nickname' => $this->nickname,
            'masked' => $this->masked,
            'avatar' => $this->avatar,
        ];
    }

    /**
     * Applique la forme du masquage (I5.10) : le pseudo ET ses initiales
     * disparaissent ensemble, l'image prédéfinie reste.
     */
    private static function resolve(string $publicId, ?string $nickname, bool $masked, AvatarRef $avatar): self
    {
        $data = $avatar->toArray();

        if (! $masked) {
            return new self($publicId, $nickname, false, $data);
        }

        $data['initials'] = AvatarRef::FALLBACK_INITIAL;

        return new self($publicId, null, true, $data);
    }

    /**
     * État de masquage du siège, lu sur sa ligne. Une colonne absente des
     * attributs n'est tolérée que pour un siège qui vient d'être inséré, dont
     * la ligne porte le défaut SQL (NULL).
     *
     * @throws LogicException siège lu sans `nickname_masked_at`.
     */
    private static function isMasked(Player $seat): bool
    {
        if (! array_key_exists('nickname_masked_at', $seat->getAttributes()) && ! $seat->wasRecentlyCreated) {
            throw new LogicException(
                'PlayerIdentity exige `player.nickname_masked_at` chargé : un siège lu sans cette colonne publierait un pseudo masqué.',
            );
        }

        return $seat->nickname_masked_at !== null;
    }
}
