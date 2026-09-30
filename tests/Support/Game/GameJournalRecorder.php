<?php

namespace Tests\Support\Game;

use App\Support\Game\GameJournal;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;

/**
 * Le VRAI canal `game` (spec 100 § 10.9 : pilote `daily`, lignes JSON,
 * processeur `RedactPersonalData`), écrit dans un répertoire temporaire et
 * relu — pour les tests du journal du moteur (spec 60 § 4.7, lot L60-7).
 *
 * Même montage que `BruteForceReportTest` : une ligne qui partirait sur un
 * autre canal, ou avec une donnée de joueur dans son contexte, ne passerait
 * pas.
 */
final class GameJournalRecorder
{
    private function __construct(private readonly string $directory) {}

    /** Redirige le canal `game` vers un répertoire neuf. */
    public static function start(): self
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-game-journal-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);

        config(['logging.channels.'.GameJournal::CHANNEL.'.path' => $directory.DIRECTORY_SEPARATOR.'game.log']);
        Log::forgetChannel(GameJournal::CHANNEL);

        return new self($directory);
    }

    /**
     * Les lignes JSON écrites, dans l'ordre ; celles d'un libellé seulement si
     * `$message` est donné.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(?string $message = null): array
    {
        $lines = [];

        foreach (File::files($this->directory) as $file) {
            foreach (preg_split('/\R/', trim((string) file_get_contents($file->getPathname()))) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if ($message === null || ($decoded['message'] ?? null) === $message) {
                    $lines[] = $decoded;
                }
            }
        }

        return $lines;
    }

    /**
     * Les contextes des lignes d'un libellé, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    public function contexts(string $message): array
    {
        return array_map(
            static function (array $line): array {
                /** @var array<string, mixed> $context */
                $context = $line['context'] ?? [];

                return $context;
            },
            $this->lines($message),
        );
    }

    /** Le texte brut de tout le journal. */
    public function raw(): string
    {
        $raw = '';

        foreach (File::files($this->directory) as $file) {
            $raw .= (string) file_get_contents($file->getPathname());
        }

        return $raw;
    }

    /**
     * Ferme le fichier (Windows refuse d'effacer un fichier ouvert) et
     * efface le répertoire.
     */
    public function stop(): void
    {
        $logger = Log::channel(GameJournal::CHANNEL)->getLogger();

        if ($logger instanceof Monolog) {
            $logger->close();
        }

        Log::forgetChannel(GameJournal::CHANNEL);
        File::deleteDirectory($this->directory);
    }
}
