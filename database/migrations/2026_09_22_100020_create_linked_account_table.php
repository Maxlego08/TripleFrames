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
        Schema::create('linked_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('provider_email', 255)->nullable();
            $table->boolean('provider_email_verified')->default(false);
            $table->string('suggested_nickname', 50)->nullable();
            $table->text('provider_avatar_url')->nullable();
            $table->timestamps();

            $table->index('user_id', 'linked_account_user_idx');
            $table->unique(['provider', 'provider_user_id'], 'linked_account_provider_uid_uq');
            $table->unique(['user_id', 'provider'], 'linked_account_user_provider_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('linked_account');
    }
};
