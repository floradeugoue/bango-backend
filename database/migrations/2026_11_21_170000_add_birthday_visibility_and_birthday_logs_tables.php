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
        if (! Schema::hasColumn('users', 'birthday_visibility')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('birthday_visibility', 20)->default('none')->after('birth_date');
            });
        }

        if (! Schema::hasTable('birthday_notification_logs')) {
            Schema::create('birthday_notification_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->string('type', 20); // 'self' or 'friend'
                $table->timestamps();

                $table->unique(['user_id', 'recipient_id', 'year']);
                $table->index(['user_id', 'year']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('birthday_notification_logs');

        if (Schema::hasColumn('users', 'birthday_visibility')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('birthday_visibility');
            });
        }
    }
};
