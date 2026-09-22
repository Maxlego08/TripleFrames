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
        Schema::create('import_run', function (Blueprint $table) {
            $table->id();
            $table->string('run_kind', 12);
            $table->string('status', 12)->default('running');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('filter_min_vote_count')->nullable();
            $table->string('filter_languages', 64)->nullable();
            $table->smallInteger('filter_min_release_year')->nullable();
            $table->boolean('is_widened')->default(false);
            $table->unsignedInteger('tmdb_page_cursor')->nullable();
            $table->timestamp('last_request_at')->nullable();
            $table->unsignedInteger('total_seen')->default(0);
            $table->unsignedInteger('total_imported')->default(0);
            $table->unsignedInteger('total_skipped')->default(0);
            $table->unsignedInteger('total_refused_content')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('actor_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_run');
    }
};
