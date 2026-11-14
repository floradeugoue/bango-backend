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
        if (Schema::hasTable('pricing_change_proposals')) {
            Schema::table('pricing_change_proposals', function (Blueprint $table) {
                if (! Schema::hasColumn('pricing_change_proposals', 'legal_document_id')) {
                    $table->foreignId('legal_document_id')->nullable()->constrained('legal_documents')->nullOnDelete()->after('subscription_price_id');
                }
                if (! Schema::hasColumn('pricing_change_proposals', 'terms_version')) {
                    $table->string('terms_version', 50)->nullable()->after('legal_document_id');
                }
                if (! Schema::hasColumn('pricing_change_proposals', 'applied_at')) {
                    $table->timestamp('applied_at')->nullable()->after('status');
                }
            });
        }

        if (Schema::hasTable('subscription_prices')) {
            Schema::table('subscription_prices', function (Blueprint $table) {
                if (! Schema::hasColumn('subscription_prices', 'legal_document_id')) {
                    $table->foreignId('legal_document_id')->nullable()->constrained('legal_documents')->nullOnDelete()->after('product_id');
                }
                if (! Schema::hasColumn('subscription_prices', 'required_terms_version')) {
                    $table->string('required_terms_version', 50)->nullable()->after('legal_document_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('subscription_prices')) {
            Schema::table('subscription_prices', function (Blueprint $table) {
                if (Schema::hasColumn('subscription_prices', 'legal_document_id')) {
                    $table->dropConstrainedForeignId('legal_document_id');
                }
                if (Schema::hasColumn('subscription_prices', 'required_terms_version')) {
                    $table->dropColumn('required_terms_version');
                }
            });
        }

        if (Schema::hasTable('pricing_change_proposals')) {
            Schema::table('pricing_change_proposals', function (Blueprint $table) {
                if (Schema::hasColumn('pricing_change_proposals', 'legal_document_id')) {
                    $table->dropConstrainedForeignId('legal_document_id');
                }
                if (Schema::hasColumn('pricing_change_proposals', 'terms_version')) {
                    $table->dropColumn('terms_version');
                }
                if (Schema::hasColumn('pricing_change_proposals', 'applied_at')) {
                    $table->dropColumn('applied_at');
                }
            });
        }
    }
};
