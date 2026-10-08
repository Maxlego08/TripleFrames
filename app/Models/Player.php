<?php

namespace App\Models;

use App\Avatars\AccountImage;
use App\Avatars\AvatarRef;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Le siège (§ 7.1) — jamais une personne, et jamais un compte.
 *
 * `room_id` est **nullable parce qu'une partie solo n'a pas de salon** ; un siège
 * solo reste un `player` pour porter locale, pseudo et avatar. `user_id` est
 * toujours facultatif : la boucle de jeu ne passe pas par le compte (principe 10),
 * et aucune FK ne part de `users` vers un fait de partie.
 *
 * **Le rôle d'hôte n'est pas ici** : il vit sur `room.host_player_id`, référence
 * souple lue par {@see Room::hostPlayer()} et écrite par la seule action de
 * transfert. {@see self::hostedRoom()} en est l'inverse.
 *
 * **Aucune adresse IP, aucun identifiant de session.** `active_seat_token` est un
 * ULID applicatif frappé à la prise de siège : un jeton = un siège, le second
 * onglet frappe un nouveau jeton et l'ancien passe en lecture seule. Cette
 * garantie est **par siège et jamais par personne** — rien n'empêche un humain de
 * prendre un second siège sous un autre `player_token`, et le schéma ne peut pas
 * le voir sans donnée personnelle.
 *
 * **Un siège s'identifie par le seul hash du `player_token` courant, expulsé
 * toujours exclu** (spec 40 § 3.9) : {@see self::heldByToken()} combiné à
 * `whereNull('kicked_at')`, ou `PlayerTokenManager::seatIn()` pour un salon
 * donné. Un siège expulsé passe `left` avec `kicked_at` posé au même instant que
 * `left_at` (D15 du 23/09) ; son jeton est refusé dans ce salon jusqu'à
 * l'archivage, qui efface le hash.
 *
 * `player.created_at` **n'est pas une colonne pilote de purge** : un siège se
 * supprime uniquement par dépendance (§ 11). Deux déclencheurs effacent les
 * identifiants d'invité — `room.archived_at` pour un siège de salon,
 * `room_id IS NULL AND last_seen_at < now − 24 h` pour un siège solo — plus
 * l'anonymisation de compte, distincte.
 *
 * Toutes les colonnes datées sont en `timestamp(3)`, `nickname_masked_at` et
 * `left_at` comprises : `$dateFormat` s'applique à **toutes** les colonnes datées
 * du modèle (§ 1.2). Corollaire : **aucun `DB::table('player')->update()` ne touche
 * jamais `last_seen_at`**, sous peine de perdre les millisecondes en silence.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $room_id `#[Hidden]` : la liste des joueurs EST la charge utile du lobby, et `room.id` y partirait douze fois par salon.
 * @property int|null $user_id `#[Hidden]`.
 * @property int|null $visitor_id Visiteur consentant du siège (D62 du 06/10), anonymisé à 12 mois.
 * @property string|null $device_class `mobile`, `tablet` ou `desktop`, pour un visiteur consentant seulement.
 * @property string|null $browser_family Famille de navigateur, pour un visiteur consentant seulement.
 * @property string|null $os_family Famille de système, pour un visiteur consentant seulement.
 * @property string|null $nickname
 * @property string|null $nickname_normalized `#[Hidden]` : forme repliée, jamais affichée.
 * @property CarbonImmutable|null $nickname_masked_at
 * @property CarbonImmutable|null $nickname_reports_from `#[Hidden]`, hors `#[Fillable]` : début de la fenêtre de comptage des signalements du pseudo, posé à la levée du masquage (spec 40 § 13.3, D66 du 07/10) ; NULL = depuis toujours.
 * @property string|null $player_token_hash `#[Hidden]` : SHA-256 du `tid` du `player_token`, jamais de la valeur du cookie (spec 40 § 3.5).
 * @property string|null $solo_token_hash `#[Hidden]` : créneau d'unicité du siège solo (E10-N3, `player_solo_token_uq`) — copie de `player_token_hash` si et seulement si `room_id` est nul, écrite dans la même écriture que lui par le démarrage solo et effacée avec lui. Hors `#[Fillable]`. La reprise d'un siège solo ne le lit jamais : elle passe par `player_token_hash` (`player_token_idx`).
 * @property string|null $active_seat_token
 * @property Locale $locale
 * @property AvatarKind|null $avatar_kind
 * @property string|null $avatar_preset
 * @property CarbonImmutable $joined_at
 * @property PlayerConnectionState $connection_state
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $kicked_at Expulsion par l'hôte (D15 du 23/09) : posée au même instant serveur que `left_at`, jamais remise à NULL. Hors `#[Fillable]`, `#[Hidden]`.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Room|null $room
 * @property-read User|null $user `#[Hidden]` : un `players.user` chargé publierait l'e-mail, le rôle et la date d'inscription d'AUTRUI — `#[Hidden]` sur `User` ne couvre que la part § 5.1, jamais le cas « compte d'un autre joueur ».
 * @property-read Room|null $hostedRoom
 * @property-read Collection<int, GamePlayer> $gamePlayers
 * @property-read Collection<int, RoundPlayer> $roundPlayers
 * @property-read Collection<int, Guess> $guesses
 * @property-read Collection<int, Report> $reportsFiled
 * @property-read Collection<int, Report> $reportsAgainst
 */
#[Table('player')]
#[DateFormat('Y-m-d H:i:s.v')]
#[RouteKey('public_id')]
#[Fillable([
    'nickname',
    'nickname_normalized',
    'locale',
    'avatar_kind',
    'avatar_preset',
])]
#[Hidden(['id', 'room_id', 'user_id', 'user', 'visitor_id', 'visitor', 'device_class', 'browser_family', 'os_family', 'player_token_hash', 'solo_token_hash', 'active_seat_token', 'nickname_normalized', 'kicked_at', 'nickname_reports_from'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /**
     * Effectif **présent** d'un salon : `connection_state <> 'left'`, servi par
     * `player_room_state_idx`.
     *
     * Compter toutes les lignes `player` compterait l'historique des sièges et non
     * l'effectif : une ligne n'est jamais supprimée avant l'archivage, donc un
     * salon de capacité 4 dont quatre joueurs sont partis refuserait tout le monde
     * pendant 24 h (§ 6.2). C'est aussi le prédicat de la fin anticipée, qui ne
     * compte que les joueurs **connectés**.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function holdingSeat(Builder $query): void
    {
        $query->where('connection_state', '<>', PlayerConnectionState::Left->value);
    }

    /**
     * Sièges tenus par ce `player_token` : `player_token_hash = $token->hash()`
     * (spec 40 § 3.1), servi par `player_room_token_uq` pour un salon donné et
     * par `player_token_idx` sinon.
     *
     * **N'exclut pas un siège expulsé** : tout consommateur qui identifie un siège
     * le combine à `whereNull('kicked_at')` (§ 3.9, I4.9), ou passe par
     * `PlayerTokenManager::seatIn()` pour un salon donné.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function heldByToken(Builder $query, PlayerToken $token): void
    {
        $query->where('player_token_hash', $token->hash());
    }

    /**
     * Siège expulsé par l'hôte (D15 du 23/09) : son jeton est refusé dans ce
     * salon jusqu'à l'archivage, qui efface le hash. `kicked_at` n'est jamais
     * remise à NULL — aucune réadmission.
     */
    public function wasKicked(): bool
    {
        return $this->kicked_at !== null;
    }

    /**
     * NULL en solo (§ 7.10).
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Rattachement tardif d'un invité à un compte, détaché à l'anonymisation.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le visiteur consentant qui a pris le siège (D62 du 06/10) : relie les
     * sièges successifs d'un même navigateur.
     *
     * @return BelongsTo<Visitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /**
     * Inverse de la référence souple `room.host_player_id` — sans contrainte de FK
     * (§ 1.5) : l'absence de ligne est un état normal, jamais une erreur.
     *
     * @return HasOne<Room, $this>
     */
    public function hostedRoom(): HasOne
    {
        return $this->hasOne(Room::class, 'host_player_id');
    }

    /**
     * @return HasMany<GamePlayer, $this>
     */
    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /**
     * @return HasMany<RoundPlayer, $this>
     */
    public function roundPlayers(): HasMany
    {
        return $this->hasMany(RoundPlayer::class);
    }

    /**
     * @return HasMany<Guess, $this>
     */
    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }

    /**
     * Signalements **émis** par ce siège.
     *
     * @return HasMany<Report, $this>
     */
    public function reportsFiled(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_player_id');
    }

    /**
     * Signalements **reçus** par ce siège — deux signalements distincts masquent le
     * pseudo, et c'est `nickname_masked_at` qui porte l'état, jamais un écrasement
     * (qui collisionnerait sur `player_room_nickname_uq`).
     *
     * @return HasMany<Report, $this>
     */
    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(Report::class, 'target_player_id');
    }

    /**
     * Miroir EXACT des défauts SQL de `player` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'connection_state' => PlayerConnectionState::Connected->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'user_id' => 'integer',
            'nickname_masked_at' => 'datetime',
            'nickname_reports_from' => 'datetime',
            'locale' => Locale::class,
            'avatar_kind' => AvatarKind::class,
            'joined_at' => 'datetime',
            'connection_state' => PlayerConnectionState::class,
            'last_seen_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'left_at' => 'datetime',
            'kicked_at' => 'datetime',
        ];
    }

    /**
     * Résolution d'avatar d'un SIÈGE — le seul avatar que l'écran de jeu affiche.
     *
     * Le § 1.3 pose que les trois colonnes `avatar_preset` (`users`, `player`,
     * `game_player`) sont lues par le MÊME accesseur. Sans celui-ci, le seul chemin
     * serveur pour obtenir une URL d'avatar de joueur serait
     * `$room->load('players.user')` — et la charge utile du lobby porterait, pour
     * chacun des douze occupants, l'e-mail complet du compte, son `role` et sa date
     * d'inscription, à des inconnus réunis par un lien partagé.
     *
     * **Branche `upload`** (spec 40 § 11.5, D49 du 01/10) : l'image VISIBLE du
     * compte rattaché, lue vivante par clé primaire, sinon le prédéfini de
     * repli du siège, sinon les initiales du PSEUDO — un masquage s'applique
     * donc partout, à la prochaine composition d'une vue.
     *
     * **La branche `provider` n'existe pas ici** : `player` n'a aucune colonne
     * `avatar_provider_path`, et `display_avatar_preset` n'est « jamais un chemin de
     * copie provider » (§ 7.3), précisément pour qu'un masquage postérieur fasse
     * redescendre la chaîne de repli.
     *
     * **Jamais nommée `avatar()`** : `HasAttributes::hasAttributeMutator()` exige
     * le type de retour exact `Illuminate\Database\Eloquent\Casts\Attribute`.
     */
    public function avatarRef(): AvatarRef
    {
        $initials = AvatarRef::initialsFrom($this->nickname);

        $image = AccountImage::fromKind($this->avatar_kind);
        $path = $image === null ? null : UploadedAvatars::visiblePath($this->user_id, $image);

        if ($image !== null && $path !== null) {
            return $image === AccountImage::Upload ? AvatarRef::upload($path, $initials) : AvatarRef::provider($path, $initials);
        }

        if ($this->avatar_kind !== null && $this->avatar_preset !== null) {
            return AvatarRef::preset($this->avatar_preset, $initials);
        }

        return AvatarRef::initials($initials);
    }
}
