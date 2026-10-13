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
            $table->text('bio')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('suspended_from')->nullable();
            $table->timestamp('suspended_until')->nullable();
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'suspension_reason', 'suspended_from', 'suspended_until']);
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
