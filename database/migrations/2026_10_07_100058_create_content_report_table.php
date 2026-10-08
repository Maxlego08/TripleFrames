<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le signalement de contenu par les joueurs (D63 du 07/10, spec 10 § 8.1 bis) :
 * un film ou une image signalé depuis la révélation ou le podium, traité dans
 * la file curateur+ du back-office. Distinct de `report` (pseudo et avatar,
 * § 8.1) et de `takedown_request` (voie juridique, § 8.2).
 *
 * - `target_key` (`f:<frame_id>` ou `m:<movie_id>`) porte le dédoublonnage :
 *   un signaleur — compte, sinon siège — ne signale qu'une fois une cible ;
 * - aucun effet automatique : seul un geste curateur+ change l'état d'un
 *   film ou d'une image ;
 * - aucune adresse IP, aucun visiteur ;
 * - purgé 12 mois après `created_at` (périmètre `content_report`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_report', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')
                ->constrained('movie', indexName: 'content_report_movie_fk')->restrictOnDelete();
            $table->foreignId('frame_id')->nullable()
                ->constrained('frame', indexName: 'content_report_frame_fk')->restrictOnDelete();
            $table->string('target_key', 24);
            $table->string('reason', 20);
            $table->text('comment')->nullable();
            $table->foreignId('reporter_user_id')->nullable()
                ->constrained('users', indexName: 'content_report_reporter_user_fk')->nullOnDelete();
            $table->foreignId('reporter_player_id')->nullable()
                ->constrained('player', indexName: 'content_report_reporter_player_fk')->nullOnDelete();
            $table->string('status', 12)->default('open');
            $table->string('resolution', 20)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()
                ->constrained('users', indexName: 'content_report_resolved_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reporter_user_id', 'target_key'], 'content_report_user_target_uq');
            $table->unique(['reporter_player_id', 'target_key'], 'content_report_player_target_uq');
            $table->index(['status', 'created_at'], 'content_report_status_idx');
            $table->index('movie_id', 'content_report_movie_idx');
            $table->index('frame_id', 'content_report_frame_idx');
            $table->index(['target_key', 'status'], 'content_report_target_idx');
            $table->index(['created_at', 'id'], 'content_report_created_idx');
            $table->index('resolved_by_id', 'content_report_resolved_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_report');
    }
};
