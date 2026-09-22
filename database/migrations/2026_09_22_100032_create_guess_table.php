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
        Schema::create('guess', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('round')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('player')->restrictOnDelete();
            $table->timestamp('received_at', 3);
            $table->unsignedInteger('answered_at_ms');
            $table->unsignedTinyInteger('tier_index');
            $table->unsignedTinyInteger('lock_rank');
            $table->string('source', 10);
            $table->string('match_kind', 10);
            $table->foreignId('answer_key_id')->nullable()->constrained('answer_key')->nullOnDelete();
            $table->string('answer_key_normalized', 200);
            $table->string('submitted_normalized', 200);
            $table->unsignedTinyInteger('edit_distance');
            $table->boolean('prefix_was_ambiguous');
            $table->unsignedSmallInteger('points_tier');
            $table->unsignedSmallInteger('points_bonus');
            $table->unsignedSmallInteger('points_total');
            $table->timestamp('created_at', 3);

            $table->unique(['round_id', 'player_id'], 'guess_round_player_uq');
            $table->unique(['round_id', 'lock_rank'], 'guess_round_rank_uq');
            $table->index('player_id');
            $table->index('answer_key_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guess');
    }
};
