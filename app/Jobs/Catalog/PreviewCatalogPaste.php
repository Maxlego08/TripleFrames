<?php

namespace App\Jobs\Catalog;

use App\Support\Admin\PastePreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * L'aperçu à blanc d'un collage, **différé** — spec 20 § 3.3.
 *
 * Un collage de cinquante identifiants coûte cinquante appels de détail
 * TMDB, soit bien plus que les trente secondes du SAPI web : la requête
 * ouvre l'aperçu en cache ({@see PastePreview::open()}), confie ce job à la
 * file par DÉFAUT — jamais une file de jeu, aucun `onQueue()` — et rend la
 * main ; l'écran sonde le résultat.
 *
 * **Aucune logique d'import n'est réécrite** : le job appelle
 * `catalog:import-ids --preview=<jeton>`, en simulation, ce qui garde
 * `TmdbClient` dans son périmètre autorisé (`TmdbBoundaryTest`) et rejoue
 * exactement les gardes de l'import réel — dédoublonnage, film retiré,
 * filtre de contenu — sans rien écrire, **pas même une ligne `import_run`**.
 *
 * Pas de nouvelle tentative : un aperçu interrompu se relance d'un bouton, et
 * {@see self::failed()} le marque interrompu plutôt que « en cours » pour
 * l'éternité. Aucune garde de rôle relue ici, à la différence de
 * `RunCatalogImport` : rien n'est écrit au catalogue, et l'aperçu n'est
 * lisible que par son auteur.
 */
class PreviewCatalogPaste implements ShouldQueue
{
    use Queueable;

    /** Un aperçu interrompu se relance d'un bouton, jamais en silence. */
    public int $tries = 1;

    /** Le même plafond que l'import réel du même collage. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $userId  l'auteur, dont l'identifiant est dans la clé du cache
     * @param  string  $token  le jeton de l'aperçu
     * @param  list<int>  $identifiers  le collage, dans l'ordre du curateur
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $token,
        public readonly array $identifiers,
    ) {}

    public function handle(): void
    {
        $status = Artisan::call('catalog:import-ids', [
            // Des chaînes, comme pour l'import réel : un entier nu serait
            // ignoré par la lecture du collage.
            'ids' => array_map(strval(...), $this->identifiers),
            '--preview' => $this->token,
            '--actor' => (string) $this->userId,
        ]);

        // La commande marque elle-même l'aperçu dans tous les cas qu'elle
        // connaît ; ce filet couvre un refus d'entrée qu'elle n'aurait pas
        // nommé. Un aperçu déjà terminé n'est jamais réécrit.
        if ($status !== 0) {
            PastePreview::fail($this->userId, $this->token);
        }
    }

    /**
     * Un job perdu ne laisse jamais un aperçu « en cours » : l'écran cesse de
     * sonder et propose « Réessayer ».
     */
    public function failed(?Throwable $exception): void
    {
        PastePreview::fail($this->userId, $this->token);
    }
}
