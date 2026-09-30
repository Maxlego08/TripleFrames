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
        Schema::create('seen_frame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('room')->cascadeOnDelete();
            $table->foreignId('frame_id')->constrained('frame')->cascadeOnDelete();
            $table->timestamp('last_seen_at');

            $table->unique(['room_id', 'frame_id'], 'seen_frame_room_frame_uq');
            $table->index('frame_id', 'seen_frame_frame_idx');
            $table->index('last_seen_at', 'seen_frame_last_seen_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seen_frame');
    }
};
