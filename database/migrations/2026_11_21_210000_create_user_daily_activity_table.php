<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_daily_activity')) {
            Schema::create('user_daily_activity', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('date');
                $table->timestamps();

                $table->unique(['user_id', 'date']);
                $table->index(['date']);
            });

            // Rétro-remplissage depuis last_seen_at existants pour conserver l'historique disponible
            if (Schema::hasColumn('users', 'last_seen_at')) {
                DB::table('users')
                    ->whereNotNull('last_seen_at')
                    ->select('id', 'last_seen_at')
                    ->chunkById(100, function ($users) {
                        $records = [];
                        $now = now();
                        foreach ($users as $user) {
                            $records[] = [
                                'user_id' => $user->id,
                                'date' => substr((string) $user->last_seen_at, 0, 10),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                        if (! empty($records)) {
                            DB::table('user_daily_activity')->insertOrIgnore($records);
                        }
                    });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_daily_activity');
    }
};
