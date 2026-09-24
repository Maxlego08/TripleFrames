<?php

namespace App\Actions\Curation;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Ajouter une variante à la banque d'un film — contrat C9, spec 20 § 5.3.
 *
 * Au jalon 1, **la voie TMDB seule** : `fromCapture()` arrive avec la voie
 * capture (lot L20-33), après l'arbitrage de sa licéité.
 *
 * Ce que l'action reçoit : des primitives et des OCTETS, jamais un DTO de
 * `App\Support\Tmdb` (`TmdbBoundaryTest`). Le contrôleur a déjà vérifié que le
 * visuel est un backdrop du film, que ses dimensions permettent une image de
 * jeu et que le cadre respecte le plancher sur la hauteur de master tirée des
 * métadonnées ; le job le revérifiera sur le master réel.
 *
 * **Dédoublonnage dans le film**, sous `lockForUpdate` sur la ligne `movie`
 * (spec 10 § 4.1) : une frame du même film, ni `withdrawn` ni écartée, qui
 * porte le même `source_hash` ET le même rectangle refuse l'ajout. Un double
 * envoi ne crée donc jamais deux variantes identiques, qui compteraient
 * double et masqueraient le badge « variante unique » (§ 6.6) ; une même
 * source recadrée autrement reste une variante légitime. Écartée =
 * `unpublished` jamais publiée (`first_published_at` NULL, EN20-1) : pour
 * réutiliser le visuel d'une image écartée, on l'ajoute de nouveau.
 *
 * **Un seul fichier écrit dans la requête** : les octets originaux, déposés
 * PROVISOIREMENT sous `master_path`, que le premier traitement réussi
 * remplace par le master normalisé. Écrits après le dédoublonnage et avant
 * l'insertion, dans la transaction ; tout échec avant le commit les
 * supprime, pour qu'aucun original ne survive sans sa ligne. Aucun traitement
 * Imagick n'a lieu ici : le job part sur la file `default`, **après le
 * commit**.
 *
 * Aucune ligne `admin_action` : ajouter une image n'engage rien, et la trace
 * est `frame.uploaded_by_id` (§ 2.2, ligne 13).
 */
final class AddFrame
{
    /**
     * Crée une frame `draft` et `pending` depuis un visuel TMDB, et distribue
     * son traitement.
     *
     * @throws ValidationException le même visuel et le même cadre existent déjà dans la banque du film
     * @throws Throwable
     */
    public function fromTmdb(
        Movie $movie,
        User $curator,
        FrameLevel $level,
        CropRect $crop,
        string $tmdbFilePath,
        string $originalBytes,
        ?int $cropSeconds,
    ): Frame {
        $sourceHash = hash('sha256', $originalBytes);
        $masterPath = FrameStoragePrefix::Master->newPath();
        $written = false;
        $committed = false;

        try {
            return DB::transaction(function () use ($movie, $curator, $level, $crop, $tmdbFilePath, $originalBytes, $cropSeconds, $sourceHash, $masterPath, &$written, &$committed): Frame {
                $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

                // La garde de la route, rejouée sous le verrou : la banque d'un
                // film suspendu ou retiré entre-temps ne grossit pas.
                Gate::forUser($curator)->authorize('create', [Frame::class, $locked]);

                if ($this->hasTwin($locked, $sourceHash, $crop)) {
                    throw ValidationException::withMessages([
                        'crop' => __('admin.frame.tmdb.duplicate'),
                    ]);
                }

                if (! Storage::disk(FrameStoragePrefix::DISK)->put($masterPath, $originalBytes)) {
                    throw new RuntimeException('Octets provisoires non écrits sur le disque frames.');
                }

                $written = true;

                $frame = new Frame;
                $frame->forceFill([
                    'movie_id' => $locked->id,
                    'frame_level' => $level,
                    'availability' => ContentAvailability::Draft,
                    'processing_state' => FrameProcessingState::Pending,
                    'processing_error' => null,
                    'source_kind' => FrameSourceKind::Tmdb,
                    'tmdb_file_path' => $tmdbFilePath,
                    'source_hash' => $sourceHash,
                    'crop_x' => $crop->x,
                    'crop_y' => $crop->y,
                    'crop_width' => $crop->width,
                    'crop_height' => $crop->height,
                    'master_path' => $masterPath,
                    'uploaded_by_id' => $curator->id,
                    'crop_seconds' => self::cappedCropSeconds($cropSeconds),
                ])->save();

                // Enregistré AVANT la distribution, donc exécuté avant elle au
                // commit : une panne de la file après le commit ne fait jamais
                // supprimer les octets d'une ligne déjà écrite.
                DB::afterCommit(static function () use (&$committed): void {
                    $committed = true;
                });

                // `afterCommit` est posé par le job lui-même : il ne lit jamais
                // une ligne qu'une transaction annulerait.
                ProcessFrameImage::dispatch($frame->id);

                return $frame;
            });
        } catch (Throwable $exception) {
            if ($written && ! $committed) {
                self::deleteProvisional($masterPath);
            }

            throw $exception;
        }
    }

    /**
     * Vrai si la banque du film porte déjà ce visuel sous ce cadre : même
     * `source_hash`, même rectangle, hors images retirées et écartées.
     *
     * Aucun index sur `source_hash` (spec 10 § 4.1) : la lecture passe par le
     * préfixe `movie_id` de `frame_movie_level_idx`, sur la banque d'un seul
     * film.
     */
    private function hasTwin(Movie $movie, string $sourceHash, CropRect $crop): bool
    {
        return Frame::query()
            ->where('movie_id', $movie->id)
            ->where('source_hash', $sourceHash)
            ->where('crop_x', $crop->x)
            ->where('crop_y', $crop->y)
            ->where('crop_width', $crop->width)
            ->where('crop_height', $crop->height)
            ->where('availability', '!=', ContentAvailability::Withdrawn->value)
            ->whereNot(fn (Builder $query) => $query
                ->where('availability', ContentAvailability::Unpublished->value)
                ->whereNull('first_published_at'))
            ->exists();
    }

    /**
     * Le temps de recadrage, plafonné à `catalog.curation.crop_seconds_max` :
     * un cadre oublié ouvert ne fausse pas la médiane du débit (§ 10.1).
     */
    private static function cappedCropSeconds(?int $cropSeconds): ?int
    {
        if ($cropSeconds === null) {
            return null;
        }

        return min(max(0, $cropSeconds), Config::integer('catalog.curation.crop_seconds_max'));
    }

    /**
     * Les octets provisoires d'un ajout qui n'a pas abouti. Un échec de
     * suppression est journalisé sans chemin : il ne masque jamais l'erreur
     * d'origine.
     */
    private static function deleteProvisional(string $path): void
    {
        try {
            Storage::disk(FrameStoragePrefix::DISK)->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Ajout d’image : octets provisoires non supprimés.', [
                'exception' => $exception::class,
            ]);
        }
    }
}
