<?php

namespace App\Console\Commands;

use App\Enums\ContentAvailability;
use App\Enums\FrameSourceKind;
use App\Enums\Locale;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Le manifeste du disque `frames` — spec 100 § 13.2 et § 13.3, lot L100-10.
 *
 * **Sans option (tier chaud)** : une ligne par fichier PRÉSENT sur le disque,
 * `<sha256> <taille> <chemin relatif>`, triée par chemin. C'est la preuve de
 * ce qui existait à la date du vidage, et c'est aussi la table de
 * correspondance condensat → chemin qui permet de redéposer un objet du tier
 * froid à sa place : la base ne porte le condensat que du dérivé publié
 * (`published_hash`), jamais celui d'un master.
 *
 * **Sous `--cold` (tier froid)** : `<sha256> <chemin relatif>`, au format de
 * `sha256sum`, pour le périmètre de 10 § 10 — une REQUÊTE, pas une colonne :
 *
 * - le `game_path` de toute frame `published` ;
 * - le `master_path` ET le `game_path` de toute frame `source_kind = capture`,
 *   publiée ou non (D38 du 28/09, amendement du § 13.3) : une capture est
 *   irremplaçable, aucune référence ne la retélécharge. Une capture
 *   `withdrawn` y reste tant que ses fichiers existent : les octets retirés
 *   qui reviennent d'une restauration sont resupprimés par
 *   `takedown:reconcile` (10 § 10, arbitrage A9). Seule une capture retirée
 *   dont `files_deleted_at` atteste la disparition des fichiers en sort : il
 *   n'y a plus rien à sauvegarder, et la compter manquante ferait échouer
 *   chaque passage.
 *
 * Un master d'origine TMDB n'en fait jamais partie : il se reconstruit depuis
 * `tmdb_file_path` et le rectangle de recadrage.
 *
 * Le condensat est TOUJOURS calculé sur les octets lus, jamais recopié de la
 * base. Sous `--cold`, le dérivé d'une frame `published` dont le condensat
 * diffère de `published_hash` (ou sans `published_hash`) est **altéré** : il
 * n'est pas listé — ses octets ne sont pas ceux qu'a vus la revue, et l'objet
 * froid `cold/game/<published_hash>.webp.age` n'existerait jamais.
 *
 * **Codes de sortie** : 0 ; 1 quand un fichier listé par le disque est
 * illisible (tier chaud), ou quand un fichier du périmètre froid est
 * introuvable, illisible ou altéré. Les autres lignes sont écrites quand même,
 * pour que `backup-cold.sh` envoie tout ce qui peut l'être avant d'échouer ;
 * les nombres partent sur la sortie d'erreur, jamais dans le manifeste, et le
 * code 1 arrête `backup-hot.sh` (`pipefail`) avant le battement.
 *
 * Seules les colonnes typées de `frame` sont lues ; aucune requête n'entre
 * dans une colonne JSON.
 */
class BackupManifestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:manifest
        {--cold : restreindre au périmètre du tier froid (dérivés publiés et fichiers des captures)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manifeste du disque frames (chemin, taille, SHA-256), ou périmètre du tier froid sous --cold';

    /** Frames lues par lot sous `--cold`. */
    private const int CHUNK_SIZE = 500;

    public function handle(): int
    {
        $disk = Storage::disk(FrameStoragePrefix::DISK);

        return $this->option('cold') ? $this->cold($disk) : $this->hot($disk);
    }

    /**
     * Le SHA-256 d'un fichier du disque, lu en flux ; `null` s'il est absent
     * ou illisible. Partagé avec `backup:verify`.
     */
    public static function sha256(Filesystem $disk, string $path): ?string
    {
        return self::digest($disk, $path)['hash'] ?? null;
    }

    /**
     * Le SHA-256 et la taille d'un fichier, tirés du MÊME flux : un fichier
     * remplacé ou supprimé entre deux appels (recadrage, file par défaut) ne
     * peut ni faire lever une lecture de métadonnées séparée, ni associer la
     * taille d'un fichier au condensat d'un autre. `null` s'il est absent ou
     * illisible.
     *
     * @return array{hash: string, size: int}|null
     */
    private static function digest(Filesystem $disk, string $path): ?array
    {
        try {
            $stream = $disk->readStream($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            $size = hash_update_stream($context, $stream);

            return ['hash' => hash_final($context), 'size' => $size];
        } catch (Throwable) {
            return null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Tout le disque, fichier par fichier.
     */
    private function hot(Filesystem $disk): int
    {
        $files = $disk->allFiles();
        sort($files, SORT_STRING);

        $unreadable = 0;

        foreach ($files as $path) {
            $digest = self::digest($disk, $path);

            if ($digest === null) {
                $unreadable++;

                continue;
            }

            $this->line($digest['hash'].' '.$digest['size'].' '.$path);
        }

        if ($unreadable === 0) {
            return self::SUCCESS;
        }

        $this->writeError('manifest_unreadable', ['count' => $unreadable]);

        return self::FAILURE;
    }

    /**
     * Le périmètre du tier froid, dédoublonné et trié par chemin.
     */
    private function cold(Filesystem $disk): int
    {
        /**
         * Chemin → condensat de revue attendu : `published_hash` du dérivé
         * d'une frame publiée (chaîne vide s'il manque), `null` quand aucun
         * condensat de revue ne s'applique (master, capture non publiée).
         *
         * @var array<string, string|null> $paths
         */
        $paths = [];

        DB::table('frame')
            ->select(['id', 'availability', 'source_kind', 'game_path', 'master_path', 'published_hash'])
            ->where(static function ($query): void {
                $query->where('availability', ContentAvailability::Published->value)
                    ->orWhere(static function ($capture): void {
                        $capture->where('source_kind', FrameSourceKind::Capture->value)
                            ->where(static function ($present): void {
                                $present->where('availability', '!=', ContentAvailability::Withdrawn->value)
                                    ->orWhereNull('files_deleted_at');
                            });
                    });
            })
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, static function ($frames) use (&$paths): void {
                foreach ($frames as $frame) {
                    $game = is_string($frame->game_path) ? $frame->game_path : '';
                    $master = is_string($frame->master_path) ? $frame->master_path : '';

                    if ($game !== '') {
                        $paths[$game] = $frame->availability === ContentAvailability::Published->value
                            ? (is_string($frame->published_hash) ? $frame->published_hash : '')
                            : ($paths[$game] ?? null);
                    }

                    if ($master !== '' && $frame->source_kind === FrameSourceKind::Capture->value) {
                        $paths[$master] ??= null;
                    }
                }
            });

        ksort($paths, SORT_STRING);

        $missing = 0;
        $altered = 0;

        foreach ($paths as $path => $expected) {
            $hash = self::sha256($disk, (string) $path);

            if ($hash === null) {
                $missing++;

                continue;
            }

            if ($expected !== null && ($expected === '' || ! hash_equals($expected, $hash))) {
                $altered++;

                continue;
            }

            $this->line($hash.' '.$path);
        }

        if ($missing > 0) {
            $this->writeError('manifest_missing', ['count' => $missing]);
        }

        if ($altered > 0) {
            $this->writeError('manifest_altered', ['count' => $altered]);
        }

        return $missing + $altered === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Une ligne `admin.console.backup.<clé>`, en français, sur la sortie
     * d'erreur : jamais dans le manifeste lui-même.
     *
     * @param  array<string, int>  $replace
     */
    private function writeError(string $key, array $replace): void
    {
        $line = trans('admin.console.backup.'.$key, $replace, Locale::French->value);
        $this->getOutput()->getErrorStyle()->writeln(is_string($line) ? $line : '');
    }
}
