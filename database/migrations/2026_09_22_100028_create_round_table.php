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
        Schema::create('round', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('game')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('room')->restrictOnDelete();
            $table->unsignedTinyInteger('sequence_index');
            $table->unsignedTinyInteger('round_number')->nullable();
            $table->foreignId('movie_id')->constrained('movie')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('ended_at', 3)->nullable();
            $table->timestamp('reveal_ends_at', 3)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedTinyInteger('found_count')->default(0);
            $table->foreignId('decoy_movie_id_1')->nullable()->constrained('movie')->restrictOnDelete();
            $table->foreignId('decoy_movie_id_2')->nullable()->constrained('movie')->restrictOnDelete();
            $table->foreignId('decoy_movie_id_3')->nullable()->constrained('movie')->restrictOnDelete();
            $table->boolean('choices_use_original_title')->default(false);
            $table->string('cancel_reason', 30)->nullable();
            $table->timestamp('cancelled_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['game_id', 'sequence_index'], 'round_game_sequence_uq');
            $table->index(['game_id', 'status'], 'round_game_status_idx');
            $table->index(['room_id', 'started_at', 'movie_id'], 'round_room_started_movie_idx');
            $table->index(['movie_id', 'found_count'], 'round_movie_found_idx');
            $table->index('decoy_movie_id_1');
            $table->index('decoy_movie_id_2');
            $table->index('decoy_movie_id_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round');
    }
};
