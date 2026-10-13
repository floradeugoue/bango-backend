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
            $table->boolean('is_private')->default(false);
            $table->json('privacy_settings')->nullable();
            $table->string('badge')->default('none');
            $table->boolean('verification_lapsed')->default(false);
            $table->timestamp('restricted_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_private',
                'privacy_settings',
                'badge',
                'verification_lapsed',
                'restricted_until',
            ]);
        });
    }
};
