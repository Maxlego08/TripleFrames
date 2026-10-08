<?php

namespace Database\Factories;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Frames\WebpPadding;
use App\Support\Identity\PublicId;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Imagick;
use RuntimeException;

/**
 * Fabrique de {@see Frame}.
 *
 * `definition()` alimente EXACTEMENT les colonnes `NOT NULL` de la migration —
 * celles sans défaut comme celles qui en portent un, reprises à l'identique. Une
 * frame par défaut est donc un BROUILLON : `draft`, `pending`, aucun chemin,
 * aucun octet sur disque, et c'est voulu. Le balayage des 36 modèles instancie
 * chaque table ; si l'état par défaut écrivait des fichiers, tout test du dépôt
 * devrait appeler `Storage::fake('frames')` pour créer un film.
 *
 * **C'est ici que vivent les deux faits qui portent sur des octets réels** (§ 13.3,
 * exigence 1). {@see self::published()} écrit un VRAI fichier WebP, relit les
 * octets écrits, calcule `published_hash` sur eux, crée la `frame_review`
 * `passed` avec `reviewed_hash = $frame->published_hash` et pose
 * `frame.published_review_id`. Jamais deux empreintes tirées indépendamment :
 * avec un `fake()->sha256()` de chaque côté, la garde de publication ne trouve
 * aucune preuve, aucun film n'est publiable, et le lobby affiche « 0 film » sur
 * un catalogue de démonstration complet. Sans fichier réel, la première manche
 * signe une URL vers un objet absent : `isServable()` est vrai en base, le moteur
 * n'emprunte pas le chemin de substitution, et l'image reste cassée `D` secondes.
 *
 * **Tout test qui appelle {@see self::withFiles()}, {@see self::published()} ou
 * {@see self::processingFailed()} avec un échec rejouable (le défaut) doit
 * d'abord appeler `Storage::fake('frames')`**, sans quoi les octets partent
 * dans la racine réelle du disque — `FRAMES_DISK_ROOT`, à défaut
 * `storage/app/frames` — et y restent après le `RefreshDatabase` qui, lui, annule
 * la ligne. Un fichier ne participe à aucune transaction. Ce n'est plus une
 * consigne de docblock : {@see self::write()} lève sous `php artisan test` quand
 * la racine du disque n'est pas celle d'un `Storage::fake()`, de sorte que le
 * premier test fautif échoue par son nom au lieu de polluer silencieusement.
 *
 * **Les fichiers sont au format de la chaîne réelle** (contrat C9, spec 20
 * § 5.5) : un dérivé WebP de EXACTEMENT {@see FrameGeometry::GAME_WIDTH} ×
 * {@see FrameGeometry::GAME_HEIGHT}, paddé au multiple de
 * {@see FrameGeometry::GAME_PAD_BYTES} par {@see WebpPadding}, un master de
 * {@see FrameGeometry::MASTER_WIDTH} × 1080, et le rectangle par défaut de
 * {@see FrameGeometry::defaultCrop()}. Sans cela, la sonde « aucune frame
 * `ready` hors 1280 × 720 ou hors padding » et l'audit du plancher (§ 5.9)
 * rejetteraient les fixtures elles-mêmes.
 *
 * **{@see self::processingFailed()} est exclusive de {@see self::withFiles()}
 * et de {@see self::published()}** : l'écriture des octets vit dans une
 * fermeture de `state()`, donc chaîner l'échec après les fichiers laisse le
 * dérivé sur le disque tout en ramenant `game_path` à `NULL` — des octets
 * orphelins, et après `published()` une ligne `published` + `failed` que le
 * prédicat unique de variante jouable (§ 3.2) existe justement pour rendre
 * impossible.
 *
 * @extends Factory<Frame>
 */
class FrameFactory extends Factory
{
    /**
     * Hauteur du master de fixture : celle d'une source 16:9, donc 1080 pour la
     * largeur exacte de 1920 — calculée, jamais écrite en littéral.
     */
    private const int MASTER_HEIGHT = FrameGeometry::MASTER_WIDTH * FrameGeometry::ASPECT_HEIGHT / FrameGeometry::ASPECT_WIDTH;

    /** Couleur de l'aplat : une image de fixture ne montre rien, et ne ressemble à aucune image réelle. */
    private const string FLAT_COLOR = 'gray50';

    /**
     * Les deux conteneurs WebP de fixture, encodés une fois par processus :
     * `game` (déjà paddé) et `master`.
     *
     * @var array<string, string>
     */
    private static array $blobs = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Identité publique (D63 du 07/10) : le générateur de production,
            // que la garde `creating` du modèle appellerait de toute façon.
            'public_id' => PublicId::generate(),
            'movie_id' => Movie::factory(),
            'frame_level' => FrameLevel::Level3,
            'availability' => ContentAvailability::Draft,
            'processing_state' => FrameProcessingState::Pending,
            'source_kind' => FrameSourceKind::Tmdb,
            // Référence du visuel TMDB d'origine, conservée pour toujours : c'est
            // elle qui rend l'image retéléchargeable au lieu d'être sauvegardée
            // en octets. Jamais un titre, jamais une année.
            'tmdb_file_path' => '/'.bin2hex(random_bytes(13)).'.jpg',
            // Empreinte des octets REÇUS, calculée côté serveur. L'original non
            // recadré n'est jamais conservé, seule son empreinte l'est — elle ne
            // vaut donc jamais `published_hash`.
            'source_hash' => hash('sha256', random_bytes(32)),
            // Rectangle conservé, exprimé dans l'espace du master de 1920 px,
            // et non dans celui du dérivé servi : le plus grand cadre admis par
            // le plancher, centré — celui que le recadreur propose d'abord.
            ...self::cropColumns(),
        ];
    }

    /**
     * Le rectangle par défaut du master de fixture, en colonnes `crop_*`.
     *
     * @return array{crop_x: int, crop_y: int, crop_width: int, crop_height: int}
     */
    private static function cropColumns(): array
    {
        $crop = FrameGeometry::defaultCrop(self::MASTER_HEIGHT, PlatformLimits::current());

        return [
            'crop_x' => $crop->x,
            'crop_y' => $crop->y,
            'crop_width' => $crop->width,
            'crop_height' => $crop->height,
        ];
    }

    /**
     * Niveau de cryptivité de l'image — propriété STABLE fixée en curation, à ne
     * jamais confondre avec `tier_index`, qui naît du tirage de la manche.
     */
    public function level(FrameLevel $level): static
    {
        return $this->state(fn (array $attributes): array => [
            'frame_level' => $level,
        ]);
    }

    /**
     * Capture personnelle plutôt que visuel TMDB.
     *
     * Le timecode désigne un instant DANS L'ŒUVRE : il identifie l'œuvre, jamais
     * la méthode d'extraction, et le schéma ne porte aucune colonne de support,
     * d'édition, d'appareil ni de logiciel (arbitrage A7).
     */
    public function capture(?int $timecodeMs = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_kind' => FrameSourceKind::Capture,
            'tmdb_file_path' => null,
            'source_timecode_ms' => $timecodeMs ?? fake()->numberBetween(60_000, 5_400_000),
        ]);
    }

    /**
     * Les deux dérivés du job d'image, RÉELLEMENT écrits sur le disque `frames`.
     *
     * Deux noms aléatoires INDÉPENDANTS tirés de `bin2hex(random_bytes(16))` et
     * jamais d'un ULID (arbitrage A8) : un ULID est trié dans le temps, et un
     * éclatement sur son préfixe regrouperait les images curées dans la même
     * séance, c'est-à-dire celles d'un même film. Un nom partagé entre les deux
     * préfixes ferait en outre qu'un service de jeu légitime révélerait le chemin
     * de la source de re-cadrage, qui n'est jamais servie.
     *
     * `published_hash` est calculé sur les octets RELUS depuis le disque, jamais
     * sur ceux qu'on croit avoir écrits : c'est la seule formulation qui prouve
     * à la fois l'existence du fichier et l'exactitude de l'empreinte.
     *
     * Le dérivé est un aplat mis en cache : toutes les fixtures servent donc les
     * MÊMES octets, et le même `published_hash`. Rien ne l'interdit — aucune
     * colonne n'est unique sur une empreinte —, et la preuve de revue reste liée
     * à sa frame par `published_review_id`, jamais par l'empreinte seule.
     *
     * La frame reste `draft` : produire le dérivé n'est pas le publier.
     */
    public function withFiles(): static
    {
        return $this->state(function (array $attributes): array {
            $paths = FrameStoragePrefix::newPathPair();

            self::write($paths['master'], self::masterBlob());

            $game = self::write($paths['game'], self::gameBlob());

            return [
                'processing_state' => FrameProcessingState::Ready,
                'processing_error' => null,
                'master_path' => $paths['master'],
                'game_path' => $paths['game'],
                'published_hash' => hash('sha256', $game),
                'game_bytes' => strlen($game),
                'game_width' => FrameGeometry::GAME_WIDTH,
                'game_height' => FrameGeometry::GAME_HEIGHT,
                'crop_seconds' => fake()->numberBetween(20, 240),
            ];
        });
    }

    /**
     * La chaîne complète de l'exigence 1 du § 13.3 : des octets, une empreinte
     * calculée sur eux, une preuve qui cite cette empreinte, et le lien de la
     * frame vers cette preuve exacte.
     *
     * La condition de publication d'une frame, et elle seule : il existe une
     * ligne `decision = passed` dont `reviewed_hash = frame.published_hash` et
     * dont `grid_version` est la version courante de la grille, et
     * `frame.published_review_id` désigne CETTE ligne. La garde interroge
     * `frame_review` ; les copies `reviewed_at` / `review_grid_version` portées
     * par la frame ne sont qu'une file de travail, écrite ici par le même
     * observateur que la ligne de preuve.
     *
     * Le curateur est facultatif pour un test, mais le seeder de démonstration
     * DOIT le passer : l'exigence 5 du § 13.3 veut une preuve nominative, et
     * `reviewer_name` / `reviewer_role` sont des instantanés pris à l'instant de
     * la revue, explicitement exclus de l'anonymisation.
     */
    public function published(?User $reviewer = null): static
    {
        return $this->withFiles()
            ->state(fn (array $attributes): array => [
                'availability' => ContentAvailability::Published,
                'availability_changed_at' => now(),
                'first_published_at' => now(),
            ])
            ->afterCreating(function (Frame $frame) use ($reviewer): void {
                $reviews = FrameReview::factory()->passed()->forFrame($frame);

                if ($reviewer instanceof User) {
                    $reviews = $reviews->by($reviewer);
                }

                $review = $reviews->create();

                $frame->forceFill([
                    'published_review_id' => $review->id,
                    'reviewed_at' => $review->reviewed_at,
                    'review_grid_version' => $review->grid_version,
                ])->save();
            });
    }

    /**
     * Échec du job d'image au PREMIER traitement : la ligne précède le fichier
     * final, et une frame non traitée ne doit jamais entrer en jeu.
     *
     * `processing_error` est un cas de {@see FrameProcessingFailure}, dont la
     * valeur est une CLÉ DE TRADUCTION, jamais un message brut : le back-office
     * doit rester lisible par un non-technicien (spec 20 § 5.6).
     *
     * `master_path` reste posé, comme après un vrai échec (colonne UNIQUE,
     * jamais réaffectée), et le disque suit la chaîne réelle (§ 5.6) :
     *
     * - un échec DÉFINITIF n'a aucun fichier sous ce chemin — le job supprime
     *   les octets provisoires d'un échec définitif au premier traitement ;
     * - un échec REJOUABLE (le défaut, `unexpected`) garde ses octets, que
     *   « Relancer » réutilise : la fabrique y écrit le master de fixture, un
     *   WebP de 1920 de large dont l'empreinte n'est pas `source_hash` — un
     *   échec survenu après la normalisation. Un rejouable écrit donc des
     *   octets, et exige `Storage::fake('frames')` comme {@see self::withFiles()}.
     *
     * Un `master_path` déjà fourni par un état antérieur appartient à
     * l'appelant : ses octets ne sont jamais écrasés.
     */
    public function processingFailed(FrameProcessingFailure $failure = FrameProcessingFailure::Unexpected): static
    {
        return $this->state(function (array $attributes) use ($failure): array {
            $master = $attributes['master_path'] ?? null;

            if (! is_string($master)) {
                $master = FrameStoragePrefix::Master->newPath();

                if ($failure->isRetryable()) {
                    self::write($master, self::masterBlob());
                }
            }

            return [
                'processing_state' => FrameProcessingState::Failed,
                'processing_error' => $failure,
                'game_path' => null,
                'master_path' => $master,
                'published_hash' => null,
                'game_bytes' => null,
                'game_width' => null,
                'game_height' => null,
            ];
        });
    }

    /**
     * Retrait juridique — terminal, aucune transition n'en sort.
     *
     * Les deux chemins ne passent PAS à NULL (arbitrage A9) : les effacer rendrait
     * les fichiers innommables si le job de suppression échouait. C'est
     * `files_deleted_at` qui atteste que les objets ont réellement disparu, et
     * elle n'est posée qu'après un `missing()` vérifié — donc jamais ici.
     */
    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'availability' => ContentAvailability::Withdrawn,
            'availability_changed_at' => now(),
        ]);
    }

    /**
     * Écrit les octets, les relit, et rend ceux qui sont RÉELLEMENT sur le disque.
     *
     * La relecture n'est pas une précaution de style : c'est elle qui transforme
     * « le fichier devrait être là » en un fait, et le poste le plus délicat de
     * cette phase est précisément celui dont l'échec se diagnostique le plus mal.
     */
    private static function write(string $path, string $bytes): string
    {
        $disk = Storage::disk(FrameStoragePrefix::DISK);

        // La garde qui transforme la pollution silencieuse en échec nommé au
        // premier test fautif. `RefreshDatabase` annule les lignes, jamais les
        // octets : sans `Storage::fake()`, chaque exécution dépose des fichiers de
        // fixture dans la racine réelle du disque, sans aucune ligne pour les
        // nommer — et plus rien ne les distingue des images curées réelles.
        // Séparateurs normalisés : `storage_path()` colle un chemin en `/` derrière
        // une base en `\` sous Windows, et la comparaison brute échouerait toujours.
        $root = str_replace('\\', '/', $disk->path(''));
        $fakeRoot = str_replace('\\', '/', storage_path('framework/testing'));

        if (app()->runningUnitTests() && ! str_starts_with($root, $fakeRoot)) {
            throw new RuntimeException(
                'Storage::fake(FrameStoragePrefix::DISK) est obligatoire avant toute fixture qui écrit des '
                .'octets : sans lui, les fichiers partent dans la racine réelle du disque `frames` et y restent '
                .'après le RefreshDatabase, qui n’annule que la ligne.',
            );
        }

        $disk->put($path, $bytes);

        $written = $disk->get($path);

        if ($written !== $bytes) {
            throw new RuntimeException(
                "Le fichier d'image [{$path}] n'a pas été écrit sur le disque ["
                .FrameStoragePrefix::DISK."]. Le contrat de fixture exige des octets RÉELS : c'est sur eux "
                .'que `published_hash` est calculé, et sans eux la première manche signe une URL vers un objet absent.',
            );
        }

        return $written;
    }

    /**
     * Le dérivé servi de fixture : un aplat WebP de EXACTEMENT
     * {@see FrameGeometry::GAME_WIDTH} × {@see FrameGeometry::GAME_HEIGHT},
     * paddé par {@see WebpPadding} — des NUL après le bloc RIFF, champ de taille
     * intact — au multiple de {@see FrameGeometry::GAME_PAD_BYTES}, comme le
     * produit la chaîne réelle.
     */
    private static function gameBlob(): string
    {
        return self::$blobs['game'] ??= WebpPadding::pad(
            self::flat(FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT),
        );
    }

    /**
     * Le master de fixture : un aplat WebP de {@see FrameGeometry::MASTER_WIDTH}
     * × 1080, l'espace du rectangle par défaut.
     */
    private static function masterBlob(): string
    {
        return self::$blobs['master'] ??= self::flat(FrameGeometry::MASTER_WIDTH, self::MASTER_HEIGHT);
    }

    /**
     * Un aplat WebP, généré par Imagick et par rien d'autre — ni `gd`, ni `exif`
     * ne sont installées, et une image de jeu réelle ou un extrait de base de
     * production sont absolument interdits en fixtures.
     *
     * **Mis en cache par {@see self::gameBlob()} et {@see self::masterBlob()}**,
     * et c'est mesuré : un catalogue de démonstration complet demande plus de
     * deux cents fichiers, rejoués à chaque `beforeEach` d'un fichier de test.
     * Un aplat s'encode en quelques centaines d'octets : le dérivé paddé pèse
     * un seul quantum.
     */
    private static function flat(int $width, int $height): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException(
                "L'extension `imagick` est absente : le contrat de fixture du § 13.3 exige un fichier WebP réel. "
                .'Elle est déclarée `ext-imagick` dans composer.json et installée par la clé `extensions:` du workflow CI.',
            );
        }

        $image = new Imagick;
        $image->newImage($width, $height, self::FLAT_COLOR);
        $image->setImageFormat('webp');
        // Aucune métadonnée ne survit : le schéma ne porte aucune colonne EXIF, et
        // la source ne doit pas revenir par le fichier.
        $image->stripImage();

        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }
}
