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
        Schema::create('frame_review', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frame_id')->constrained('frame')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name', 255);
            $table->string('reviewer_role', 10);
            $table->unsignedTinyInteger('grid_version');
            $table->string('decision', 10);
            $table->char('reviewed_hash', 64);
            $table->string('declared_source_kind', 8);
            $table->string('declared_source_reference', 255)->nullable();
            $table->json('answers');
            $table->timestamp('reviewed_at');

            $table->index(['frame_id', 'id'], 'frame_review_frame_idx');
            $table->index(['grid_version', 'decision'], 'frame_review_version_idx');
            $table->index('reviewer_id', 'frame_review_reviewer_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frame_review');
    }
};
