<?php

namespace App\Console\Commands;

use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La vérification d'une restauration — spec 100 § 13.5, étape (e), lot L100-10.
 *
 * Code 0 si et seulement si CHAQUE frame `published` a son fichier de jeu
 * présent sur le disque `frames` et de SHA-256 égal à `published_hash` ; sinon
 * code 1 et le nombre de fichiers manquants ou altérés.
 *
 * **Pourquoi le condensat et pas la seule présence** : la revue immuable porte
 * sur `published_hash` (§ 13.3). Un dérivé régénéré, tronqué ou déposé au
 * mauvais chemin aurait un autre condensat : servir ces octets reviendrait à
 * servir une image que personne n'a revue.
 *
 * Est **manquant** un dérivé sans `game_path`, hors du préfixe `game/` ou
 * absent du disque ; est **altéré** un dérivé présent dont le condensat diffère
 * de `published_hash`, ou dont `published_hash` est vide (rien ne prouve alors
 * ce qui a été revu).
 *
 * Lecture seule : aucune ligne n'est écrite, aucun fichier n'est touché. Sortie
 * en français, par clés littérales du domaine `admin` et locale forcée, comme
 * `backup:snapshot`.
 */
class BackupVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie que chaque frame publiée a son fichier de jeu présent, au condensat publié';

    /** Frames lues par lot. */
    private const int CHUNK_SIZE = 500;

    public function handle(): int
    {
        $disk = Storage::disk(FrameStoragePrefix::DISK);
        $checked = 0;
        $missing = 0;
        $altered = 0;

        DB::table('frame')
            ->select(['id', 'game_path', 'published_hash'])
            ->where('availability', ContentAvailability::Published->value)
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, static function ($frames) use ($disk, &$checked, &$missing, &$altered): void {
                foreach ($frames as $frame) {
                    $checked++;
                    $path = is_string($frame->game_path) ? $frame->game_path : '';

                    $hash = $path !== '' && FrameStoragePrefix::Game->owns($path)
                        ? BackupManifestCommand::sha256($disk, $path)
                        : null;

                    if ($hash === null) {
                        $missing++;

                        continue;
                    }

                    $expected = is_string($frame->published_hash) ? $frame->published_hash : '';

                    if ($expected === '' || ! hash_equals($expected, $hash)) {
                        $altered++;
                    }
                }
            });

        if ($missing + $altered === 0) {
            $this->components->info($this->translated('verify_ok', ['count' => $checked]));

            return self::SUCCESS;
        }

        $this->components->error($this->translated('verify_failed', [
            'count' => $missing + $altered,
            'missing' => $missing,
            'altered' => $altered,
            'checked' => $checked,
        ]));

        return self::FAILURE;
    }

    /**
     * Une ligne `admin.console.backup.<clé>`, en français.
     *
     * @param  array<string, int>  $replace
     */
    private function translated(string $key, array $replace): string
    {
        $line = trans('admin.console.backup.'.$key, $replace, Locale::French->value);

        return is_string($line) ? $line : '';
    }
}
