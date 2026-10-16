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
        // 1. Rattachement des Reels à une Page Pro
        if (Schema::hasTable('reels') && ! Schema::hasColumn('reels', 'page_id')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
                $table->index('page_id');
            });
        }

        // 2. Cloisonnement de l'inbox professionnelle dans la messagerie
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'page_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('type')->constrained('pages')->cascadeOnDelete();
                $table->index(['page_id', 'type']);
            });
        }

        // 3. Campagnes de Boost au nom d'une Page Pro
        if (Schema::hasTable('campaigns') && ! Schema::hasColumn('campaigns', 'page_id')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
                $table->index('page_id');
            });
        }

        // 4. Vitrine immobilière pour les agences
        if (Schema::hasTable('listings') && ! Schema::hasColumn('listings', 'page_id')) {
            Schema::table('listings', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('agent_id')->constrained('pages')->nullOnDelete();
                $table->index('page_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('listings') && Schema::hasColumn('listings', 'page_id')) {
            Schema::table('listings', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'page_id')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'page_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('reels') && Schema::hasColumn('reels', 'page_id')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }
    }
};
