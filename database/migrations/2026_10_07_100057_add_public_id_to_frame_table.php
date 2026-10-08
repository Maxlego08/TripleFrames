<?php

use App\Support\Identity\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `frame.public_id` (D63 du 07/10, spec 10 § 1.1 et § 4.1) : l'identité
 * publique d'une image, base32 de Crockford aléatoire ({@see PublicId}),
 * jamais dérivée de l'`id`. Elle n'adresse une image que dans le lien
 * « Signaler » de la révélation (`/report?frame=…`) : elle ne quitte le
 * serveur qu'**après** la révélation de la manche, jamais avant (règle 3), et
 * aucune route ne sert une image par elle.
 *
 * **Table de la règle 12** : `php artisan backup:snapshot` (code 0) avant
 * cette migration. Trois temps, portables SQLite et MySQL : colonne
 * nullable, rétro-remplissage par lots au constructeur de requêtes (sans
 * modèle : `updated_at` n'est pas touché, aucun événement), puis `NOT NULL`
 * et UNIQUE `frame_public_id_uq`.
 */
return new class extends Migration
{
    /** Taille d'un lot du rétro-remplissage. */
    private const int BACKFILL_CHUNK = 500;

    public function up(): void
    {
        Schema::table('frame', function (Blueprint $table) {
            $table->char('public_id', PublicId::LENGTH)->nullable()->after('id');
        });

        DB::table('frame')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(self::BACKFILL_CHUNK, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('frame')->where('id', $row->id)->update(['public_id' => PublicId::generate()]);
                }
            });

        Schema::table('frame', function (Blueprint $table) {
            $table->char('public_id', PublicId::LENGTH)->nullable(false)->change();
            $table->unique('public_id', 'frame_public_id_uq');
        });
    }

    public function down(): void
    {
        Schema::table('frame', function (Blueprint $table) {
            $table->dropUnique('frame_public_id_uq');
            $table->dropColumn('public_id');
        });
    }
};
