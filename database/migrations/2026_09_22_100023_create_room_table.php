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
        Schema::create('room', function (Blueprint $table) {
            $table->id();
            $table->char('room_code', 6);
            $table->char('room_code_active', 6)->nullable();
            $table->string('status', 20)->default('lobby');
            $table->unsignedBigInteger('host_player_id')->nullable();
            $table->unsignedTinyInteger('capacity');
            $table->unsignedTinyInteger('frames_per_round');
            $table->unsignedTinyInteger('rounds_count');
            $table->string('input_difficulty', 10);
            $table->boolean('allow_late_join');
            $table->json('settings');
            $table->unsignedSmallInteger('settings_version');
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('room_code_active', 'room_active_code_uq');
            $table->index('room_code', 'room_code_idx');
            $table->index(['archived_at', 'last_activity_at'], 'room_archived_activity_idx');
            $table->index('host_player_id', 'room_host_player_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room');
    }
};
