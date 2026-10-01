<?php

use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
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
use App\Models\WrongAnswer;
use App\Support\Answers\ChoicesPresenter;
use App\ValueObjects\Answers\ChoicesPayload;
use App\ValueObjects\Answers\SeatInputView;
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
        'player' => [Player::class, [
            'id',
            'room_id',
            'user_id',
            'player_token_hash',
            'solo_token_hash',
            'active_seat_token',
            'nickname_normalized',
            'kicked_at',
        ]],
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
        'round_choice_set' => [RoundChoiceSet::class, ['choice_1', 'choice_2', 'choice_3', 'choice_4', 'rendered_locale']],
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
        'wrong_answer' => [WrongAnswer::class, ['player_id', 'submitted_text', 'submitted_normalized']],
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

it("ne sérialise jamais nickname_normalized ni kicked_at d'un siège", function () {
    // Spec 10 § 7.1 (E10-34) : la forme repliée du pseudo n'est « jamais
    // affichée », et l'instant d'expulsion ne regarde que le serveur. Un siège
    // EXPULSÉ, pour que `kicked_at` soit réellement renseignée : sur un siège
    // ordinaire, la clé manquerait par nullité et le test passerait à vide.
    $seat = Player::factory()->kicked()->create();

    expect($seat->nickname_normalized)->not->toBeNull()
        ->and($seat->kicked_at)->not->toBeNull();

    $serialized = $seat->refresh()->toArray();
    $payload = json_encode($serialized, JSON_THROW_ON_ERROR);

    expect(array_key_exists('nickname_normalized', $serialized))->toBeFalse()
        ->and(array_key_exists('kicked_at', $serialized))->toBeFalse()
        ->and($payload)->not->toContain('"'.$seat->nickname_normalized.'"')
        ->and($seat->getHidden())->toContain('nickname_normalized', 'kicked_at');

    // Ce qui reste visible : le public_id, seule adresse d'un siège côté client.
    expect($serialized)->toHaveKey('public_id');
});

it('ChoicesPayload ne porte aucun identifiant de film', function () {
    // Spec 70 § 10.8, contrat C11 : les quatre chaînes, le drapeau et `lang`,
    // rien d'autre. La forme est prouvée sur la CLASSE — aucune propriété,
    // aucun paramètre de plus — et sur une charge réelle, rendue par le
    // présentateur pour une manche aux trois leurres posés.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(ChoicesPayload::class))->getProperties(),
    );
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(ChoicesPayload::class, '__construct'))->getParameters(),
    );

    expect($properties)->toBe(['choices', 'useOriginalTitle', 'lang'])
        ->and($parameters)->toBe(['choices', 'useOriginalTitle', 'lang'])
        ->and(is_subclass_of(ChoicesPayload::class, JsonSerializable::class))->toBeFalse();

    $round = Round::factory()->withDecoys()->create();
    RoundChoiceSet::factory()->forRound($round)->create();
    $seat = RoundPlayer::factory()
        ->forRound($round, Player::factory()->create())
        ->withChoices(Locale::English)
        ->create();

    $payload = app(ChoicesPresenter::class)->forSeat($seat)?->toArray() ?? throw new LogicException;
    $movieIds = [$round->movie_id, $round->decoy_movie_id_1, $round->decoy_movie_id_2, $round->decoy_movie_id_3];

    expect(array_keys($payload))->toBe(['choices', 'useOriginalTitle', 'lang']);

    // Pas un entier dans la charge : ni identifiant de film, ni index de la
    // bonne réponse.
    array_walk_recursive($payload, static function (mixed $value) use ($movieIds): void {
        expect(is_int($value))->toBeFalse()
            ->and(in_array($value, $movieIds, true))->toBeFalse();
    });

    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('movie')
        ->not->toContain('decoy')
        ->not->toContain('"id"')
        ->and($payload['lang'])->toBe(Locale::English->bcp47());

    // `lang` est la locale ATTEINTE de la ligne, jamais sa locale : une ligne
    // anglaise rendue en français au rang 2 dit `fr`, une ligne de titres
    // originaux ne dit rien.
    foreach ([Locale::French, null] as $reached) {
        $other = Round::factory()->withDecoys()->create();
        RoundChoiceSet::factory()->forRound($other)->renderedIn($reached)->create();
        $otherSeat = RoundPlayer::factory()
            ->forRound($other, Player::factory()->create())
            ->withChoices(Locale::English)
            ->create();

        expect(app(ChoicesPresenter::class)->forSeat($otherSeat)?->toArray()['lang'])->toBe($reached?->bcp47());
    }
});

it('SeatInputView ne sort que les données du siège demandeur', function () {
    // Spec 70 § 16, contrat C10 § 3 (R-26) : la vue d'UN siège, destinataire
    // unique de la resynchronisation. La forme est prouvée sur la CLASSE —
    // aucune propriété de plus — et sur une manche réelle où trois sièges
    // ont trois états : chacun ne reçoit que les siens.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(SeatInputView::class))->getProperties(),
    );

    expect($properties)->toBe(['inputState', 'attemptsLeft', 'choices', 'lockRank', 'score'])
        ->and(is_subclass_of(SeatInputView::class, JsonSerializable::class))->toBeFalse();

    $round = Round::factory()->withDecoys()->create();
    RoundChoiceSet::factory()->forRound($round)->create();

    $seat = static fn (Closure $state): RoundPlayer => $state(
        RoundPlayer::factory()->forRound($round, Player::factory()->create(['room_id' => $round->room_id]))->withChoices(Locale::English),
    )->create();

    $first = $seat(static fn ($factory) => $factory->locked());
    $second = $seat(static fn ($factory) => $factory->locked());
    $wrong = $seat(static fn ($factory) => $factory->qcmWrong());

    $firstGuess = Guess::factory()->forRound($round, Player::query()->findOrFail($first->player_id))->withRank(1)->atTier(2)->withSpeedBonus(40)->create();
    $secondGuess = Guess::factory()->forRound($round, Player::query()->findOrFail($second->player_id))->withRank(2)->atTier(3)->create();

    $expected = static fn (RoundPlayer $roundPlayer, ?Guess $guess): array => [
        'inputState' => $roundPlayer->input_state->value,
        'attemptsLeft' => 0,
        'choices' => app(ChoicesPresenter::class)->forSeat($roundPlayer)?->toArray(),
        'locked' => $guess === null ? null : [
            'lockRank' => $guess->lock_rank,
            'tierIndex' => $guess->tier_index,
            'pointsTier' => $guess->points_tier,
            'pointsBonus' => $guess->points_bonus,
            'pointsTotal' => $guess->points_total,
        ],
    ];

    $views = [
        [SeatInputView::forSeat($first)->toArray(), $expected($first, $firstGuess)],
        [SeatInputView::forSeat($second)->toArray(), $expected($second, $secondGuess)],
        [SeatInputView::forSeat($wrong)->toArray(), $expected($wrong, null)],
    ];

    // Chaque siège : SON état, SES points, SES propositions permutées pour
    // lui — jamais le rang, les points ni l'état d'un autre.
    foreach ($views as [$view, $own]) {
        expect($view)->toBe($own)
            ->and(array_keys($view))->toBe(['inputState', 'attemptsLeft', 'choices', 'locked'])
            ->and(array_keys($view['choices'] ?? []))->toBe(['choices', 'useOriginalTitle', 'lang']);

        // La structure exacte ci-dessus fixe toutes les clés : aucune ne
        // nomme un identifiant. Et aucune valeur ne porte le `public_id` d'un
        // siège, le sien compris : `SelfState` le porte déjà, hors de la vue.
        $json = json_encode($view, JSON_THROW_ON_ERROR);

        foreach ([$first, $second, $wrong] as $any) {
            expect($json)->not->toContain((string) Player::query()->findOrFail($any->player_id)->public_id);
        }
    }

    expect($views[0][0]['locked'])->not->toBe($views[1][0]['locked'])
        ->and($views[0][0]['inputState'])->toBe(RoundPlayerInputState::Locked->value)
        ->and($views[2][0]['inputState'])->toBe(RoundPlayerInputState::QcmWrong->value);
});
