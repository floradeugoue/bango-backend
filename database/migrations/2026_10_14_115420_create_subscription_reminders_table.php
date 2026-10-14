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
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reminder_type', 64); // upcoming_7d, upcoming_3d, upcoming_1d, failed_payment_24h, failed_payment_72h, grace_period_notice, expired_notice
            $table->timestamp('target_date')->nullable();
            $table->string('channel', 32)->default('database'); // database, sms, email, push
            $table->string('status', 32)->default('sent'); // sent, failed
            $table->string('idempotency_key', 128)->unique();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->index(['subscription_id', 'reminder_type']);
            $table->index(['user_id', 'reminder_type']);
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
