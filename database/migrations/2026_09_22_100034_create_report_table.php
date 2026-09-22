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
        Schema::create('report', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16);
            $table->foreignId('reporter_player_id')->constrained('player')->cascadeOnDelete();
            $table->foreignId('target_player_id')->nullable()->constrained('player')->cascadeOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->index('reporter_player_id');
            $table->unique(['reporter_player_id', 'target_player_id'], 'report_reporter_player_uq');
            $table->unique(['reporter_player_id', 'target_user_id'], 'report_reporter_user_uq');
            $table->index('target_user_id', 'report_target_user_idx');
            $table->index('target_player_id', 'report_target_player_idx');
            $table->index('created_at', 'report_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report');
    }
};
