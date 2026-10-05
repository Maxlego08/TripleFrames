<?php

namespace App\Console\Commands;

use App\Enums\FrameLevel;
use App\Enums\UserRole;
use App\Jobs\Curation\ImportFrameBatch;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\ImportSnapshotGuard;
use App\Support\Curation\FrameBatch;
use App\Support\Curation\FrameBatchException;
use App\Support\Curation\FrameBatchImport;
use App\Support\Curation\TmdbFrameIntake;
use App\Support\Frames\CropRect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ajoute à leurs banques les images d'un lot — spec 20 § 5.10, D57 du 05/10.
 *
 * Deux sources, exclusives l'une de l'autre :
 *
 * - **un fichier** (`{file}`), le chemin réservé à la proposition locale :
 *   l'IA prépare le lot sur le poste, la commande en dépose les images en
 *   `draft`, et le porteur en valide les niveaux dans l'éditeur local ;
 * - **un lot du back-office** (`--batch`, `--actor` obligatoire), le moteur
 *   du job {@see ImportFrameBatch} : le lot vit en cache
 *   sous la clé de son auteur ({@see FrameBatchImport}), la commande y écrit
 *   le sort de chaque film pour que l'écran l'affiche.
 *
 * Chaque image passe par {@see TmdbFrameIntake} — exactement les gardes de
 * l'ajout unitaire de l'éditeur — puis par `AddFrame`, qui la crée `draft`
 * et `pending`, écrit sa ligne `frame.added` au nom réel de l'auteur et
 * distribue son traitement. **Rien n'est revu ni publié ici** : la revue et
 * la publication restent le geste du curateur, dans la base qui joue.
 *
 * **Règle 12** : la commande écrit `frame`. Lancée à la main hors `local` et
 * `testing`, elle prend un instantané bloquant avant sa première écriture
 * ({@see ImportSnapshotGuard}) ; le job du back-office, chemin ordinaire du
 * curateur, en est dispensé comme le balayage, et ne détruit rien.
 */
class CurationFrameBatchCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'curation:frame-batch
        {file? : Chemin d’un lot d’images JSON (format tripleframes.frame-batch)}
        {--batch= : Jeton d’un lot ouvert par le back-office}
        {--actor= : Identifiant ou e-mail du compte curateur auteur des images}
        {--dry-run : N’écrit rien ; affiche l’aperçu du lot}';

    /**
     * @var string
     */
    protected $description = 'Ajoute aux banques de leurs films les images d’un lot (draft, à revoir et publier dans le back-office)';

    public function handle(TmdbFrameIntake $intake): int
    {
        // Le domaine `admin` n'existe qu'en français : les motifs de refus
        // d'images, traduits par les gardes, le sont dans cette langue.
        App::setLocale('fr');

        $actor = $this->actor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $token = $this->option('batch');
        $file = $this->argument('file');

        if (is_string($token) && $token !== '') {
            return $this->fromBackOffice($intake, $actor, $token);
        }

        if (! is_string($file) || $file === '') {
            $this->components->error('Indiquez un fichier de lot, ou --batch.');

            return self::FAILURE;
        }

        return $this->fromFile($intake, $actor, $file);
    }

    private function fromFile(TmdbFrameIntake $intake, User $actor, string $file): int
    {
        $json = is_file($file) ? file_get_contents($file) : false;

        if (! is_string($json)) {
            $this->components->error('Fichier de lot introuvable ou illisible : '.$file);

            return self::FAILURE;
        }

        try {
            $batch = FrameBatch::fromJson($json);
        } catch (FrameBatchException $exception) {
            $this->components->error($exception->translated());

            return self::FAILURE;
        }

        $rows = FrameBatchImport::preview($actor, $batch);
        $this->renderPreview($rows);

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->guardSnapshot()) {
            return self::FAILURE;
        }

        foreach ($batch->movies as $index => $entry) {
            if ($rows[$index]['status'] !== FrameBatchImport::STATUS_READY) {
                continue;
            }

            [$added, $skipped, $refused] = $this->importMovie($intake, $actor, $entry);

            $this->components->twoColumnDetail(
                sprintf('%s (TMDB %d)', $rows[$index]['title'] ?? '—', $entry['tmdb_id']),
                sprintf('%d ajoutée(s), %d déjà présente(s), %d refusée(s)', $added, $skipped, count($refused)),
            );

            foreach ($refused as $message) {
                $this->line('    · '.$message);
            }
        }

        return self::SUCCESS;
    }

    private function fromBackOffice(TmdbFrameIntake $intake, User $actor, string $token): int
    {
        $state = FrameBatchImport::find($actor->id, $token);

        if ($state === null) {
            $this->components->error('Lot introuvable ou expiré.');

            return self::FAILURE;
        }

        try {
            $batch = FrameBatch::fromArray($state['batch']);
        } catch (FrameBatchException) {
            FrameBatchImport::fail($actor->id, $token);

            return self::FAILURE;
        }

        if (! $this->guardSnapshot()) {
            FrameBatchImport::fail($actor->id, $token, 'admin.frame_batch.snapshot_failed');

            return self::FAILURE;
        }

        FrameBatchImport::start($actor->id, $token);

        foreach ($batch->movies as $entry) {
            [$added, $skipped, $refused] = $this->importMovie($intake, $actor, $entry);
            FrameBatchImport::record($actor->id, $token, $entry['tmdb_id'], $added, $skipped, $refused);
        }

        FrameBatchImport::complete($actor->id, $token);

        return self::SUCCESS;
    }

    /**
     * Les images d'un film du lot, une à une : une image refusée n'arrête
     * jamais les suivantes.
     *
     * @param  array{tmdb_id: int, title: string|null, frames: list<array{tmdb_file_path: string, level: FrameLevel, crop: CropRect|null}>}  $entry
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function importMovie(TmdbFrameIntake $intake, User $actor, array $entry): array
    {
        $movie = Movie::query()->where('tmdb_id', $entry['tmdb_id'])->first();

        if ($movie === null || ! Gate::forUser($actor)->allows('create', [Frame::class, $movie])) {
            return [0, 0, []];
        }

        $added = 0;
        $skipped = 0;
        $refused = [];

        foreach ($entry['frames'] as $frame) {
            $movie->load('frames');

            // Un doublon déclaré n'est pas retéléchargé : `AddFrame` le
            // refuserait de toute façon, après coup.
            if ($frame['crop'] !== null && FrameBatchImport::isKnown($movie, $frame['tmdb_file_path'], $frame['crop']->toArray())) {
                $skipped++;

                continue;
            }

            try {
                $intake->add(
                    movie: $movie,
                    curator: $actor,
                    level: $frame['level'],
                    crop: $frame['crop'],
                    filePath: $frame['tmdb_file_path'],
                );
                $added++;
            } catch (ValidationException $exception) {
                $refused[] = sprintf('%s (niveau %d) : %s', $frame['tmdb_file_path'], $frame['level']->value, $this->firstMessage($exception));
            } catch (Throwable $exception) {
                Log::error('Image d’un lot en échec.', [
                    'movie_id' => $movie->id,
                    'exception' => $exception::class,
                ]);
                $refused[] = sprintf('%s (niveau %d) : %s', $frame['tmdb_file_path'], $frame['level']->value, __('admin.frame_batch.frame_failed', [], 'fr'));
            }
        }

        return [$added, $skipped, $refused];
    }

    /**
     * @param  list<array{tmdb_id: int, title: string|null, status: string, frames: int, known: int}>  $rows
     */
    private function renderPreview(array $rows): void
    {
        $this->table(
            ['TMDB', 'Titre', 'État', 'Images', 'Déjà présentes'],
            array_map(static fn (array $row): array => [
                $row['tmdb_id'],
                $row['title'] ?? '—',
                __('admin.frame_batch.status.'.$row['status'], [], 'fr'),
                $row['frames'],
                $row['known'],
            ], $rows),
        );
    }

    private function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return __('admin.frame_batch.frame_failed', [], 'fr');
    }

    /**
     * L'auteur des images : un compte curateur au moins. Sans auteur valide,
     * rien n'est écrit — chaque image porte `uploaded_by_id` et sa ligne de
     * journal au nom réel de son auteur.
     */
    private function actor(): ?User
    {
        $actor = $this->option('actor');

        if (! is_scalar($actor) || (string) $actor === '') {
            $this->components->error('--actor est obligatoire : le compte curateur auteur des images.');

            return null;
        }

        $actor = (string) $actor;

        $user = ctype_digit($actor)
            ? User::query()->whereKey((int) $actor)->first()
            : User::query()->where('email', $actor)->first();

        if (! $user instanceof User || ! $user->role->atLeast(UserRole::Curator)) {
            $this->components->error('Compte inconnu ou sans rôle de curateur : '.$actor);

            return null;
        }

        return $user;
    }

    /** Règle 12, comme les commandes d'import ({@see ImportSnapshotGuard}). */
    private function guardSnapshot(): bool
    {
        if (! ImportSnapshotGuard::required(false)) {
            return true;
        }

        if ($this->call('backup:snapshot') === self::SUCCESS) {
            return true;
        }

        $this->components->error(__('admin.frame_batch.snapshot_failed', [], 'fr'));

        return false;
    }
}
