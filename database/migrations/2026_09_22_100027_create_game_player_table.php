<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_player', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('game')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('player')->restrictOnDelete();
            $table->string('display_nickname', 20)->nullable();
            $table->string('display_avatar_kind', 20)->nullable();
            $table->string('display_avatar_preset', 40)->nullable();
            $table->unsignedTinyInteger('first_round_number')->nullable();
            $table->string('status', 20)->default('playing');
            $table->unsignedTinyInteger('rounds_played')->nullable();
            $table->unsignedTinyInteger('correct_answers')->nullable();
            $table->integer('final_score')->nullable();
            $table->unsignedInteger('total_answer_time_ms')->nullable();
            // Deux octets et non un (E10-04) : `game_player` n'est pas borné
            // (retardataires en rotation, partis et expulsés restent classés),
            // et un 256e rang lèverait l'erreur 1264 au gel.
            $table->unsignedSmallInteger('final_rank')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'game_id'], 'game_player_player_game_uq');
            $table->index('game_id', 'game_player_game_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_player');
    }
};
