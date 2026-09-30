<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `player.kicked_at` — expulsion par l'hôte (D15 du 23/09, spec 10 § 7.1,
 * E10-01), migration additive n° 44 du lot 9 de § 13.2.
 *
 * **Uniquement un ajout**, jamais une réécriture de la migration de création
 * déjà jouée : la production naît dès le jalon 1 et `migrate` ne rejoue jamais
 * ce qu'il a déjà joué. `player` n'est pas une table sanctuarisée (règle 12) :
 * aucun instantané bloquant n'est requis.
 *
 * `timestamp(3)` comme toute colonne datée de `player` (§ 1.2) : posée dans la
 * même transaction que `connection_state = left`, au même instant serveur que
 * `left_at`, à la milliseconde. Nullable et sans défaut ; **jamais remise à
 * NULL** (pas de réadmission) ; aucun index : lue par `player_room_token_uq`
 * (`room_id`, `player_token_hash`), dont le hash s'efface à l'archivage — ce qui
 * fait tomber le refus de lui-même.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->timestamp('kicked_at', 3)->nullable()->after('left_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropColumn('kicked_at');
        });
    }
};
