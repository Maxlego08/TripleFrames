<?php

namespace App\Support\Identity;

use App\Enums\Locale;
use App\Rules\ValidNickname;
use App\Support\Catalog\AnswerKeyNormalizer;
use RuntimeException;

/**
 * La liste noire des pseudos — contrat C5, première moitié (spec 40 § 5.7),
 * étape 5 de {@see ValidNickname}.
 *
 * **Ressource versionnée, aucune table** (10 § A15) : un fichier par cas de
 * {@see Locale} plus {@see self::RESERVED_FILE}, sous
 * `resource_path(self::DIRECTORY)`. Elle applique l'**union** de tous ces
 * fichiers, **quelle que soit la langue du joueur** : un pseudo s'affiche à
 * tout le salon, dans toutes ses langues, comme une bonne réponse est acceptée
 * dans toutes les langues activées (I5.6).
 *
 * **Fichiers** : UTF-8, LF, une entrée par ligne, `#` en début de ligne pour
 * les commentaires, en-tête `# source:`, `# license:`, `# retrieved:` (et
 * `# changes:` dès qu'une entrée importée est retirée ou ajoutée), vérifié par
 * `NicknameBlocklistTest`. Un fichier manquant pour une locale activée lève
 * une exception **bruyante** ({@see self::files()}) : ajouter une langue sans
 * sa liste noire doit casser, pas passer (05 § Ajouter une troisième langue,
 * étape 4).
 *
 * **Compilation.** Chaque entrée passe par {@see NicknameNormalizer::normalize()}
 * — la forme repliée qui porte l'unicité d'un pseudo — et est mémorisée sous
 * deux formes : brute `k`, et réduite `ρ(k)`, où `ρ` ramène toute lettre
 * répétée à une seule. La liste compilée vit le temps du processus ; les
 * fichiers sont lus à la première validation. Une entrée dont la forme
 * repliée est vide (un émoji, que la règle refuse de toute façon à l'étape 2)
 * ne compile en rien : une entrée vide serait sous-chaîne de tout.
 *
 * **Algorithme** ({@see self::blocks()}). Soit `c` la forme compacte
 * (`normalize()`) et `f` la forme repliée, séparateurs conservés
 * (`AnswerKeyNormalizer::fold()`). Pour chaque transformation `L` ∈
 * {identité, {@see self::LEET_PRIMARY}, {@see self::LEET_SECONDARY}} :
 *
 * - (a) **forme brute, toujours** : `L(c)` et les jetons alphabétiques de
 *   `L(f)`, comparés aux entrées brutes ;
 * - (b) **forme réduite, seulement si `ρ(L(c)) ≠ L(c)`** — la saisie montre
 *   elle-même une lettre répétée : `ρ(L(c))` et les jetons de `ρ(L(f))`,
 *   comparés aux entrées réduites.
 *
 * Une entrée `e` bloque si elle fait au moins {@see self::SUBSTRING_MIN_LENGTH}
 * caractères et figure dans la forme compacte, **ou** si elle est l'un des
 * jetons, **ou** si elle égale la forme compacte entière. Une entrée longue
 * est donc reconnue n'importe où — leet, lettres répétées ou séparées compris
 * —, une entrée courte seulement comme **mot entier** (« Conan » et « Leçon »
 * échappent à l'effet Scunthorpe). Sans la condition de (b), « Bob », « As »
 * et « Château » tomberaient sur la forme réduite d'une entrée.
 *
 * **Résidus assumés** (§ 5.7) : une entrée courte collée à un autre mot passe ;
 * une entrée longue produit des faux positifs (« Badminton » contient
 * `admin`) ; une saisie à lettre répétée sans rapport avec l'entrée peut
 * tomber sur une forme réduite. Le message reste neutre, le joueur choisit un
 * autre pseudo. Le seuil et les tables leet sont des **constantes
 * d'algorithme**, jamais de la configuration (§ 5.10).
 */
final class NicknameBlocklist
{
    /** Répertoire des listes, relatif à `resource_path()`. */
    public const string DIRECTORY = 'moderation/nicknames';

    /** Nom (sans extension) de la liste des noms réservés, rédigée pour le projet. */
    public const string RESERVED_FILE = 'reserved';

    /**
     * Longueur à partir de laquelle une entrée est cherchée **n'importe où**
     * dans la forme compacte ; en deçà, seulement comme mot entier.
     */
    public const int SUBSTRING_MIN_LENGTH = 5;

    /** Substitution leet des chiffres, `1` lu comme `i`. */
    public const array LEET_PRIMARY = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't'];

    /** Substitution leet des chiffres, `1` lu comme `l`. */
    public const array LEET_SECONDARY = ['0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't'];

    /** Extension de chaque liste. */
    private const string EXTENSION = '.txt';

    /** Une ligne qui commence par ce caractère est un commentaire. */
    private const string COMMENT_PREFIX = '#';

    /** `ρ` : toute lettre répétée ramenée à une seule. */
    private const string REPEATED_LETTER_PATTERN = '/([a-z])\1+/';

    /** Séparateurs de jetons : tout ce qui n'est pas une lettre ASCII. */
    private const string TOKEN_SEPARATOR_PATTERN = '/[^a-z]+/';

    /**
     * Listes compilées, par répertoire lu : pour chaque forme, l'ensemble des
     * entrées (égalité et jetons) et celles assez longues pour être cherchées
     * comme sous-chaînes.
     *
     * @var array<string, array{raw: array{all: array<string, true>, long: list<string>}, reduced: array{all: array<string, true>, long: list<string>}}>
     */
    private static array $compiled = [];

    /**
     * Vrai si le pseudo — sa forme **canonique** ({@see NicknameNormalizer::canonical()})
     * — tombe sur une entrée de l'une des listes.
     */
    public static function blocks(string $canonical): bool
    {
        $lists = self::compiled();
        $compact = NicknameNormalizer::normalize($canonical);
        $folded = AnswerKeyNormalizer::fold($canonical);

        foreach ([[], self::LEET_PRIMARY, self::LEET_SECONDARY] as $leet) {
            $substitutedCompact = strtr($compact, $leet);
            $substitutedFolded = strtr($folded, $leet);

            // (a) Forme brute, toujours.
            if (self::matches($substitutedCompact, $substitutedFolded, $lists['raw'])) {
                return true;
            }

            // (b) Forme réduite, seulement si la saisie montre elle-même une
            // lettre répétée.
            $reducedCompact = self::reduce($substitutedCompact);

            if ($reducedCompact !== $substitutedCompact
                && self::matches($reducedCompact, self::reduce($substitutedFolded), $lists['reduced'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les chemins absolus des listes : un par cas de {@see Locale}, dans
     * l'ordre de déclaration, puis {@see self::RESERVED_FILE}.
     *
     * @return list<string>
     *
     * @throws RuntimeException si l'une manque : une locale activée sans sa
     *                          liste noire laisserait passer tout pseudo de
     *                          sa langue.
     */
    public static function files(): array
    {
        $names = array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());
        $names[] = self::RESERVED_FILE;

        $paths = [];

        foreach ($names as $name) {
            $path = resource_path(self::DIRECTORY.'/'.$name.self::EXTENSION);

            if (! is_file($path)) {
                throw new RuntimeException(
                    "Liste noire de pseudos introuvable : {$path}. Chaque locale activée et les noms réservés "
                    .'exigent leur fichier (spec 40 § 5.7).',
                );
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return array{raw: array{all: array<string, true>, long: list<string>}, reduced: array{all: array<string, true>, long: list<string>}}
     */
    private static function compiled(): array
    {
        return self::$compiled[resource_path(self::DIRECTORY)] ??= self::compile(self::files());
    }

    /**
     * @param  list<string>  $files
     * @return array{raw: array{all: array<string, true>, long: list<string>}, reduced: array{all: array<string, true>, long: list<string>}}
     */
    private static function compile(array $files): array
    {
        $raw = [];
        $reduced = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException("Liste noire de pseudos illisible : {$file}.");
            }

            foreach (explode("\n", $contents) as $line) {
                if ($line === '' || str_starts_with($line, self::COMMENT_PREFIX)) {
                    continue;
                }

                $entry = NicknameNormalizer::normalize(NicknameNormalizer::canonical($line));

                if ($entry === '') {
                    continue;
                }

                $raw[$entry] = true;
                $reduced[self::reduce($entry)] = true;
            }
        }

        return ['raw' => self::index($raw), 'reduced' => self::index($reduced)];
    }

    /**
     * @param  array<string, true>  $entries
     * @return array{all: array<string, true>, long: list<string>}
     */
    private static function index(array $entries): array
    {
        $all = [];
        $long = [];

        foreach (array_keys($entries) as $entry) {
            // Une clé numérique (« 69 ») revient en entier : elle reste une chaîne.
            $entry = (string) $entry;
            $all[$entry] = true;

            if (strlen($entry) >= self::SUBSTRING_MIN_LENGTH) {
                $long[] = $entry;
            }
        }

        return ['all' => $all, 'long' => $long];
    }

    /**
     * Vrai si une entrée bloque la forme compacte `$compact`, dont `$folded`
     * donne les jetons : sous-chaîne pour une entrée longue, jeton ou forme
     * entière pour toute entrée.
     *
     * @param  array{all: array<string, true>, long: list<string>}  $list
     */
    private static function matches(string $compact, string $folded, array $list): bool
    {
        if (isset($list['all'][$compact])) {
            return true;
        }

        foreach (preg_split(self::TOKEN_SEPARATOR_PATTERN, $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (isset($list['all'][$token])) {
                return true;
            }
        }

        foreach ($list['long'] as $entry) {
            if (str_contains($compact, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** `ρ` : toute lettre répétée ramenée à une seule (« boob » → « bob »). */
    private static function reduce(string $text): string
    {
        return preg_replace(self::REPEATED_LETTER_PATTERN, '$1', $text) ?? $text;
    }
}
