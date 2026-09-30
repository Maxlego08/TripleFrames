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
        Schema::create('round_player', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('round')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('player')->restrictOnDelete();
            $table->string('input_state', 20)->default('open');
            $table->timestamp('input_closed_at', 3)->nullable();
            $table->unsignedTinyInteger('wrong_attempts')->default(0);
            $table->string('choices_locale', 5)->nullable();
            $table->timestamp('choices_composed_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['round_id', 'player_id'], 'round_player_round_player_uq');
            $table->index('player_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_player');
    }
};
