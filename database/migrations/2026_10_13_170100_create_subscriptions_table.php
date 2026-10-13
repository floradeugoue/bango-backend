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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('subscription_products')->restrictOnDelete();
            $table->foreignId('price_id')->constrained('subscription_prices')->restrictOnDelete();
            $table->string('badge_type'); // landlord, agent, page
            $table->string('cadence'); // monthly, yearly, one_off
            $table->string('state')->default('active'); // pending_payment, active, grace, failed, expired, canceled, suspended
            $table->decimal('amount', 12, 2);
            $table->string('currency', 4)->default('XAF');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('grace_until')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('next_renewal')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('cancel_feedback')->nullable();
            $table->string('payment_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'badge_type']);
            $table->index(['user_id', 'state']);
            $table->index(['state', 'current_period_end']);
            $table->index(['state', 'grace_until']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type'); // created, activated, renewed, renewal_failed, grace_entered, plan_changed, canceled, expired, suspended, reactivated, extended, revoked
            $table->string('old_state')->nullable();
            $table->string('new_state')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->default('system'); // user, admin, system, pay
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'created_at']);
            $table->index(['user_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
    }
};
