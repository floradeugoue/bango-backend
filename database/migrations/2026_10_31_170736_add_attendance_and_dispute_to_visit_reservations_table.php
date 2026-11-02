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
        Schema::table('visit_reservations', function (Blueprint $table) {
            $table->string('prospect_attendance')->nullable()->after('prospect_message');
            $table->dateTime('prospect_attendance_at')->nullable()->after('prospect_attendance');
            $table->string('landlord_attendance')->nullable()->after('prospect_attendance_at');
            $table->dateTime('landlord_attendance_at')->nullable()->after('landlord_attendance');
            $table->dateTime('disputed_at')->nullable()->after('landlord_attendance_at');
            $table->text('dispute_reason')->nullable()->after('disputed_at');

            $table->index(['status', 'disputed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_reservations', function (Blueprint $table) {
            $table->dropIndex(['status', 'disputed_at']);
            $table->dropColumn([
                'prospect_attendance',
                'prospect_attendance_at',
                'landlord_attendance',
                'landlord_attendance_at',
                'disputed_at',
                'dispute_reason',
            ]);
        });
    }
};
