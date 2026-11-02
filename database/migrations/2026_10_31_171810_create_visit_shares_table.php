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
        Schema::create('visit_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('agreed_percent', 5, 2)->unsigned()->nullable();
            $table->decimal('offer_percent', 5, 2)->unsigned();
            $table->string('offer_by', 16); // 'landlord' | 'agent'
            $table->timestamp('offer_at');
            $table->timestamps();

            $table->unique(['landlord_id', 'agent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_shares');
    }
};
