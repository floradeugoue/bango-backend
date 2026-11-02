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
        // 1. Support tickets créés au nom d'une Page Pro
        if (Schema::hasTable('support_tickets') && ! Schema::hasColumn('support_tickets', 'page_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['page_id', 'status']);
            });
        }

        // 2. Likes sur annonces immobilières au nom d'une Page Pro
        if (Schema::hasTable('listing_likes') && ! Schema::hasColumn('listing_likes', 'page_id')) {
            Schema::table('listing_likes', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['listing_id', 'page_id']);
            });
        }

        // 3. Sauvegardes / favoris d'annonces immobilières au nom d'une Page Pro
        if (Schema::hasTable('listing_saves') && ! Schema::hasColumn('listing_saves', 'page_id')) {
            Schema::table('listing_saves', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['listing_id', 'page_id']);
                $table->index(['page_id', 'listing_id']);
            });
        }

        // 4. Commentaires sur annonces immobilières au nom d'une Page Pro
        if (Schema::hasTable('listing_comments') && ! Schema::hasColumn('listing_comments', 'page_id')) {
            Schema::table('listing_comments', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['listing_id', 'page_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('listing_comments') && Schema::hasColumn('listing_comments', 'page_id')) {
            Schema::table('listing_comments', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('listing_saves') && Schema::hasColumn('listing_saves', 'page_id')) {
            Schema::table('listing_saves', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('listing_likes') && Schema::hasColumn('listing_likes', 'page_id')) {
            Schema::table('listing_likes', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('support_tickets') && Schema::hasColumn('support_tickets', 'page_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }
    }
};
