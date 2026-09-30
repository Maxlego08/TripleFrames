<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `player.solo_token_hash` et `player_solo_token_uq` — créneau d'unicité du
 * siège solo (E10-N3, spec 10 § 7.1, spec 60 § 16.2 et § 21), migration
 * additive n° 45 du lot 9 de § 13.2, livrée par le lot du démarrage solo
 * (L60-15).
 *
 * **Uniquement un ajout**, jamais une réécriture de la migration de création
 * déjà jouée : la production naît dès le jalon 1 et `migrate` ne rejoue jamais
 * ce qu'il a déjà joué. `player` n'est pas une table sanctuarisée (règle 12) :
 * aucun instantané bloquant n'est requis.
 *
 * **Pourquoi une colonne.** L'unicité `player_room_token_uq (room_id,
 * player_token_hash)` est inopérante pour un siège solo (`room_id IS NULL`) :
 * les deux moteurs ignorent les NULL dans un UNIQUE (§ 1.4). Sans ligne à
 * verrouiller, deux premiers lancements solo concurrents sous un même jeton
 * créeraient deux sièges. Le créneau porte l'invariant « un jeton = un siège
 * solo » **en base** : copie de `player_token_hash` si et seulement si
 * `room_id IS NULL`, NULL pour tout siège de salon, écrite par l'application
 * dans la même écriture que `player_token_hash` et effacée avec lui (échéance
 * des sièges solo, anonymisation).
 *
 * **Colonne ordinaire, jamais générée** (§ 1.4) : SQLite refuse un `STORED`
 * ajouté par `ALTER`. Même motif que `users.email` et
 * `room.room_code_active` : créneau nullable + UNIQUE ordinaire, les NULL
 * multiples des sièges de salon étant admis par les deux moteurs.
 * `string(64)` comme `player_token_hash` (SHA-256 hexadécimal), placée juste
 * après lui ; `#[Hidden]` sur le modèle. L'unique sert aussi d'index : aucun
 * index de plus.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->string('solo_token_hash', 64)->nullable()->after('player_token_hash');

            $table->unique('solo_token_hash', 'player_solo_token_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropUnique('player_solo_token_uq');
            $table->dropColumn('solo_token_hash');
        });
    }
};
