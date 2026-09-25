<?php

namespace Database\Factories;

use App\Avatars\AvatarPresetCatalog;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see Player} — le SIÈGE (§ 7.1), jamais une personne.
 *
 * **`nickname_normalized` est TOUJOURS la forme repliée de `nickname`**, écrite
 * dans la même écriture que lui. Sans cela, `player_room_nickname_uq` ne veut
 * plus rien dire : deux pseudos que MySQL tiendrait pour égaux en
 * `utf8mb4_unicode_ci` passeraient en test SQLite et lèveraient une 1062 non
 * traduite au moment de rejoindre un salon en production (§ 1.4). C'est pourquoi
 * les pseudos par défaut portent des diacritiques : une fabrique qui ne
 * produirait que de l'ASCII rendrait le pliage invisible.
 *
 * > **Le normaliseur de cette fabrique est un provisoire assumé.** Le
 * > normaliseur canonique — celui d'`answer_key` — appartient à
 * > `70-validation-des-reponses.md`, qui n'est pas écrite. {@see self::normalizeNickname()}
 * > en reproduit le contrat minimal (minuscules, diacritiques translittérés sans
 * > `ext-intl`, espaces compressés, borne à 20) et devra être remplacé par un
 * > appel au normaliseur de la spec 70 le jour où il existe.
 *
 * `room_id` est **nullable parce qu'une partie solo n'a pas de salon** : le défaut
 * est un siège de salon, {@see self::solo()} produit l'autre cas. `user_id` reste
 * nul — on joue en invité, la boucle de jeu ne passe pas par le compte
 * (principe 10).
 *
 * `active_seat_token` est un **ULID applicatif** (§ 1.1) et **jamais** un
 * identifiant de session Laravel ; `player_token_hash` est un SHA-256, jamais le
 * jeton en clair.
 *
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /** Alphabet base32 de `public_id` — identité publique, jamais dérivée de l'id (§ 1.1). */
    public const string PUBLIC_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Longueur de `public_id`, fixée par `char(12)`. */
    public const int PUBLIC_ID_LENGTH = 12;

    /** Borne produit du pseudo, et longueur de `nickname_normalized` (§ 1.3). */
    public const int NICKNAME_MAX_LENGTH = 20;

    /**
     * Prénoms de fabrique, volontairement accentués : c'est le pliage de
     * `nickname_normalized` qui est mis à l'épreuve, pas le hasard de Faker.
     *
     * @var list<string>
     */
    private const array NICKNAMES = [
        'Ada', 'Bö', 'Céline', 'Dmitri', 'Éva',
        'Farid', 'Gwenn', 'Hugo', 'Iris', 'Jonás',
    ];

    /** Compteur de sièges, qui rend `nickname_normalized` unique par construction. */
    private static int $seatSequence = 0;

    /**
     * Define the model's default state.
     *
     * Un siège de salon, invité, connecté, avatar prédéfini.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nickname = self::nickname();

        return [
            'public_id' => self::publicId(),
            'room_id' => Room::factory(),
            'user_id' => null,
            'nickname' => $nickname,
            'nickname_normalized' => self::normalizeNickname($nickname),
            'nickname_masked_at' => null,
            'player_token_hash' => hash('sha256', Str::random(40)),
            'active_seat_token' => (string) Str::ulid(),
            'locale' => Locale::English,
            'avatar_kind' => AvatarKind::Preset,
            'avatar_preset' => self::avatarPreset(),
            'joined_at' => now(),
            'connection_state' => PlayerConnectionState::Connected,
            'last_seen_at' => now(),
            'disconnected_at' => null,
            'left_at' => null,
        ];
    }

    /**
     * Siège d'entraînement solo : `room_id` nul, donc aucune ligne `seen_frame`
     * possible et aucune mémoire de salon polluée (barrière 3 du § 7.10).
     */
    public function solo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'room_id' => null,
        ]);
    }

    /**
     * Rattachement du siège à un compte — la langue du siège suit alors celle du
     * compte, source unique de la préférence.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
            'locale' => $user->locale,
        ]);
    }

    /**
     * Pseudo imposé, avec sa forme repliée recalculée — jamais l'un sans l'autre.
     */
    public function withNickname(string $nickname): static
    {
        return $this->state(fn (array $attributes): array => [
            'nickname' => $nickname,
            'nickname_normalized' => self::normalizeNickname($nickname),
        ]);
    }

    /**
     * Siège déconnecté : il tient toujours sa place (§ 6.2) mais sort du
     * dénominateur de la fin anticipée (§ 7.7).
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connection_state' => PlayerConnectionState::Disconnected,
            'disconnected_at' => now(),
        ]);
    }

    /**
     * Siège quitté — seul état qui libère une place, et le seul que
     * {@see Player::holdingSeat()} exclut.
     */
    public function left(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connection_state' => PlayerConnectionState::Left,
            'disconnected_at' => now(),
            'left_at' => now(),
        ]);
    }

    /**
     * Pseudo masqué par le seuil de deux signaleurs distincts : **on marque au lieu
     * d'écraser**, deux pseudos neutres collisionneraient sur
     * `player_room_nickname_uq`.
     */
    public function masked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'nickname_masked_at' => now(),
        ]);
    }

    /**
     * L'état d'après l'archivage du salon : les trois identifiants d'invité sont
     * effacés dans la même transaction, ce qui ferme mécaniquement la fenêtre de
     * rattachement tardif (§ 11.1). La ligne, elle, survit.
     */
    public function archivedIdentity(): static
    {
        return $this->state(fn (array $attributes): array => [
            'nickname' => null,
            'nickname_normalized' => null,
            'player_token_hash' => null,
        ]);
    }

    /**
     * Forme repliée d'un pseudo : minuscules, diacritiques translittérés par table
     * explicite (`ext-intl` est absent), espaces compressés, borne à 20.
     *
     * Provisoire nommé — voir le préambule de la classe.
     */
    public static function normalizeNickname(?string $nickname): ?string
    {
        if ($nickname === null) {
            return null;
        }

        $folded = Str::lower(Str::ascii($nickname));
        $folded = trim((string) preg_replace('/\s+/u', ' ', $folded));

        return mb_substr($folded, 0, self::NICKNAME_MAX_LENGTH);
    }

    /**
     * Pseudo d'au plus 20 caractères : un prénom accentué plus un suffixe
     * **monotone**, qui rend les collisions impossibles et non plus seulement
     * improbables.
     *
     * Un suffixe aléatoire sur dix prénoms donnait 99 000 formes repliées
     * distinctes : remplir un salon heurtait `player_room_nickname_uq
     * (room_id, nickname_normalized)` environ une exécution sur mille — une
     * flakerie non reproductible, qui se lit comme un échec de la règle de
     * capacité alors qu'il s'agit du hasard de la fabrique, et sur la contrainte
     * même que la colonne existe pour tenir. Même patron que
     * {@see ThemeFactory::$keySequence}.
     */
    private static function nickname(): string
    {
        $name = self::NICKNAMES[self::$seatSequence % count(self::NICKNAMES)];

        return mb_substr($name.(++self::$seatSequence), 0, self::NICKNAME_MAX_LENGTH);
    }

    /**
     * Identité publique d'un siège : base32 aléatoire, **jamais dérivée de l'id**.
     */
    private static function publicId(): string
    {
        $alphabet = self::PUBLIC_ID_ALPHABET;
        $last = strlen($alphabet) - 1;
        $id = '';

        for ($index = 0; $index < self::PUBLIC_ID_LENGTH; $index++) {
            $id .= $alphabet[random_int(0, $last)];
        }

        return $id;
    }

    /**
     * Clé stable du pack prédéfini, jamais un chemin de fichier (§ 5.3), tirée
     * dans {@see AvatarPresetCatalog::keys()}, seul registre des clés (spec 40
     * § 6.3) : la fabrique n'écrit jamais `preset-%02d` elle-même.
     */
    private static function avatarPreset(): string
    {
        return Arr::random(AvatarPresetCatalog::keys());
    }
}
