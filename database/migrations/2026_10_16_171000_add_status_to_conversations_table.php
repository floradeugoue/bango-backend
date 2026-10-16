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
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'status')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->string('status', 32)->default('active')->index()->after('type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'status')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
