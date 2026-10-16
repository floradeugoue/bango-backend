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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->index();
            $table->string('version', 32);
            $table->string('locale', 10)->default('fr')->index();
            $table->string('title');
            $table->longText('content_markdown');
            $table->timestamp('effective_date');
            $table->boolean('is_published')->default(true)->index();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'version', 'locale'], 'legal_documents_slug_version_locale_unique');
            $table->index(['slug', 'locale', 'is_published', 'effective_date'], 'legal_documents_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
