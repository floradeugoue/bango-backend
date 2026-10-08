<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('neighbourhood_id')->nullable()->constrained('neighbourhoods')->nullOnDelete();
        });

        // Migration des données texte vers les IDs
        DB::statement('
            UPDATE users 
            SET country_id = (SELECT id FROM countries c WHERE c.iso2 = users.country_code OR c.code = users.country_code LIMIT 1)
            WHERE users.country_code IS NOT NULL
        ');
        
        DB::statement('
            UPDATE users 
            SET city_id = (SELECT id FROM cities c WHERE c.name = users.city LIMIT 1)
            WHERE users.city IS NOT NULL
        ');

        DB::statement('
            UPDATE users 
            SET neighbourhood_id = (SELECT id FROM neighbourhoods n WHERE n.name = users.neighbourhood LIMIT 1)
            WHERE users.neighbourhood IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['neighbourhood_id']);
            $table->dropColumn(['country_id', 'city_id', 'neighbourhood_id']);
        });
    }
};
