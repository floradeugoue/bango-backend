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
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('slug');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('slug');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });

        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
