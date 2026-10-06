<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le visiteur consentant (D62 du 06/10, spec 10 § 7.1 bis) : un identifiant
 * déposé dans le navigateur **seulement après consentement**, qui relie les
 * sièges successifs d'un même navigateur pour l'analyse des parties.
 *
 * - `visitor` : l'empreinte SHA-256 du jeton du cookie (jamais le jeton),
 *   la preuve du consentement (version, instant) et l'activité. Supprimé au
 *   retrait du consentement, ou 13 mois après sa dernière activité
 *   (périmètre `visitor`).
 * - `player` : le visiteur du siège et l'appareil grossier (classe, famille
 *   de navigateur et de système), écrits seulement pour un visiteur
 *   consentant, anonymisés avec le pseudo à 12 mois (`guest_nickname`).
 *
 * Aucune adresse IP, aucune empreinte d'appareil : le type d'appareil est lu
 * dans l'en-tête `User-Agent` et réduit à trois familles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64);
            $table->string('consent_version', 20);
            $table->timestamp('consented_at', 3);
            $table->timestamp('first_seen_at', 3);
            $table->timestamp('last_seen_at', 3);
            $table->timestamps(3);

            $table->unique('token_hash', 'visitor_token_uq');
            $table->index(['last_seen_at', 'id'], 'visitor_last_seen_idx');
        });

        Schema::table('player', function (Blueprint $table) {
            $table->foreignId('visitor_id')->nullable()->after('user_id')
                ->constrained('visitor', indexName: 'player_visitor_fk')->nullOnDelete();
            $table->string('device_class', 10)->nullable()->after('visitor_id');
            $table->string('browser_family', 20)->nullable()->after('device_class');
            $table->string('os_family', 20)->nullable()->after('browser_family');

            $table->index('visitor_id', 'player_visitor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropForeign('player_visitor_fk');
            $table->dropIndex('player_visitor_idx');
            $table->dropColumn(['visitor_id', 'device_class', 'browser_family', 'os_family']);
        });

        Schema::dropIfExists('visitor');
    }
};
