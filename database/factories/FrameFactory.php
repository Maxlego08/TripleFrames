<?php

namespace Database\Factories;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
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
 * **Tout test qui appelle {@see self::withFiles()} ou {@see self::published()}
 * doit d'abord appeler `Storage::fake('frames')`**, sans quoi les octets partent
 * dans la racine réelle du disque — `FRAMES_DISK_ROOT`, à défaut
 * `storage/app/frames` — et y restent après le `RefreshDatabase` qui, lui, annule
 * la ligne. Un fichier ne participe à aucune transaction. Ce n'est plus une
 * consigne de docblock : {@see self::write()} lève sous `php artisan test` quand
 * la racine du disque n'est pas celle d'un `Storage::fake()`, de sorte que le
 * premier test fautif échoue par son nom au lieu de polluer silencieusement.
 *
 * **{@see self::published()} et {@see self::processingFailed()} sont mutuellement
 * exclusives** : l'écriture des octets vit dans une fermeture de `state()`, donc
 * chaîner la seconde après la première laisse les fichiers sur le disque tout en
 * ramenant les chemins à `NULL` — des octets orphelins ET une ligne
 * `published` + `failed` que le prédicat unique de variante jouable (§ 3.2)
 * existe justement pour rendre impossible.
 *
 * @extends Factory<Frame>
 */
class FrameFactory extends Factory
{
    /**
     * Quantum de quantification du conteneur WebP servi, en octets (§ 4.1).
     *
     * Le job de réencodage pousse la taille au multiple de 8 Ko supérieur par des
     * octets inertes en queue de RIFF : sans cela, `147 231` octets identifient
     * une image aussi sûrement qu'un chemin, et le dictionnaire (taille → titre)
     * se reconstruit sans jamais toucher à l'URL. Les fixtures respectent la même
     * règle, faute de quoi le test `game_bytes % 8192 = 0` tombe sur elles.
     */
    private const int GAME_BYTES_QUANTUM = 8192;

    /** Plafond de sortie du dérivé servi, en octets — 150 Ko (§ 10). */
    private const int GAME_BYTES_MAX = 153600;

    /** Dérivé servi : minuscule, très en dessous du plafond réel de 1280 px. */
    private const int GAME_WIDTH = 32;

    private const int GAME_HEIGHT = 18;

    /** Source de re-cadrage : minuscule elle aussi, plafond réel 1920 px. */
    private const int MASTER_WIDTH = 48;

    private const int MASTER_HEIGHT = 27;

    /**
     * Conteneurs WebP déjà encodés, clés par couple de dimensions.
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
            // Rectangle conservé, exprimé dans l'espace de la source de
            // re-cadrage 1920 px, et non dans celui du dérivé servi.
            'crop_x' => 0,
            'crop_y' => 0,
            'crop_width' => 1920,
            'crop_height' => 1080,
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
     * La frame reste `draft` : produire le dérivé n'est pas le publier.
     */
    public function withFiles(): static
    {
        return $this->state(function (array $attributes): array {
            $paths = FrameStoragePrefix::newPathPair();

            self::write($paths['master'], self::webp(self::MASTER_WIDTH, self::MASTER_HEIGHT));

            $game = self::write(
                $paths['game'],
                self::quantize(self::webp(self::GAME_WIDTH, self::GAME_HEIGHT)),
            );

            return [
                'processing_state' => FrameProcessingState::Ready,
                'processing_error' => null,
                'master_path' => $paths['master'],
                'game_path' => $paths['game'],
                'published_hash' => hash('sha256', $game),
                'game_bytes' => strlen($game),
                'game_width' => self::GAME_WIDTH,
                'game_height' => self::GAME_HEIGHT,
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
     * Échec du job d'image différé : la ligne précède le fichier final, et une
     * frame non traitée ne doit jamais entrer en jeu.
     *
     * `processing_error` est une CLÉ DE TRADUCTION, jamais un message brut : le
     * back-office doit rester lisible par un non-technicien.
     */
    public function processingFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_state' => FrameProcessingState::Failed,
            'processing_error' => 'curation.frame_processing.reencode_failed',
            'game_path' => null,
            'master_path' => null,
            'published_hash' => null,
            'game_bytes' => null,
            'game_width' => null,
            'game_height' => null,
        ]);
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
     * Pousse le conteneur au multiple de 8 Ko supérieur par des octets inertes en
     * queue de RIFF — la taille RIFF borne l'image, les décodeurs ignorent la
     * queue. L'anti-corrélation porte sur trois surfaces, et la taille servie en
     * est une.
     */
    private static function quantize(string $bytes): string
    {
        $target = (int) ceil(max(strlen($bytes), 1) / self::GAME_BYTES_QUANTUM) * self::GAME_BYTES_QUANTUM;

        if ($target > self::GAME_BYTES_MAX) {
            throw new RuntimeException(
                'Le dérivé de fixture dépasse le plafond de sortie de '.self::GAME_BYTES_MAX.' octets.',
            );
        }

        $padding = $target - strlen($bytes);

        // La queue de rembourrage est tirée d'un CSPRNG, et c'est elle qui porte
        // désormais l'unicité : le conteneur WebP est mémorisé par couple de
        // dimensions ({@see self::webp()}), donc deux frames partagent les mêmes
        // pixels — mais jamais les mêmes octets servis, donc jamais le même
        // `published_hash`. Les décodeurs ignorent la queue, la taille RIFF bornant
        // l'image ; un rembourrage aléatoire est aussi inerte qu'un rembourrage nul.
        return $padding > 0 ? $bytes.random_bytes($padding) : $bytes;
    }

    /**
     * Une image WebP minuscule, générée par Imagick et par rien d'autre — ni `gd`,
     * ni `exif` ne sont installées, et une image de jeu réelle ou un extrait de
     * base de production sont absolument interdits en fixtures.
     *
     * **Mémorisée par couple de dimensions**, et c'est mesuré : un catalogue de
     * démonstration complet demande plus de deux cents encodages, rejoués à chaque
     * `beforeEach` d'un fichier de test. L'unicité des octets servis ne vient donc
     * plus des pixels mais de la queue de rembourrage de {@see self::quantize()},
     * qui reste tirée d'un CSPRNG par frame : `published_hash` est toujours unique,
     * et chaque revue reste liée à des octets qui n'appartiennent qu'à elle. Seuls
     * les dérivés `master/`, jamais servis et jamais empreintés, deviennent
     * identiques entre eux — aucune colonne ni aucun invariant ne l'interdit.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private static function webp(int $width, int $height): string
    {
        $memo = $width.'x'.$height;

        if (array_key_exists($memo, self::$blobs)) {
            return self::$blobs[$memo];
        }

        if (! extension_loaded('imagick')) {
            throw new RuntimeException(
                "L'extension `imagick` est absente : le contrat de fixture du § 13.3 exige un fichier WebP réel. "
                .'Elle est déclarée `ext-imagick` dans composer.json et installée par la clé `extensions:` du workflow CI.',
            );
        }

        $pixels = [];

        foreach (str_split(random_bytes($width * $height * 3)) as $byte) {
            $pixels[] = ord($byte);
        }

        $image = new Imagick;
        $image->newImage($width, $height, 'black');
        $image->importImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR, $pixels);
        $image->setImageFormat('webp');
        $image->setOption('webp:lossless', 'true');
        // Aucune métadonnée ne survit : le schéma ne porte aucune colonne EXIF, et
        // la source ne doit pas revenir par le fichier.
        $image->stripImage();

        $bytes = $image->getImageBlob();
        $image->clear();

        return self::$blobs[$memo] = $bytes;
    }
}
