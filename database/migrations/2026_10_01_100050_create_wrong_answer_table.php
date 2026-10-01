<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les réponses fausses, une ligne par refus compté — spec 10 § 7.6 bis
 * (D46 du 01/10). Migration additive : aucune table de la règle 12 touchée.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wrong_answer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('round')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('player')->restrictOnDelete();
            $table->string('source', 10);
            $table->string('submitted_text', 255);
            $table->string('submitted_normalized', 255);
            $table->unsignedTinyInteger('attempt_number')->nullable();
            $table->timestamp('received_at', 3);
            $table->unsignedInteger('answered_at_ms');
            $table->timestamp('created_at', 3);

            $table->index(['round_id', 'player_id'], 'wrong_answer_round_player_idx');
            $table->index('player_id', 'wrong_answer_player_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wrong_answer');
    }
};
