<?php

namespace Tests\Support\Answers;

use App\Support\Answers\AnswerRules;
use RuntimeException;

/**
 * Lecture des fixtures de la règle de validation (spec 70 § 5.6 et § 12) :
 * `tests/Fixtures/answers/normalizer-v{n}.php` et `fingerprints.php`.
 *
 * **Seule la version courante est lue.** Le chemin des paires et l'entrée
 * d'empreinte se résolvent par {@see AnswerRules::VERSION}, jamais par un
 * balayage du répertoire : le code n'implémente que la version courante, et
 * rejouer les attentes d'une version antérieure échouerait par construction.
 * Les fichiers des versions précédentes restent sur disque, jamais exécutés
 * ni réécrits.
 *
 * La lecture est STRICTE : une paire mal formée ou une entrée manquante lève,
 * pour qu'un fichier corrompu ne passe jamais au vert en ne testant rien.
 */
final class AnswerRuleFixtures
{
    /** Répertoire des fixtures, relatif à la racine du dépôt. */
    public const string DIRECTORY = 'tests/Fixtures/answers';

    /** Motif du nom d'un fichier de paires. */
    public const string NORMALIZER_FILE_PATTERN = '/^normalizer-v([1-9]\d*)\.php$/';

    /**
     * Chemin du fichier de paires d'une version — la courante par défaut.
     */
    public static function normalizerPath(?string $directory = null, int $version = AnswerRules::VERSION): string
    {
        return self::directory($directory).DIRECTORY_SEPARATOR.'normalizer-v'.$version.'.php';
    }

    /**
     * Les paires figées de la version COURANTE, et d'elle seule.
     *
     * @return array{normalize: list<array{0: string, 1: string}>, fold: list<array{0: string, 1: string}>}
     */
    public static function normalizer(?string $directory = null): array
    {
        return self::readNormalizer(self::normalizerPath($directory));
    }

    /**
     * Le CONTENU du fichier de paires d'une version donnée, lu pour en garder
     * l'intégrité — un fichier de version n'est jamais réécrit (§ 5.6, § 12)
     * —, jamais pour l'exécuter contre le code : seul
     * {@see self::normalizer()}, résolu par la version courante, alimente
     * l'exécution.
     *
     * @return array{normalize: list<array{0: string, 1: string}>, fold: list<array{0: string, 1: string}>}
     */
    public static function frozenNormalizer(int $version, ?string $directory = null): array
    {
        return self::readNormalizer(self::normalizerPath($directory, $version));
    }

    /**
     * @return array{normalize: list<array{0: string, 1: string}>, fold: list<array{0: string, 1: string}>}
     */
    private static function readNormalizer(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Fixtures absentes : [%s].', $path));
        }

        $fixtures = require $path;

        if (! is_array($fixtures)) {
            throw new RuntimeException(sprintf('Fixtures illisibles : [%s].', $path));
        }

        return [
            'normalize' => self::pairs($fixtures, 'normalize', $path),
            'fold' => self::pairs($fixtures, 'fold', $path),
        ];
    }

    /**
     * Le registre complet des empreintes, indexé par version.
     *
     * @return array<int, string>
     */
    public static function fingerprints(?string $directory = null): array
    {
        $path = self::directory($directory).DIRECTORY_SEPARATOR.'fingerprints.php';
        $registry = require $path;

        if (! is_array($registry)) {
            throw new RuntimeException(sprintf('Registre d\'empreintes illisible : [%s].', $path));
        }

        $fingerprints = [];

        foreach ($registry as $version => $fingerprint) {
            if (! is_int($version) || ! is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
                throw new RuntimeException(sprintf('Entrée d\'empreinte mal formée : [%s].', var_export($version, true)));
            }

            $fingerprints[$version] = $fingerprint;
        }

        return $fingerprints;
    }

    /**
     * L'empreinte attendue de la version COURANTE, et d'elle seule.
     */
    public static function expectedFingerprint(?string $directory = null): string
    {
        return self::fingerprints($directory)[AnswerRules::VERSION]
            ?? throw new RuntimeException(sprintf('Aucune empreinte pour la version %d.', AnswerRules::VERSION));
    }

    /**
     * Les versions dont un fichier de paires existe sur disque, triées.
     *
     * @return list<int>
     */
    public static function normalizerVersionsOnDisk(?string $directory = null): array
    {
        $versions = [];

        foreach (scandir(self::directory($directory)) ?: [] as $file) {
            if (preg_match(self::NORMALIZER_FILE_PATTERN, $file, $matches) === 1) {
                $versions[] = (int) $matches[1];
            }
        }

        sort($versions);

        return $versions;
    }

    private static function directory(?string $directory): string
    {
        return $directory ?? base_path(self::DIRECTORY);
    }

    /**
     * @param  array<array-key, mixed>  $fixtures
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(array $fixtures, string $function, string $path): array
    {
        $pairs = $fixtures[$function] ?? null;

        if (! is_array($pairs) || $pairs === [] || ! array_is_list($pairs)) {
            throw new RuntimeException(sprintf('Paires [%s] absentes ou mal formées : [%s].', $function, $path));
        }

        $checked = [];

        foreach ($pairs as $index => $pair) {
            if (! is_array($pair) || count($pair) !== 2 || ! is_string($pair[0] ?? null) || ! is_string($pair[1] ?? null)) {
                throw new RuntimeException(sprintf('Paire [%s] n° %d mal formée : [%s].', $function, $index, $path));
            }

            $checked[] = [$pair[0], $pair[1]];
        }

        return $checked;
    }
}
