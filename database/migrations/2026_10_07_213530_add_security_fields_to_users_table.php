<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method')->nullable();
            $table->string('two_factor_secret')->nullable();
            $table->jsonb('backup_codes')->nullable();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_code')->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();
            $table->integer('pending_email_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_method', 'two_factor_secret', 'backup_codes',
                'pending_email', 'pending_email_code', 'pending_email_expires_at', 'pending_email_attempts'
            ]);
        });
    }
};
