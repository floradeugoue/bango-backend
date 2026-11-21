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
            $table->timestamp('neighbourhood_verified_at')->nullable()->after('neighbourhood_id');
            $table->index(['neighbourhood_verified_at'], 'users_neighbourhood_verified_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_neighbourhood_verified_at_idx');
            $table->dropColumn('neighbourhood_verified_at');
        });
    }
};
