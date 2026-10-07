<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pause manuelle d'une partie (D64 du 07/10, spec 10 § 7.2, spec 60
 * § 14) :
 *
 * - `pause_kind` : nature de la pause en cours (`empty` : plus aucun siège
 *   présent ; `manual` : geste de l'hôte ou du joueur solo), NULL hors
 *   pause ;
 * - `pause_requested_at` : demande de pause en attente, prise d'effet à la
 *   fin de la révélation de la manche en cours ; NULL sans demande ;
 * - `manual_paused_ms` : budget consommé par les pauses manuelles de la
 *   partie (attente PUIS décompte de reprise), plafonné par
 *   `EngineConstants::pauseTimeoutMs()` — distinct de `total_paused_ms`,
 *   qui cumule aussi les pauses automatiques.
 *
 * Ne touche aucune table de la règle 12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game', function (Blueprint $table) {
            $table->string('pause_kind', 10)->nullable()->after('paused_at');
            $table->timestamp('pause_requested_at', 3)->nullable()->after('pause_kind');
            $table->unsignedInteger('manual_paused_ms')->default(0)->after('total_paused_ms');
        });
    }

    public function down(): void
    {
        Schema::table('game', function (Blueprint $table) {
            $table->dropColumn(['pause_kind', 'pause_requested_at', 'manual_paused_ms']);
        });
    }
};
