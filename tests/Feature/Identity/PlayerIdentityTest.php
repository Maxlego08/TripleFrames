<?php

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\AvatarRef;
use App\Enums\AvatarKind;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\User;
use App\Support\Identity\PlayerIdentity;
use Database\Factories\PlayerFactory;

/*
|--------------------------------------------------------------------------
| Identité affichée d'un siège — spec 40 § 7, contrat C5 (L40-6)
|--------------------------------------------------------------------------
|
| `PlayerIdentity` est la SEULE sérialisation de ce qu'un siège montre aux
| autres : `publicId`, pseudo, masquage, avatar. Elle est diffusée au salon,
| identique pour tous — `SeatView` de `60` l'étend, le podium de `80`
| l'embarque —, donc toute clé de trop part chez des inconnus réunis par un
| lien partagé.
|
| L'avatar passe par l'accesseur serveur unique de la ligne (`avatarRef()`) :
| prédéfini, puis initiales. Le masquage n'a pas de geste au J1 (règle du
| J2) : sa FORME est prouvée ici, sur des sièges fabriqués par
| `PlayerFactory::masked()`.
|
*/

/**
 * Clés de la charge, à tous les niveaux : seules celles-ci ont le droit d'y
 * figurer.
 *
 * @param  array<mixed>  $payload
 * @return list<string>
 */
function playerIdentityKeys(array $payload, string $prefix = ''): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        $keys[] = $prefix.$key;

        if (is_array($value)) {
            array_push($keys, ...playerIdentityKeys($value, $prefix.$key.'.'));
        }
    }

    return $keys;
}

/** Charge encodée comme elle partirait au client. */
function playerIdentityJson(PlayerIdentity $identity): string
{
    return json_encode($identity->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

it("sérialise l'identité d'un siège avec son public_id, son pseudo et son avatar seulement", function () {
    $preset = AvatarPresetCatalog::keys()[4];

    // Un siège qui a tout à cacher : rattaché à un compte (dont le nom n'est
    // jamais recopié, I5.11), expulsé (`kicked_at` posé), jeton haché, jeton
    // d'onglet, forme repliée distincte du pseudo affiché.
    $user = User::factory()->create(['name' => 'Titulaire Du Compte']);
    $seat = Player::factory()->forUser($user)->kicked()->withNickname('Jean-Luc Zoé')->create([
        'avatar_kind' => AvatarKind::Preset,
        'avatar_preset' => $preset,
    ])->refresh()->load('user', 'room');

    expect($seat->nickname_normalized)->toBe('jeanluczoe')
        ->and($seat->kicked_at)->not->toBeNull()
        ->and($seat->player_token_hash)->not->toBeNull()
        ->and($seat->active_seat_token)->not->toBeNull();

    $identity = PlayerIdentity::fromSeat($seat);

    expect($identity->toArray())->toBe([
        'publicId' => $seat->public_id,
        'nickname' => 'Jean-Luc Zoé',
        'masked' => false,
        'avatar' => [
            'kind' => AvatarKind::Preset->value,
            'url' => AvatarRef::presetUrl($preset),
            'altKey' => AvatarRef::ALT_KEY_PRESET,
            'initials' => 'JZ',
        ],
    ]);

    // L'avatar est celui de l'accesseur serveur unique de la ligne.
    expect($identity->toArray()['avatar'])->toBe($seat->avatarRef()->toArray());

    // Aucune autre clé, à aucun niveau, et aucune valeur interne sous une
    // autre clé.
    expect(playerIdentityKeys($identity->toArray()))->toBe([
        'publicId', 'nickname', 'masked', 'avatar',
        'avatar.kind', 'avatar.url', 'avatar.altKey', 'avatar.initials',
    ]);

    $json = playerIdentityJson($identity);

    foreach ([
        'nickname_normalized' => (string) $seat->nickname_normalized,
        'player_token_hash' => (string) $seat->player_token_hash,
        'active_seat_token' => (string) $seat->active_seat_token,
        'kicked_at' => (string) $seat->kicked_at?->format('Y-m-d H:i:s'),
        'users.name' => $user->name,
        'users.email' => (string) $user->email,
        'room.room_code' => (string) $seat->room?->room_code,
    ] as $column => $value) {
        expect($value)->not->toBe('')
            ->and(str_contains($json, $value))->toBeFalse("{$column} fuit dans l'identité");
    }

    // Repli terminal de la chaîne : sans prédéfini, les initiales seules.
    $initialsOnly = Player::factory()->withNickname('Ada Lovelace')->create([
        'avatar_kind' => null,
        'avatar_preset' => null,
    ]);

    expect(PlayerIdentity::fromSeat($initialsOnly)->toArray())->toBe([
        'publicId' => $initialsOnly->public_id,
        'nickname' => 'Ada Lovelace',
        'masked' => false,
        'avatar' => [
            'kind' => null,
            'url' => null,
            'altKey' => AvatarRef::ALT_KEY_INITIALS,
            'initials' => 'AL',
        ],
    ]);

    // Au J1, aucun geste ne masque : quel que soit l'état du siège, `masked`
    // est faux et le pseudo présent.
    foreach ([
        'connecté' => Player::factory(),
        'déconnecté' => Player::factory()->disconnected(),
        'parti' => Player::factory()->left(),
        'expulsé' => Player::factory()->kicked(),
        'solo' => Player::factory()->solo(),
    ] as $state => $factory) {
        $shown = PlayerIdentity::fromSeat($factory->create())->toArray();

        expect($shown['masked'])->toBeFalse("siège {$state}")
            ->and($shown['nickname'])->not->toBeNull("siège {$state}");
    }

    // L'archivage efface le pseudo : l'identité le rend nul, sans le
    // prétendre masqué, et les initiales retombent sur le caractère neutre.
    $archived = PlayerIdentity::fromSeat(Player::factory()->archivedIdentity()->create())->toArray();

    expect($archived['nickname'])->toBeNull()
        ->and($archived['masked'])->toBeFalse()
        ->and($archived['avatar']['initials'])->toBe(AvatarRef::FALLBACK_INITIAL);

    // Un siège qui vient d'être inséré, sans `nickname_masked_at` dans ses
    // attributs, porte le défaut SQL (NULL) : il est lu non masqué.
    $attributes = Player::factory()->raw();
    unset($attributes['nickname_masked_at']);

    $inserted = (new Player)->forceFill($attributes);
    $inserted->save();

    expect(array_key_exists('nickname_masked_at', $inserted->getAttributes()))->toBeFalse()
        ->and(PlayerIdentity::fromSeat($inserted)->toArray()['masked'])->toBeFalse();

    // Pendant la partie : le pseudo et l'avatar GELÉS au lancement, jamais
    // ceux que le siège a pris depuis ; `publicId` reste celui du siège.
    $participation = GamePlayer::factory()->frozenFrom($seat)->create();

    $seat->update([
        'nickname' => 'Autre Pseudo',
        'nickname_normalized' => 'autrepseudo',
        'avatar_preset' => AvatarPresetCatalog::keys()[7],
    ]);

    $participation = GamePlayer::query()
        ->with('player:'.implode(',', PlayerIdentity::FROZEN_SEAT_COLUMNS))
        ->findOrFail($participation->id);

    $frozen = PlayerIdentity::fromGamePlayer($participation);

    expect($frozen->toArray())->toBe([
        'publicId' => $seat->public_id,
        'nickname' => 'Jean-Luc Zoé',
        'masked' => false,
        'avatar' => [
            'kind' => AvatarKind::Preset->value,
            'url' => AvatarRef::presetUrl($preset),
            'altKey' => AvatarRef::ALT_KEY_PRESET,
            'initials' => 'JZ',
        ],
    ])
        ->and($frozen->toArray()['avatar'])->toBe($participation->avatarRef()->toArray())
        ->and(playerIdentityKeys($frozen->toArray()))->toBe(playerIdentityKeys($identity->toArray()))
        ->and(playerIdentityJson($frozen))->not->toContain('Autre Pseudo');

    // La relation `player` est exigée, avec `public_id` et
    // `nickname_masked_at` : lue sans eux, l'identité refuse au lieu de
    // deviner.
    $unloaded = GamePlayer::query()->findOrFail($participation->id);

    expect(fn () => PlayerIdentity::fromGamePlayer($unloaded))->toThrow(LogicException::class);

    foreach (['player:id,public_id' => 'nickname_masked_at', 'player:id,nickname_masked_at' => 'public_id'] as $partial => $missing) {
        $participation = GamePlayer::query()->with($partial)->findOrFail($participation->id);

        expect(fn () => PlayerIdentity::fromGamePlayer($participation))->toThrow(LogicException::class, $missing);
    }
});

it("ne laisse jamais fuiter le pseudo ni ses initiales d'un siège masqué", function () {
    $preset = AvatarPresetCatalog::keys()[2];

    // Initiales « UO » : ni U ni O n'appartiennent à l'alphabet de
    // `public_id`, et ni l'URL ni la clé d'`alt` ne portent de capitale. Leur
    // absence de la charge prouve donc qu'elles n'y sont nulle part, sans
    // dépendre du hasard du `public_id`.
    expect(PlayerFactory::PUBLIC_ID_ALPHABET)->not->toContain('U')
        ->and(PlayerFactory::PUBLIC_ID_ALPHABET)->not->toContain('O');

    $seat = Player::factory()->withNickname('Ursula Oliveira')->create([
        'avatar_kind' => AvatarKind::Preset,
        'avatar_preset' => $preset,
    ]);

    /** @var list<string> $secrets */
    $secrets = ['Ursula', 'Oliveira', 'UO', (string) $seat->nickname_normalized];

    // Témoin : non masqué, le même siège montre pseudo et initiales — le test
    // saurait voir la fuite.
    $shown = playerIdentityJson(PlayerIdentity::fromSeat($seat));

    expect($shown)->toContain('Ursula Oliveira')
        ->and($shown)->toContain('"UO"');

    // Gel au lancement, AVANT le masquage : l'affichage gelé porte le pseudo.
    $participation = GamePlayer::factory()->frozenFrom($seat)->create();

    expect($participation->display_nickname)->toBe('Ursula Oliveira');

    // Le masquage marque, n'écrase pas (10 § 7.1) : le pseudo reste en base.
    $seat->forceFill(['nickname_masked_at' => now()])->save();

    expect($seat->refresh()->nickname)->toBe('Ursula Oliveira');

    $masked = PlayerIdentity::fromSeat($seat);

    expect($masked->toArray())->toBe([
        'publicId' => $seat->public_id,
        'nickname' => null,
        'masked' => true,
        'avatar' => [
            'kind' => AvatarKind::Preset->value,
            'url' => AvatarRef::presetUrl($preset),
            'altKey' => AvatarRef::ALT_KEY_PRESET,
            'initials' => AvatarRef::FALLBACK_INITIAL,
        ],
    ]);

    // Le masquage s'applique aussi à l'affichage gelé : il se lit sur le
    // siège vivant, jamais sur la partie.
    $participation = GamePlayer::query()
        ->with('player:'.implode(',', PlayerIdentity::FROZEN_SEAT_COLUMNS))
        ->findOrFail($participation->id);

    $frozen = PlayerIdentity::fromGamePlayer($participation);

    expect($participation->display_nickname)->toBe('Ursula Oliveira')
        ->and($frozen->toArray())->toBe($masked->toArray());

    foreach (['lobby' => $masked, 'partie' => $frozen] as $where => $identity) {
        $json = playerIdentityJson($identity);

        foreach ($secrets as $secret) {
            expect(str_contains($json, $secret))->toBeFalse("{$where} : le pseudo masqué fuit");
        }
    }

    // Même forme pour un siège masqué sans prédéfini : le repli sur les
    // initiales n'en montre aucune.
    $initialsOnly = Player::factory()->withNickname('Ulysse Onfray')->masked()->create([
        'avatar_kind' => null,
        'avatar_preset' => null,
    ]);

    expect(PlayerIdentity::fromSeat($initialsOnly)->toArray())->toBe([
        'publicId' => $initialsOnly->public_id,
        'nickname' => null,
        'masked' => true,
        'avatar' => [
            'kind' => null,
            'url' => null,
            'altKey' => AvatarRef::ALT_KEY_INITIALS,
            'initials' => AvatarRef::FALLBACK_INITIAL,
        ],
    ])
        ->and(playerIdentityJson(PlayerIdentity::fromSeat($initialsOnly)))->not->toContain('Ulysse')
        ->and(playerIdentityJson(PlayerIdentity::fromSeat($initialsOnly)))->not->toContain('UO');

    // Un siège relu SANS la colonne de masquage le croirait visible : la
    // construction refuse, au lieu de publier le pseudo.
    $partial = Player::query()
        ->select(['id', 'public_id', 'nickname', 'avatar_kind', 'avatar_preset'])
        ->findOrFail($seat->id);

    expect($partial->nickname)->toBe('Ursula Oliveira')
        ->and(fn () => PlayerIdentity::fromSeat($partial))->toThrow(LogicException::class, 'nickname_masked_at');
});
