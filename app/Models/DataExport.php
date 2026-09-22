<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une archive d'export de données personnelles (§ 5.4).
 *
 * Le fichier produit est le concentré de données personnelles le plus dense que le
 * système fabrique : sans cette table, il n'est référencé nulle part, survit à la
 * suppression du compte, part dans chaque sauvegarde et n'apparaît dans aucun tableau
 * de conservation. Archive générée en job différé, URL signée valable sept jours.
 *
 * > **`deleted_at` N'EST PAS une colonne de `SoftDeletes`.** C'est la date d'effacement
 * > du FICHIER d'archive, une colonne métier castée `'datetime'`, point. Utiliser le
 * > trait ferait filtrer silencieusement les archives effacées par toute requête, et la
 * > purge par `expires_at` ne verrait plus rien. Aucun modèle du schéma n'utilise
 * > `SoftDeletes` : aucune autre table ne porte de `deleted_at`.
 *
 * La table porte des `timestamps()` CONVENTIONNELS malgré ses colonnes datées métier
 * (`requested_at`, `completed_at`, `expires_at`, `downloaded_at`, `deleted_at`) : ne
 * jamais la basculer en `$timestamps = false`, réservé à `frame_review` et `seen_frame`.
 *
 * Périmètre de purge `data_export`, piloté par `expires_at`, qui supprime le fichier
 * PUIS la ligne. L'action d'anonymisation supprime les archives du compte AVANT de
 * vider `users`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $path
 * @property int|null $size_bytes
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $downloaded_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Table('data_export')]
// Aucune colonne ne vient d'un formulaire : le chemin est nommé par ULID côté serveur,
// et les quatre échéances sont posées par la demande, le job et la purge.
#[Fillable([])]
// Le chemin ne quitte jamais le serveur : l'archive se télécharge par une URL signée à
// durée courte, et un chemin sérialisé annulerait le fait qu'il ne soit pas devinable.
#[Hidden(['path'])]
class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
