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
        Schema::table('copyright_claims', function (Blueprint $table) {
            $table->string('claim_kind')->default('media')->after('claimant_email');
            $table->string('country', 10)->nullable()->after('claim_kind');

            $table->index('claim_kind', 'copyright_claims_claim_kind_idx');
            $table->index('country', 'copyright_claims_country_idx');
            $table->index(['status', 'resolved_at'], 'copyright_claims_status_resolved_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copyright_claims', function (Blueprint $table) {
            $table->dropIndex('copyright_claims_claim_kind_idx');
            $table->dropIndex('copyright_claims_country_idx');
            $table->dropIndex('copyright_claims_status_resolved_idx');
            $table->dropColumn(['claim_kind', 'country']);
        });
    }
};
