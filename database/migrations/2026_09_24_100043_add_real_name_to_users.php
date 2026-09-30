<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.real_name` — nom réel d'un compte privilégié (D12 du 23/09, spec 10
 * § 5.1, E10-02), migration additive n° 43 du lot 9 de § 13.2.
 *
 * **Uniquement un ajout**, jamais une réécriture d'une migration de création
 * déjà jouée : la production naît dès le jalon 1 et `migrate` ne rejoue jamais
 * ce qu'il a déjà joué. `users` n'est pas une table sanctuarisée (règle 12) :
 * aucun instantané bloquant n'est requis.
 *
 * Nullable et sans défaut : un joueur n'a pas de nom réel, et la règle
 * « rôle ≥ `curator` ⟹ nom réel non vide » est une garde de modèle
 * (`User::saving`), déclenchée seulement quand `role` ou `real_name` change,
 * pour ne jamais bloquer un compte existant. Aucun index : le nom réel n'est
 * jamais un critère de recherche, seulement l'instantané que figent
 * `frame_review.reviewer_name` et `admin_action.actor_name`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('real_name', 255)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('real_name');
        });
    }
};
