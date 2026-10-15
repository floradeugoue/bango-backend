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
        Schema::table('sounds', function (Blueprint $table) {
            $table->string('provenance')->nullable()->after('duration_seconds');
            $table->string('license_type')->nullable()->after('provenance');
            $table->string('license_name')->nullable()->after('license_type');
            $table->string('license_url')->nullable()->after('license_name');
            $table->text('license_terms')->nullable()->after('license_url');
            $table->text('usage_restrictions')->nullable()->after('license_terms');
            $table->string('rights_status')->default('verified')->after('usage_restrictions');
            $table->boolean('is_published')->default(true)->after('rights_status');
            $table->timestamp('published_at')->nullable()->after('is_published');

            $table->index(['is_published', 'rights_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'rights_status']);
            $table->dropColumn([
                'provenance',
                'license_type',
                'license_name',
                'license_url',
                'license_terms',
                'usage_restrictions',
                'rights_status',
                'is_published',
                'published_at',
            ]);
        });
    }
};
