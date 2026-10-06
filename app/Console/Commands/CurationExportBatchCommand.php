<?php

namespace App\Console\Commands;

use App\Http\Requests\Admin\FrameBatchStoreRequest;
use App\Models\Movie;
use App\Support\Curation\FrameBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Exporte en lot les images d'un ensemble de films — spec 20 § 5.10, D57 du
 * 05/10. Lecture seule.
 *
 * Le lot ({@see FrameBatch}) ne porte que des références TMDB, des niveaux et
 * des cadres : il se dépose dans l'écran « Lots d'images » du back-office de
 * production, qui retélécharge les originaux et rejoue toutes les gardes. Une
 * capture personnelle n'est jamais exportée, ses octets ne se
 * retéléchargeant pas ; elle est comptée à part.
 *
 * Au-delà des plafonds de l'import (200 films, 1 024 Ko), l'export se
 * découpe en plusieurs fichiers numérotés, chacun importable tel quel
 * (amendé le 06/10).
 */
class CurationExportBatchCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'curation:export-batch
        {ids?* : Identifiants TMDB des films à exporter}
        {--all : Tous les films qui ont au moins une image exportable}
        {--out= : Fichier de sortie (défaut : storage/app/frame-batches/frame-batch-<date>.json ; numéroté -1, -2… si le lot est découpé)}';

    /**
     * @var string
     */
    protected $description = 'Exporte en lot JSON les images TMDB de films, pour les importer dans une autre base';

    public function handle(): int
    {
        /** @var list<string> $ids */
        $ids = (array) $this->argument('ids');
        $all = (bool) $this->option('all');

        if ($ids === [] && ! $all) {
            $this->components->error('Indiquez des identifiants TMDB, ou --all.');

            return self::FAILURE;
        }

        $query = Movie::query()->whereNotNull('tmdb_id')->with('frames')->orderBy('id');

        if (! $all) {
            $query->whereIn('tmdb_id', array_map(intval(...), array_filter($ids, ctype_digit(...))));
        }

        $movies = $query->get();
        $batch = FrameBatch::fromMovies($movies);

        $captures = 0;

        foreach ($movies as $movie) {
            foreach ($movie->frames as $frame) {
                if ($frame->tmdb_file_path === null) {
                    $captures++;
                }
            }
        }

        if ($batch->movies === []) {
            $this->components->warn('Aucune image exportable.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $out = $this->option('out');
        $out = is_string($out) && $out !== ''
            ? $out
            : storage_path('app/frame-batches/frame-batch-'.$now->format('Ymd-His').'.json');

        // Un lot par fichier, chacun sous les plafonds de l'écran d'import :
        // au-delà, `-1`, `-2`… avant l'extension, à déposer l'un après l'autre.
        try {
            $chunks = $batch->encodedChunks(FrameBatchStoreRequest::MAX_KILOBYTES * 1024, $now);
        } catch (JsonException) {
            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($out));

        $files = [];

        foreach ($chunks as $index => $json) {
            $path = count($chunks) === 1 ? $out : self::numbered($out, $index + 1);
            File::put($path, $json);
            $files[] = $path;
        }

        $this->components->info(sprintf(
            '%d film(s), %d image(s) exportée(s) en %d lot(s)%s',
            count($batch->movies),
            $batch->framesCount(),
            count($files),
            $captures > 0 ? sprintf(' — %d capture(s) non exportable(s)', $captures) : '',
        ));

        foreach ($files as $path) {
            $this->line('  '.$path);
        }

        return self::SUCCESS;
    }

    /** `lot.json` → `lot-2.json` ; sans extension, le numéro est suffixé. */
    private static function numbered(string $path, int $number): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === ''
            ? $path.'-'.$number
            : substr($path, 0, -strlen($extension) - 1).'-'.$number.'.'.$extension;
    }
}
