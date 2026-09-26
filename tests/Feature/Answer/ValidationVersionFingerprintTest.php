<?php

use App\Actions\Room\LaunchGame;
use App\Enums\Locale;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Player;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Identity\PlayerToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\AnswerRuleFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Version de la règle de validation — spec 70 § 12, contrat C12, lots L70-1 et L70-5
|--------------------------------------------------------------------------
|
| `game.validation_version` dit sous quelle règle une distance a été
| acceptée. L'empreinte en est la garde : tout changement de configuration
| (`config/catalog.php`) ou de constante sans nouvelle version fait échouer
| ce fichier, et le remède est d'incrémenter `AnswerRules::VERSION` puis
| d'AJOUTER une empreinte et des fixtures, jamais de réécrire celles d'une
| version existante.
|
*/

it('l\'empreinte des paramètres de la version courante est figée', function (): void {
    $fingerprint = AnswerRules::fingerprint();

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fingerprint)->toBe(AnswerRuleFixtures::expectedFingerprint())
        ->and(AnswerRules::fingerprint())->toBe($fingerprint);

    // Chaque paramètre de configuration couvert par la version change
    // l'empreinte : un déploiement qui les toucherait sans nouvelle version
    // échoue ici.
    Config::set('catalog.min_prefix_length', AnswerKeyNormalizer::minPrefixLength() + 1);

    expect(AnswerRules::fingerprint())->not->toBe($fingerprint);

    Config::set('catalog.min_prefix_length', AnswerKeyNormalizer::DEFAULT_MIN_PREFIX_LENGTH);
    Config::set('catalog.subtitle_separators', [...AnswerKeyNormalizer::DEFAULT_SUBTITLE_SEPARATORS, ' / ']);

    expect(AnswerRules::fingerprint())->not->toBe($fingerprint);

    Config::set('catalog.subtitle_separators', AnswerKeyNormalizer::DEFAULT_SUBTITLE_SEPARATORS);

    expect(AnswerRules::fingerprint())->toBe($fingerprint);
});

it('seules l\'empreinte et les fixtures de la version courante sont exécutées', function (): void {
    // L'historique est conservé, sans trou et sans version à venir : une
    // entrée d'empreinte et un fichier de paires par version, de 1 à la
    // courante.
    $versions = range(1, AnswerRules::VERSION);

    expect(array_keys(AnswerRuleFixtures::fingerprints()))->toBe($versions)
        ->and(AnswerRuleFixtures::normalizerVersionsOnDisk())->toBe($versions);

    // Le chargeur se résout par la version courante, jamais par un balayage.
    expect(AnswerRuleFixtures::normalizerPath())
        ->toEndWith(DIRECTORY_SEPARATOR.'normalizer-v'.AnswerRules::VERSION.'.php')
        ->and(AnswerRuleFixtures::expectedFingerprint())
        ->toBe(AnswerRuleFixtures::fingerprints()[AnswerRules::VERSION]);

    // Simulation : à côté des fichiers de la version courante, ceux d'une
    // autre version portent des attentes que le code courant ne produit pas.
    // Le chargeur les ignore — s'il les lisait, la suite échouerait par
    // construction, puisque le code n'implémente que la version courante.
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'answer-fixtures-'.bin2hex(random_bytes(6));
    $other = AnswerRules::VERSION + 1;

    File::ensureDirectoryExists($directory);

    try {
        File::copy(AnswerRuleFixtures::normalizerPath(), AnswerRuleFixtures::normalizerPath($directory));
        File::put(
            AnswerRuleFixtures::normalizerPath($directory, $other),
            "<?php\n\nreturn ['normalize' => [['Rocky IV', 'rocky iv']], 'fold' => [['José', 'José']]];\n",
        );
        File::put($directory.DIRECTORY_SEPARATOR.'fingerprints.php', sprintf(
            "<?php\n\nreturn [%d => '%s', %d => '%s'];\n",
            AnswerRules::VERSION,
            AnswerRules::fingerprint(),
            $other,
            str_repeat('0', 64),
        ));

        expect(AnswerRuleFixtures::normalizerVersionsOnDisk($directory))->toBe([AnswerRules::VERSION, $other])
            ->and(AnswerRuleFixtures::normalizer($directory))->toBe(AnswerRuleFixtures::normalizer())
            ->and(AnswerRuleFixtures::expectedFingerprint($directory))->toBe(AnswerRules::fingerprint());

        foreach (AnswerRuleFixtures::normalizer($directory)['normalize'] as [$input, $expected]) {
            expect(AnswerKeyNormalizer::normalize($input))->toBe($expected);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('la fabrique de partie écrit la version courante de la règle', function (): void {
    expect(Game::factory()->make()->validation_version)->toBe(AnswerRules::VERSION);
});

it('game.validation_version est écrite depuis AnswerRules::VERSION au lancement', function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);

    // Un vrai lancement, par l'action du salon : l'hôte, un invité, un vivier
    // qui remplit juste le tirage.
    $settings = RoomSettings::fromInput(['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT]);
    [$room, $host] = LobbyWrites::hostedRoom(PlayerToken::mint(Locale::French), $settings);
    Player::factory()->for($room)->create();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());

    $outcome = app(LaunchGame::class)->handle($room, $host);

    expect($outcome->isLaunched())->toBeTrue()
        ->and($outcome->game)->toBeInstanceOf(Game::class);

    $gameId = $outcome->game?->id;

    // La colonne telle que la base la porte, sans cast.
    expect(DB::table('game')->where('id', $gameId)->value('validation_version'))->toEqual(AnswerRules::VERSION);

    // Et son seul écrivain applicatif est `OpenGame`, qui la lit sur
    // `AnswerRules::VERSION`, jamais sur un littéral : au passage à la
    // version 2, toute partie lancée l'écrira sans autre changement.
    $writers = [];

    foreach (File::allFiles(app_path()) as $file) {
        $source = $file->getContents();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        preg_match_all("/'validation_version'\s*=>\s*([^,\n]+)/", $source, $pairs);
        preg_match_all('/->validation_version\s*=(?!=)\s*([^;\n]+)/', $source, $assignments);

        foreach ([...$pairs[1], ...$assignments[1]] as $value) {
            // La déclaration de cast du modèle n'écrit rien.
            if ($relative === 'Models/Game.php' && trim($value) === "'integer'") {
                continue;
            }

            $writers[] = $relative.' : '.trim($value);
        }
    }

    expect($writers)->toBe(['Actions/Game/OpenGame.php : AnswerRules::VERSION']);
});
