<?php

use App\Support\Ops\RedactPersonalData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;

/*
|--------------------------------------------------------------------------
| Journaux sans données personnelles — spec 100 § 10.9
|--------------------------------------------------------------------------
|
| `RedactPersonalData`, branché sur les canaux de l'application, retire du
| contexte toute clé de la liste close. La preuve passe par les VRAIS canaux,
| écrits dans des fichiers temporaires et relus : un processeur qui existe
| mais n'est branché nulle part ne prouverait rien.
|
*/

/** Les canaux de l'application, `stack` compris, qui hérite de `single`. */
function logRedactionChannels(): array
{
    return ['single', 'daily', 'game', 'stack', 'stderr'];
}

beforeEach(function (): void {
    $this->logDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-logs-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->logDirectory);

    $this->stderr = $this->logDirectory.DIRECTORY_SEPARATOR.'stderr.log';

    config([
        'logging.channels.single.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'single.log',
        'logging.channels.daily.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'daily.log',
        'logging.channels.game.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'game.log',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.stderr.handler_with.stream' => $this->stderr,
    ]);

    foreach (logRedactionChannels() as $channel) {
        Log::forgetChannel($channel);
    }
});

afterEach(function (): void {
    // Les fichiers restent ouverts par leurs gestionnaires : fermés avant
    // d'effacer le répertoire, sans quoi Windows refuse la suppression.
    foreach (logRedactionChannels() as $channel) {
        $logger = Log::channel($channel)->getLogger();

        if ($logger instanceof Monolog) {
            $logger->close();
        }

        Log::forgetChannel($channel);
    }

    Context::flush();
    File::deleteDirectory($this->logDirectory);
});

it('retire du contexte de journal toute clé de la liste close des données personnelles', function (): void {
    $personal = [
        'ip' => '203.0.113.7',
        'ip_address' => '203.0.113.8',
        'nickname' => 'PseudoTemoin',
        'email' => 'temoin@example.test',
        'player_token' => 'jeton-temoin-7f3a',
        'answer' => 'ReponseTemoin',
        'submitted' => 'SaisieTemoin',
    ];

    // La liste close, exactement.
    expect(array_keys($personal))->toBe(RedactPersonalData::KEYS);

    // Des données posées par `Context` (données supplémentaires), des clés
    // imbriquées et d'autres graphies de la même donnée.
    Context::add('ip_address', '198.51.100.23');
    Context::add('gameRef', 'partie-témoin');

    // Des objets, dont le formateur écrit les clés : un modèle ou une
    // collection passent par `jsonSerialize()`, un objet de données par ses
    // propriétés publiques.
    $context = [
        ...$personal,
        'round' => 3,
        'nested' => ['nickname' => 'PseudoImbrique', 'tier' => 2, 'deeper' => ['EMAIL' => 'profond@example.test']],
        'player' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['id' => 7, 'nickname' => 'PseudoObjet', 'meta' => ['email' => 'objet@example.test']];
            }
        },
        'dto' => new class
        {
            public int $tier = 1;

            public string $answer = 'ReponseObjet';
        },
        'ipAddress' => '203.0.113.9',
        'Player-Token' => 'jeton-graphie-9c1d',
    ];

    $forbidden = [
        ...array_values($personal),
        'PseudoImbrique',
        'profond@example.test',
        'PseudoObjet',
        'objet@example.test',
        'ReponseObjet',
        '203.0.113.9',
        'jeton-graphie-9c1d',
        '198.51.100.23',
    ];

    foreach (logRedactionChannels() as $channel) {
        Log::channel($channel)->warning("Témoin du canal {$channel} pour {nickname}", $context);
    }

    $files = File::files($this->logDirectory);
    $written = implode("\n", array_map(static fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()), $files));

    // Chaque canal a écrit sa ligne…
    foreach (logRedactionChannels() as $channel) {
        expect($written)->toContain("Témoin du canal {$channel}");
    }

    // … sans aucune des valeurs personnelles, sous aucune graphie, et le
    // marqueur du message n'a pas été remplacé par le pseudo.
    foreach ($forbidden as $value) {
        expect($written)->not->toContain($value);
    }

    expect($written)->toContain('{nickname}');

    // Le canal `game` écrit des lignes JSON : le reste du contexte y est
    // intact, imbrication comprise.
    $gameFiles = array_values(array_filter(
        $files,
        static fn (SplFileInfo $file): bool => str_starts_with($file->getFilename(), 'game-'),
    ));

    expect($gameFiles)->toHaveCount(1);

    $record = json_decode(trim((string) file_get_contents($gameFiles[0]->getPathname())), true, flags: JSON_THROW_ON_ERROR);

    expect($record['context'])->toBe([
        'round' => 3,
        'nested' => ['tier' => 2, 'deeper' => []],
        'player' => ['id' => 7, 'meta' => []],
        'dto' => ['tier' => 1],
    ])
        ->and($record['extra'])->toBe(['gameRef' => 'partie-témoin'])
        ->and($record['level_name'])->toBe('WARNING');

    // Un objet qui se référence lui-même ne fait pas boucler le processeur.
    $cycle = new stdClass;
    $cycle->nickname = 'PseudoCycle';
    $cycle->self = $cycle;

    expect((string) json_encode(RedactPersonalData::redact(['cycle' => $cycle])))
        ->not->toContain('PseudoCycle')
        ->toContain(RedactPersonalData::DEPTH_MARKER);
});
