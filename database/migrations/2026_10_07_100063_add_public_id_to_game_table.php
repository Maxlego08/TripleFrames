<?php

use App\Support\Identity\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `game.public_id` (D66 du 07/10, spec 10 § 7.2 ; spec 40 § 13.4, lot
 * L40-13) : l'identité publique d'une partie, base32 de Crockford aléatoire
 * ({@see PublicId}), jamais dérivée de l'`id`. Seule adresse du détail d'une
 * partie dans l'historique de son titulaire (`history.show`) ; jamais dans
 * une charge de jeu.
 *
 * `game` n'est PAS une table de la règle 12 : aucun instantané bloquant
 * n'est exigé par cette migration. Trois temps, portables SQLite et MySQL :
 * colonne nullable, rétro-remplissage par lots au constructeur de requêtes
 * (sans modèle : `updated_at` n'est pas touché, la garde des colonnes figées
 * n'est pas sollicitée), puis `NOT NULL` et UNIQUE `game_public_id_uq`.
 */
return new class extends Migration
{
    /** Taille d'un lot du rétro-remplissage. */
    private const int BACKFILL_CHUNK = 500;

    public function up(): void
    {
        Schema::table('game', function (Blueprint $table) {
            $table->char('public_id', PublicId::LENGTH)->nullable()->after('id');
        });

        DB::table('game')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(self::BACKFILL_CHUNK, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('game')->where('id', $row->id)->update(['public_id' => PublicId::generate()]);
                }
            });

        Schema::table('game', function (Blueprint $table) {
            $table->char('public_id', PublicId::LENGTH)->nullable(false)->change();
            $table->unique('public_id', 'game_public_id_uq');
        });
    }

    public function down(): void
    {
        Schema::table('game', function (Blueprint $table) {
            $table->dropUnique('game_public_id_uq');
            $table->dropColumn('public_id');
        });
    }
};
