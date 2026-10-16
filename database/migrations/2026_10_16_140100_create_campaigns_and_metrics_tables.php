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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('boostable_type');
            $table->string('boostable_id');
            $table->string('objective')->default('reach'); // reach, profile_visits, messages, engagement
            $table->decimal('budget_amount', 12, 2);
            $table->string('budget_currency', 3)->default('XAF');
            $table->unsignedSmallInteger('duration_days');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->jsonb('targeting')->nullable();
            $table->string('status')->default('draft'); // draft, pending, active, paused, completed, cancelled
            $table->string('stopped_reason')->nullable(); // moderation, manual, reported, badge
            $table->string('payment_method')->nullable(); // credit, external, mixed
            $table->string('payment_reference')->nullable()->index();
            $table->decimal('spent_amount', 12, 2)->default(0.00);
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('reach_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['boostable_type', 'boostable_id']);
            $table->index('status');
            $table->index('start_at');
            $table->index('end_at');
        });

        Schema::create('campaign_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->decimal('spent', 10, 2)->default(0.00);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'metric_date']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_metrics');
        Schema::dropIfExists('campaigns');
    }
};
