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
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('admins_only')->default(false)->after('metadata');
            $table->boolean('is_read_only')->default(false)->after('admins_only');
            $table->string('invite_token', 64)->nullable()->unique()->after('is_read_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['admins_only', 'is_read_only', 'invite_token']);
        });
    }
};
