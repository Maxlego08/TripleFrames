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
        Schema::create('answer_key', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('movie')->cascadeOnDelete();
            $table->string('key_kind', 16);
            $table->string('source_locale', 12)->nullable();
            $table->string('normalized', 200);
            $table->boolean('is_ambiguous')->default(false);
            $table->timestamps();

            $table->unique(['normalized', 'movie_id'], 'answer_key_norm_movie_uq');
            $table->index('movie_id', 'answer_key_movie_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('answer_key');
    }
};
