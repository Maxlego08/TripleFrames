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
        Schema::create('theme_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('theme')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label', 80);
            $table->timestamps();

            $table->index('theme_id');
            $table->unique(['theme_id', 'locale'], 'theme_label_theme_locale_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_label');
    }
};
