<?php

use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\LinkedAccount;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[Hidden]` est une règle de SÉCURITÉ (§ 1.7), et c'est la seule règle du projet
 * dont la violation est invisible en relecture : rien ne lève, rien ne se journalise,
 * `composer ci:check` reste vert, et la fuite part dans les props Inertia de toutes
 * les pages, SSR compris.
 *
 * Ce fichier outille les assertions que la spec 10 déclare obligatoires — § 5.3,
 * § 6.2, § 7.1, § 7.8 et la ligne du tableau de renvoi vers la spec 60 — **plus le
 * cas que leur formulation laisse passer** : une RELATION CHARGÉE.
 * `relationsToArray()` appelle `getArrayableRelations()` puis `getArrayableItems()`,
 * donc `$hidden` filtre aussi les relations, par leur clé camelCase. Sans cette
 * moitié, « `$round->toArray()` ne contient pas `movie_id` » passe au vert sur une
 * charge utile qui porte `{"movie":{"title_original":"Inception"}}`.
 */

/**
 * Clés interdites par modèle — liste consolidée des § 5.1, § 6.2 et § 7.
 *
 * @return array<string, array{class-string<Model>, list<string>}>
 */
function forbiddenSerializedKeys(): array
{
    return [
        'users' => [User::class, [
            'real_name',
            'password',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'remember_token',
            'plan',
            'avatar_provider_path',
            'avatar_provider_hidden_at',
            'terms_accepted_at',
            'terms_version',
            'age_confirmed_at',
            'anonymized_at',
            'last_login_at',
        ]],
        'room' => [Room::class, ['id', 'host_player_id', 'settings_version']],
        'player' => [Player::class, ['id', 'room_id', 'user_id', 'player_token_hash', 'active_seat_token']],
        'game' => [Game::class, ['room_id', 'draw_seed', 'draw_pool_size', 'settings_snapshot']],
        'game_player' => [GamePlayer::class, ['player_id']],
        'round' => [Round::class, [
            'game_id',
            'room_id',
            'movie_id',
            'decoy_movie_id_1',
            'decoy_movie_id_2',
            'decoy_movie_id_3',
            'choices_use_original_title',
        ]],
        'round_tier' => [RoundTier::class, ['frame_id', 'served_frame_id', 'frame_level', 'serve_token']],
        'round_player' => [RoundPlayer::class, ['player_id']],
        'round_choice_set' => [RoundChoiceSet::class, ['choice_1', 'choice_2', 'choice_3', 'choice_4']],
        'guess' => [Guess::class, [
            'player_id',
            'answer_key_id',
            'answer_key_normalized',
            'submitted_normalized',
            'tier_index',
            'points_tier',
            'points_bonus',
            'points_total',
        ]],
        'frame' => [Frame::class, [
            'id',
            'movie_id',
            'tmdb_file_path',
            'source_hash',
            'published_hash',
            'crop_x',
            'crop_y',
            'crop_width',
            'crop_height',
            'game_path',
            'master_path',
        ]],
        'seen_frame' => [SeenFrame::class, ['id', 'room_id', 'frame_id']],
        'admin_action' => [AdminAction::class, ['actor_id']],
        'linked_account' => [LinkedAccount::class, ['provider_user_id', 'provider_email', 'provider_avatar_url']],
    ];
}

it('never serializes a column the schema declares hidden', function (string $model, array $forbidden) {
    $instance = $model::factory()->create();

    $serialized = $instance->toArray();

    expect($serialized)->toBeArray();

    foreach ($forbidden as $key) {
        expect(array_key_exists($key, $serialized))->toBeFalse(
            "[{$model}] sérialise [{$key}] : `#[Hidden]` est une règle de sécurité, pas de cosmétique (§ 1.7).",
        );
    }
})->with(forbiddenSerializedKeys());

it('keeps visible on users exactly what the spec leaves visible', function () {
    // § 5.1 : `role`, `locale`, `avatar_kind` et `avatar_preset` ne sont PAS cachées.
    // Les masquer casserait la garde d'affichage du back-office et le sélecteur de langue.
    // L'assertion porte sur `$hidden` et non sur la charge utile : `avatar_kind` et
    // `avatar_preset` sont nullables SANS défaut, donc absentes de l'instance tant
    // qu'aucun avatar n'a été choisi — une absence de valeur, jamais un masquage.
    $user = User::factory()->create();

    expect($user->toArray())->toHaveKeys(['role', 'locale']);

    foreach (['role', 'locale', 'avatar_kind', 'avatar_preset'] as $visible) {
        expect(in_array($visible, $user->getHidden(), true))->toBeFalse(
            "[users.{$visible}] est nommément laissée visible par le § 5.1.",
        );
    }
});

it('appends the user avatar as a strict string or null', function () {
    // § 5.3, test nommé : un `Attribute` rendant l'objet complet produirait
    // `"avatar":{"url":…}`, que `user-info.tsx` poserait en `src="[object Object]"`.
    $serialized = User::factory()->create()->toArray();

    expect($serialized)->toHaveKey('avatar');
    expect($serialized['avatar'] === null || is_string($serialized['avatar']))->toBeTrue(
        'User::$avatar doit être une chaîne ou null, jamais autre chose.',
    );
});

it('keeps the lock rank visible on a guess', function () {
    // § 7.6 : avant la révélation, la diffusion porte `player.public_id` ET `lock_rank`,
    // et rien d'autre. Cacher `lock_rank` viderait le badge « a trouvé » de son sens.
    $serialized = Guess::factory()->create()->toArray();

    expect($serialized)->toHaveKey('lock_rank');
});

it('never publishes the answer through a loaded round relation', function () {
    $round = Round::factory()->create();

    $serialized = $round->load('movie')->toArray();
    $payload = json_encode($serialized, JSON_THROW_ON_ERROR);

    expect(array_key_exists('movie', $serialized))->toBeFalse();
    expect($payload)->not->toContain('title_original');
    expect($payload)->not->toContain($round->movie->title_original);
});

it('never publishes the movie of a loaded served frame', function () {
    $frame = Frame::factory()->create();
    $tier = RoundTier::factory()->create(['frame_id' => $frame->id, 'served_frame_id' => $frame->id]);

    $serialized = $tier->load('frame', 'servedFrame')->toArray();

    expect(array_key_exists('served_frame', $serialized))->toBeFalse();
    expect(array_key_exists('frame', $serialized))->toBeFalse();
    expect(json_encode($serialized, JSON_THROW_ON_ERROR))->not->toContain('movie_id');
});

it('never publishes the account of another player through the seat', function () {
    $user = User::factory()->create();
    $player = Player::factory()->create(['user_id' => $user->id]);

    $serialized = $player->load('user')->toArray();
    $payload = json_encode($serialized, JSON_THROW_ON_ERROR);

    expect(array_key_exists('user', $serialized))->toBeFalse();
    expect($payload)->not->toContain((string) $user->email);
    expect($payload)->not->toContain('"role"');
});
