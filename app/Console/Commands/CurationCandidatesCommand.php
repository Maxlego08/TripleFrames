<?php

namespace App\Console\Commands;

use App\Enums\FrameProcessingState;
use App\Models\Movie;
use App\Settings\PlatformLimits;
use App\Support\Curation\FrameBatch;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Rassemble, pour l'analyse par l'IA, les visuels candidats des films à
 * proposer — spec 20 § 5.10, D57 du 05/10. **Poste local seulement.**
 *
 * Pour chaque identifiant TMDB : les backdrops que l'ajout accepterait (sans
 * langue attachée, D39 du 28/09 ; dimensions admises), leurs dimensions, la
 * hauteur de leur master, le plus grand cadre admis et le cadre par défaut,
 * plus les images déjà présentes dans la banque locale ; une vignette de
 * chacun est téléchargée dans le répertoire de sortie. Le fichier
 * `candidates.json` qui en résulte est l'entrée de l'analyse ; sa sortie est
 * un lot `tripleframes.frame-batch` ({@see FrameBatch}) que
 * `curation:frame-batch` dépose dans les banques locales.
 *
 * N'écrit rien en base : le classement est une **proposition**, que le
 * porteur valide dans l'éditeur local avant tout export.
 */
class CurationCandidatesCommand extends Command
{
    /** Mot-clé TMDB des vignettes téléchargées pour l'analyse. */
    private const string THUMBNAIL_SIZE = 'w780';

    /**
     * @var string
     */
    protected $signature = 'curation:candidates
        {ids* : Identifiants TMDB des films}
        {--out= : Répertoire de sortie (défaut : storage/app/curation-candidates)}';

    /**
     * @var string
     */
    protected $description = 'Télécharge les backdrops candidats de films pour leur analyse (poste local)';

    public function handle(TmdbClient $tmdb): int
    {
        if (! App::environment('local')) {
            $this->components->error('Commande réservée au poste local (APP_ENV=local).');

            return self::FAILURE;
        }

        if (! $tmdb->isConfigured()) {
            $this->components->error('Aucune clé TMDB configurée.');

            return self::FAILURE;
        }

        $out = $this->option('out');
        $out = is_string($out) && $out !== '' ? $out : storage_path('app/curation-candidates');
        File::ensureDirectoryExists($out);

        $limits = PlatformLimits::current();
        $report = [];

        /** @var list<string> $ids */
        $ids = (array) $this->argument('ids');

        foreach ($ids as $rawId) {
            if (! ctype_digit($rawId) || (int) $rawId < 1) {
                $this->components->warn('Identifiant ignoré : '.$rawId);

                continue;
            }

            $tmdbId = (int) $rawId;

            try {
                $images = $tmdb->images($tmdbId);
            } catch (TmdbException $exception) {
                $this->components->warn(sprintf('TMDB %d : visuels illisibles (%s).', $tmdbId, $exception->kind->value));

                continue;
            }

            $movie = Movie::query()->where('tmdb_id', $tmdbId)->with('frames')->first();
            $directory = $out.DIRECTORY_SEPARATOR.$tmdbId;
            File::ensureDirectoryExists($directory);

            $candidates = [];
            $excluded = 0;

            foreach ($images->backdrops as $image) {
                if (! $image->isLanguageNeutral() || ! FrameGeometry::acceptsSource($image->width, $image->height, $limits)) {
                    $excluded++;

                    continue;
                }

                $index = count($candidates) + 1;
                $thumbnail = sprintf('%02d.jpg', $index);
                $masterHeight = FrameGeometry::masterHeightFor($image->width, $image->height);

                try {
                    $response = Http::timeout(30)->get($tmdb->imageUrl($image->filePath, self::THUMBNAIL_SIZE));
                    $saved = $response->successful() && File::put($directory.DIRECTORY_SEPARATOR.$thumbnail, $response->body()) !== false;
                } catch (Throwable) {
                    $saved = false;
                }

                $candidates[] = [
                    'index' => $index,
                    'thumbnail' => $saved ? $tmdbId.'/'.$thumbnail : null,
                    'tmdb_file_path' => $image->filePath,
                    'width' => $image->width,
                    'height' => $image->height,
                    'vote_average' => $image->voteAverage,
                    'master_height' => $masterHeight,
                    'max_crop_width' => FrameGeometry::maxCropWidth($masterHeight, $limits),
                    'min_crop_width' => $limits->frameCropMinWidthPx,
                    'default_crop' => FrameGeometry::defaultCrop($masterHeight, $limits)->toArray(),
                ];
            }

            $existing = [];

            foreach ($movie === null ? [] : $movie->frames as $frame) {
                if (FrameBatch::exportable($frame) || $frame->processing_state === FrameProcessingState::Pending) {
                    $existing[] = [
                        'tmdb_file_path' => $frame->tmdb_file_path,
                        'level' => $frame->frame_level->value,
                        'crop' => CropRect::fromFrame($frame)->toArray(),
                        'availability' => $frame->availability->value,
                    ];
                }
            }

            $report[] = [
                'tmdb_id' => $tmdbId,
                'title' => $movie?->title_original,
                'release_year' => $movie?->release_year,
                'in_local_catalog' => $movie !== null,
                'excluded_backdrops' => $excluded,
                'existing_frames' => $existing,
                'candidates' => $candidates,
            ];

            $this->components->twoColumnDetail(
                sprintf('%s (TMDB %d)', $movie === null ? 'hors catalogue local' : $movie->title_original, $tmdbId),
                sprintf('%d candidat(s), %d écarté(s), %d déjà en banque', count($candidates), $excluded, count($existing)),
            );
        }

        try {
            File::put(
                $out.DIRECTORY_SEPARATOR.'candidates.json',
                json_encode(['movies' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            );
        } catch (JsonException) {
            return self::FAILURE;
        }

        $this->components->info('Candidats écrits dans '.$out.DIRECTORY_SEPARATOR.'candidates.json');

        return self::SUCCESS;
    }
}
