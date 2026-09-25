<?php

namespace App\Support\Admin;

use App\Concerns\CatalogImportValidationRules;
use App\Enums\ImportRunKind;
use App\Models\Movie;
use App\Support\Catalog\TmdbIdentifierList;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * La liste d'amorçage — spec 20 § 3.5.
 *
 * Un fichier VERSIONNÉ (`catalog.import.seed_list_path`), au format du
 * collage, lu par {@see TmdbIdentifierList} — seul lecteur autorisé d'un
 * collage. Ni table ni colonne : la reprise est une propriété du CATALOGUE,
 * pas d'un curseur. Les identifiants déjà au catalogue, films retirés compris,
 * sont retirés à chaque lecture (dédoublonnage par `tmdb_id`, `movie_tmdb_uq`,
 * spec 10 § 9.2), si bien que le clic suivant reprend toujours au premier
 * identifiant manquant : l'opération est idempotente.
 *
 * **Un lot par collage, jamais la liste entière** : `paste_max_ids` borne ce
 * qu'un envoi web confie au worker, sous le `--timeout` de la file `default` ;
 * un collage de deux cents identifiants pourrait être tué en cours de route et
 * laisser un balayage `running` sans cause visible. Le verrou de
 * {@see ImportLauncher} n'est ni contourné ni dupliqué : ce qui est lu ici ne
 * sert qu'à COMPOSER un lot et à dire à l'écran si le bouton est utile.
 */
final class SeedList
{
    // Pour `pasteMaxIds()` seul : le plafond d'un lot EST celui d'un collage
    // web, lu au même endroit, jamais recopié.
    use CatalogImportValidationRules;

    /** Le fichier ne porte aucun identifiant (état livré). */
    public const string EMPTY = 'empty';

    /** Un collage est ouvert : le bouton attend sa fin. */
    public const string BUSY = 'busy';

    /** Chaque identifiant de la liste est au catalogue. */
    public const string DONE = 'done';

    /** Un lot est prêt à partir. */
    public const string READY = 'ready';

    /**
     * Le chemin du fichier : relatif à la racine du projet, ou absolu tel
     * quel.
     */
    public static function path(): string
    {
        $configured = trim(Config::string('catalog.import.seed_list_path', ''));

        if ($configured === '') {
            return '';
        }

        return self::isAbsolute($configured) ? $configured : base_path($configured);
    }

    /**
     * Les identifiants du fichier, dans son ordre, dédoublonnés. Un fichier
     * absent ou illisible vaut une liste vide : le bouton est alors inactif,
     * jamais une page d'erreur.
     *
     * @return list<int>
     */
    public static function identifiers(): array
    {
        $path = self::path();

        if ($path === '' || ! File::isFile($path) || ! File::isReadable($path)) {
            return [];
        }

        return TmdbIdentifierList::parse(preg_split('/\R/u', File::get($path)) ?: []);
    }

    /**
     * Les identifiants de la liste encore absents du catalogue, dans l'ordre
     * du fichier. Un film retiré est PRÉSENT : son réimport est bloqué par sa
     * propre ligne (spec 10 A10).
     *
     * @param  list<int>|null  $identifiers
     * @return list<int>
     */
    public static function remaining(?array $identifiers = null): array
    {
        $identifiers ??= self::identifiers();

        if ($identifiers === []) {
            return [];
        }

        $chunk = max(1, Config::integer('catalog.import.deduplication_chunk', 200));

        /** @var array<int, true> $known */
        $known = [];

        foreach (array_chunk($identifiers, $chunk) as $slice) {
            foreach (Movie::query()->whereIn('tmdb_id', $slice)->pluck('tmdb_id') as $tmdbId) {
                if (is_int($tmdbId) || (is_string($tmdbId) && ctype_digit($tmdbId))) {
                    $known[(int) $tmdbId] = true;
                }
            }
        }

        return array_values(array_filter(
            $identifiers,
            static fn (int $identifier): bool => ! isset($known[$identifier]),
        ));
    }

    /**
     * Le prochain lot : au plus `paste_max_ids` identifiants manquants, dans
     * l'ordre du fichier.
     *
     * @param  list<int>|null  $remaining
     * @return list<int>
     */
    public static function nextBatch(?array $remaining = null): array
    {
        $remaining ??= self::remaining();

        return array_slice($remaining, 0, self::pasteMaxIds());
    }

    /**
     * L'état du bouton pour l'écran d'import : l'état, le total, le reste, le
     * prochain lot — que « Prévisualiser le lot suivant » envoie tel quel — et
     * le collage qui occupe le verrou, s'il y en a un.
     *
     * @return array{state: string, total: int, remaining: int, next_batch: list<int>, busy_run_id: int|null}
     */
    public static function summary(): array
    {
        $identifiers = self::identifiers();
        $remaining = self::remaining($identifiers);
        $busy = ImportLauncher::openRun(ImportRunKind::Paste);

        $state = match (true) {
            $identifiers === [] => self::EMPTY,
            $remaining === [] => self::DONE,
            $busy !== null => self::BUSY,
            default => self::READY,
        };

        return [
            'state' => $state,
            'total' => count($identifiers),
            'remaining' => count($remaining),
            'next_batch' => self::nextBatch($remaining),
            'busy_run_id' => $busy?->id,
        ];
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
