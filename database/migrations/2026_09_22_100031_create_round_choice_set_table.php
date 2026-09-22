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
        Schema::create('round_choice_set', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('round')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('choice_1', 255);
            $table->string('choice_2', 255);
            $table->string('choice_3', 255);
            $table->string('choice_4', 255);
            $table->timestamp('composed_at', 3);
            $table->timestamps(3);

            $table->unique(['round_id', 'locale'], 'round_choice_set_round_locale_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_choice_set');
    }
};
