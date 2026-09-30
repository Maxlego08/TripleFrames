<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Support\Admin\AdminJournal;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Ajouter une variante à la banque d'un film — contrat C9, spec 20 § 5.3 et
 * § 5.4. Deux voies, un seul corps transactionnel : {@see self::fromTmdb()},
 * le visuel TMDB téléchargé par le serveur, et {@see self::fromCapture()}, la
 * capture personnelle normalisée par le navigateur (L20-33, D38 du 28/09).
 *
 * Ce que l'action reçoit : des primitives, des OCTETS ou le fichier reçu,
 * jamais un DTO de `App\Support\Tmdb` (`TmdbBoundaryTest`). Le contrôleur a
 * déjà vérifié que la source — backdrop du film, ou capture reçue — a des
 * dimensions qui permettent une image de jeu et que le cadre respecte le
 * plancher sur la hauteur de master qu'elle donne ; le job le revérifiera sur
 * le master réel. **Le jeu est toujours dérivé par le serveur**, du master et
 * du rectangle, jamais par le navigateur (R-46).
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
 * **Un seul fichier écrit dans la requête** : les octets originaux — ceux que
 * le serveur a téléchargés, ou ceux qu'il a reçus —, déposés PROVISOIREMENT
 * sous `master_path`, que le premier traitement réussi remplace par le master
 * normalisé. Une capture l'est TOUJOURS : ses octets provisoires ont pour
 * SHA-256 `source_hash`, si bien que le job ne les prend jamais pour un master
 * déjà produit, et leur applique le refus de l'animation, `stripImage()` et
 * le réencodage. Écrits après le dédoublonnage et avant
 * l'insertion, dans la transaction ; tout échec avant le commit les
 * supprime, pour qu'aucun original ne survive sans sa ligne. Aucun traitement
 * Imagick n'a lieu ici : le job part sur la file `default`, **après le
 * commit**.
 *
 * **Une ligne `frame.added`** (D41 du 30/09), dans la transaction, après
 * l'insertion et avant la distribution du job : `details` garde la voie et le
 * niveau au moment de l'ajout — le niveau change ensuite en place. La source
 * déclarée, elle, reste sur la frame ; `frame.uploaded_by_id` aussi.
 */
final class AddFrame
{
    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

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
        return $this->add(
            movie: $movie,
            curator: $curator,
            level: $level,
            crop: $crop,
            bytes: $originalBytes,
            cropSeconds: $cropSeconds,
            ability: 'create',
            duplicateKey: 'admin.frame.tmdb.duplicate',
            source: [
                'source_kind' => FrameSourceKind::Tmdb,
                'tmdb_file_path' => $tmdbFilePath,
            ],
        );
    }

    /**
     * Crée une frame `draft` et `pending` depuis une capture personnelle, et
     * distribue son traitement.
     *
     * `source_hash` est le SHA-256 des octets REÇUS, jamais une déclaration du
     * navigateur ; `source_timecode_ms`, le minutage saisi, en secondes
     * entières × 1 000 — toujours posé : une capture sans minutage n'aurait
     * aucune source à déclarer en revue. Aucun `tmdb_file_path`.
     *
     * @throws ValidationException la même capture et le même cadre existent déjà dans la banque du film
     * @throws Throwable
     */
    public function fromCapture(
        Movie $movie,
        User $curator,
        FrameLevel $level,
        CropRect $crop,
        UploadedFile $source,
        int $timecodeMs,
        ?int $cropSeconds,
    ): Frame {
        $bytes = $source->get();

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Octets de la capture reçue illisibles.');
        }

        return $this->add(
            movie: $movie,
            curator: $curator,
            level: $level,
            crop: $crop,
            bytes: $bytes,
            cropSeconds: $cropSeconds,
            ability: 'createFromCapture',
            duplicateKey: 'admin.frame.capture.duplicate',
            source: [
                'source_kind' => FrameSourceKind::Capture,
                'tmdb_file_path' => null,
                'source_timecode_ms' => $timecodeMs,
            ],
        );
    }

    /**
     * Le corps commun aux deux voies : verrou du film, garde rejouée,
     * dédoublonnage, octets provisoires, ligne `draft` / `pending`, job après
     * le commit, et nettoyage des octets si rien n'a été validé.
     *
     * @param  string  $ability  la capacité de {@see FramePolicy} que la route a franchie, rejouée sous le verrou
     * @param  string  $duplicateKey  le refus d'un doublon, propre à la voie
     * @param  array<string, mixed>  $source  les colonnes de la source déclarée, propres à la voie
     *
     * @throws ValidationException
     * @throws Throwable
     */
    private function add(
        Movie $movie,
        User $curator,
        FrameLevel $level,
        CropRect $crop,
        string $bytes,
        ?int $cropSeconds,
        string $ability,
        string $duplicateKey,
        array $source,
    ): Frame {
        $sourceHash = hash('sha256', $bytes);
        $masterPath = FrameStoragePrefix::Master->newPath();
        $written = false;
        $committed = false;

        try {
            return DB::transaction(function () use ($movie, $curator, $level, $crop, $bytes, $cropSeconds, $ability, $duplicateKey, $source, $sourceHash, $masterPath, &$written, &$committed): Frame {
                $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

                // La garde de la route, rejouée sous le verrou : la banque d'un
                // film suspendu ou retiré entre-temps ne grossit pas, et une
                // voie capture fermée entre-temps ne reçoit rien.
                Gate::forUser($curator)->authorize($ability, [Frame::class, $locked]);

                if ($this->hasTwin($locked, $sourceHash, $crop)) {
                    throw ValidationException::withMessages([
                        'crop' => __($duplicateKey),
                    ]);
                }

                if (! Storage::disk(FrameStoragePrefix::DISK)->put($masterPath, $bytes)) {
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
                    ...$source,
                    'source_hash' => $sourceHash,
                    'crop_x' => $crop->x,
                    'crop_y' => $crop->y,
                    'crop_width' => $crop->width,
                    'crop_height' => $crop->height,
                    'master_path' => $masterPath,
                    'uploaded_by_id' => $curator->id,
                    'crop_seconds' => self::cappedCropSeconds($cropSeconds),
                ])->save();

                $this->journal->record(
                    $curator,
                    AdminActionType::FrameAdded,
                    $frame->id,
                    details: AdminActionDetails::frameAdded($frame->source_kind, $level),
                );

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
     * Vrai si la banque du film porte déjà cette source sous ce cadre : même
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
