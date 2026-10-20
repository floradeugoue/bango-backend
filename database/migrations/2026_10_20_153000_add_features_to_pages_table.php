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
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('status')->index();
            $table->unsignedInteger('followers_count')->default(0)->after('is_hidden');
            $table->foreignId('inbox_assigned_to_id')->nullable()->after('followers_count')->constrained('users')->nullOnDelete();
        });

        Schema::create('page_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
            $table->index(['page_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_followers');

        Schema::table('pages', function (Blueprint $table) {
            $table->dropForeign(['inbox_assigned_to_id']);
            $table->dropColumn(['is_hidden', 'followers_count', 'inbox_assigned_to_id']);
        });
    }
};
