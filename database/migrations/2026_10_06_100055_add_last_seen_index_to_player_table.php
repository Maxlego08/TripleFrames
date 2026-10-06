<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pilote du périmètre `guest_nickname` (D62 du 06/10, spec 10 § 11.1) :
 * la purge anonymise le pseudo d'un siège 12 mois après `last_seen_at`, par
 * lots bornés du plus ancien au plus récent — `player_solo_expiry_idx
 * (room_id, last_seen_at)` ne sert que les sièges solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->index(['last_seen_at', 'id'], 'player_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropIndex('player_last_seen_idx');
        });
    }
};
