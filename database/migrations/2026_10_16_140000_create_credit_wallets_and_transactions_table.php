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
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0); // Nombre entier de crédits (1 FCFA = 1 Crédit BANGO)
            $table->string('currency', 3)->default('XAF');
            $table->timestamps();

            $table->index(['user_id', 'currency']);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('credit_wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 50); // purchase, debit
            $table->unsignedBigInteger('amount'); // Nombre entier strictement positif de crédits
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('source', 50)->nullable()->index(); // pay, boost, visit
            $table->string('reference', 100)->nullable()->index(); // Référence unique de l'opération source
            $table->foreignId('payment_transaction_id')->nullable()->unique()->constrained('payment_transactions')->nullOnDelete();
            $table->string('reference_type')->nullable(); // Polymorphe éventuel (Campaign::class, VisitReservation::class)
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['source', 'reference']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_wallets');
    }
};
