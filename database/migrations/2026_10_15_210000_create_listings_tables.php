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
        Schema::create('listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('submission_key', 128)->nullable()->index();
            $table->string('title', 100)->nullable();
            $table->text('description')->nullable();

            $table->string('mode', 32)->default('rent'); // rent, sale, flatshare
            $table->string('kind', 32)->default('flat'); // studio, flat, villa, room, office, land
            $table->string('status', 32)->default('draft'); // draft, review, published, reserved, taken, rejected

            // Informations financières
            $table->decimal('price', 14, 2)->unsigned()->default(0);
            $table->string('currency', 10)->default('XOF');
            $table->string('period', 16)->nullable()->default('month'); // 'month' ou null pour vente
            $table->unsignedSmallInteger('deposit_months')->default(0);
            $table->unsignedSmallInteger('advance_months')->default(0);
            $table->decimal('visit_fee', 14, 2)->unsigned()->default(0);
            $table->decimal('charges', 14, 2)->unsigned()->default(0);
            $table->boolean('negotiable')->default(false);
            $table->boolean('utilities_included')->default(false);

            // Caractéristiques physiques
            $table->unsignedSmallInteger('bedrooms')->default(0);
            $table->unsignedSmallInteger('showers')->default(0);
            $table->decimal('area', 10, 2)->unsigned()->default(0); // Surface en m²
            $table->json('amenities')->nullable(); // Liste d'équipements ex: ["furnished", "parking", "meter"]
            $table->json('features')->nullable(); // Équipements structurés
            $table->json('attributes')->nullable(); // Attributs courts ex: ["1 chambre", "1 douche", "Meublé"]
            $table->boolean('agent_visited')->default(false);
            $table->boolean('sponsored')->default(false);

            // Localisation
            $table->string('city', 100)->nullable();
            $table->string('neighbourhood', 100)->nullable();
            $table->text('location')->nullable(); // Repère public indicatif ex: "Almadies · près du carrefour Ngor"
            $table->text('address')->nullable(); // Adresse précise confidentielle
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Métriques et compteurs
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->unsignedInteger('pending_visits_count')->default(0);

            // Cycle de vie & modération
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Données de brouillon
            $table->string('draft_step', 32)->nullable()->default('video');
            $table->json('draft_data')->nullable();
            $table->json('video_metrics')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index pour les requêtes fréquentes
            $table->index(['status', 'published_at']);
            $table->index(['owner_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['city', 'status']);
            $table->index(['city', 'neighbourhood', 'status']);
            $table->index(['mode', 'status']);
            $table->index(['price', 'status']);
            $table->index(['lat', 'lng']);
        });

        Schema::create('listing_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();

            $table->string('type', 16)->default('image'); // image, video
            $table->text('path');
            $table->text('url');
            $table->text('thumbnail_path')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); // en secondes pour vidéos
            $table->unsignedInteger('order')->default(0);
            $table->string('room', 32)->nullable(); // living, bedroom, kitchen, shower, outside
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'type', 'order']);
            $table->index(['listing_id', 'order']);
        });

        Schema::create('listing_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['listing_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_saves');
        Schema::dropIfExists('listing_media');
        Schema::dropIfExists('listings');
    }
};
