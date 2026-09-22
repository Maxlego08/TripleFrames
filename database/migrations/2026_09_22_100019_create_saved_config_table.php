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
        Schema::create('saved_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('name_normalized', 40);
            $table->json('settings');
            $table->unsignedSmallInteger('settings_version');
            $table->char('default_slot', 1)->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['user_id', 'name_normalized'], 'saved_config_user_name_uq');
            $table->unique(['user_id', 'default_slot'], 'saved_config_user_default_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_config');
    }
};
