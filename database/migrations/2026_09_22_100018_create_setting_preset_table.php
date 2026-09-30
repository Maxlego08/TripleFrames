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
        Schema::create('setting_preset', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30);
            $table->unsignedTinyInteger('position');
            $table->json('settings');
            $table->unsignedSmallInteger('settings_version');
            $table->timestamps();

            $table->unique('key', 'setting_preset_key_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setting_preset');
    }
};
