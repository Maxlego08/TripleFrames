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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('player');
            $table->string('locale', 5)->default('en');
            $table->string('plan', 20)->default('free');
            $table->string('avatar_kind', 20)->nullable();
            $table->string('avatar_preset', 40)->nullable();
            $table->string('avatar_provider_path', 191)->nullable();
            $table->timestamp('avatar_provider_hidden_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('age_confirmed_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->index(['role', 'last_login_at'], 'users_role_last_login_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_last_login_at_index');

            $table->dropColumn([
                'last_login_at',
                'anonymized_at',
                'age_confirmed_at',
                'terms_version',
                'terms_accepted_at',
                'avatar_provider_hidden_at',
                'avatar_provider_path',
                'avatar_preset',
                'avatar_kind',
                'plan',
                'locale',
                'role',
            ]);
        });
    }
};
