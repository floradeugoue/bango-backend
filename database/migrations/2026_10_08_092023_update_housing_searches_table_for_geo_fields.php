<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housing_searches', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('housing_searches', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn(['currency_id', 'city_id']);
        });
    }
};
