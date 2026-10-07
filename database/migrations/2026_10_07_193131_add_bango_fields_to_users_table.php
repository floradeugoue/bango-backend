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
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle')->unique()->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->boolean('gender_hidden')->default(false);
            $table->string('phone')->unique()->nullable();
            $table->string('country_code')->nullable();
            $table->string('city')->nullable();
            $table->string('neighbourhood')->nullable();
            $table->string('locale')->default('fr');
            $table->string('account_type')->default('personal');
            $table->boolean('is_verified')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('locked_until')->nullable();
            $table->jsonb('onboarding_progress')->nullable();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'handle', 'display_name', 'avatar_url', 'birth_date',
                'gender', 'gender_hidden', 'phone', 'country_code',
                'city', 'neighbourhood', 'locale', 'account_type',
                'is_verified', 'status', 'locked_until', 'onboarding_progress'
            ]);
            $table->dropSoftDeletes();
        });
    }
};
