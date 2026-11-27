<?php

namespace Tests\Feature\Reviewer;

use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Reel\Reel;
use App\Models\Reel\Series;
use App\Models\User;
use Database\Seeders\ReviewerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReviewerAndAssistantAndSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::firstOrCreate(
            ['code' => 'XAF'],
            ['name' => 'Franc CFA', 'symbol' => 'FCFA']
        );

        $cameroun = Country::create([
            'name' => 'Cameroun',
            'code' => 'CM',
            'iso2' => 'CM',
            'iso3' => 'CMR',
            'phone_code' => '237',
            'currency_id' => $currency->id,
            'is_active' => true,
        ]);
        $douala = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $cameroun->id, 'is_active' => true]);
        Neighbourhood::create(['name' => 'Bonapriso', 'slug' => 'bonapriso', 'city_id' => $douala->id, 'is_active' => true, 'status' => 'verified']);
        Role::firstOrCreate(['slug' => 'user'], ['name' => 'Utilisateur', 'is_active' => true, 'permissions' => []]);

        $this->user = User::factory()->create([
            'email' => 'regular@bangoapp.com',
            'birth_date' => '1996-03-14',
            'terms_accepted_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_reviewer_account_seeder_creates_valid_demo_account(): void
    {
        $seeder = new ReviewerAccountSeeder;
        $seeder->run();

        $reviewer = User::where('email', 'reviewer@bangoapp.com')->first();
        expect($reviewer)->not->toBeNull();
        expect($reviewer->birth_date)->not->toBeNull();
        expect($reviewer->terms_accepted_at)->not->toBeNull();
        expect($reviewer->onboarding_completed_at)->not->toBeNull();
        expect($reviewer->is_verified)->toBeTrue();

        // Vérifier les réels
        $reels = Reel::where('user_id', $reviewer->id)->get();
        expect($reels->count())->toBeGreaterThanOrEqual(2);

        // Vérifier la série
        $series = Series::where('slug', 'la-voisine')->first();
        expect($series)->not->toBeNull();
        expect($series->reels()->count())->toBeGreaterThanOrEqual(2);

        // Vérifier la conversation avec l'assistant
        $assistant = User::getAssistant();
        expect($assistant->is_system)->toBeTrue();
        expect($assistant->is_certified)->toBeTrue();
    }

    public function test_assistant_thread_endpoint_returns_synchronized_conversation(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/assistant/thread');
        $response->assertOk();
        $response->assertJsonPath('data.type', 'direct');

        $conversationId = $response->json('data.id');
        expect($conversationId)->not->toBeNull();

        // Envoi d'un message synchronisé dans la conversation avec l'assistant
        $msgRes = $this->postJson("/api/messages/threads/{$conversationId}/messages", [
            'body' => 'Bonjour Assistant, peux-tu m\'aider ?',
        ]);
        $msgRes->assertStatus(201);
        $msgRes->assertJsonPath('data.body', 'Bonjour Assistant, peux-tu m\'aider ?');

        // Récupération des messages
        $listRes = $this->getJson("/api/messages/threads/{$conversationId}/messages");
        $listRes->assertOk();
        $messages = $listRes->json('data');
        expect(count($messages))->toBeGreaterThanOrEqual(2);
    }

    public function test_series_api_endpoints_work(): void
    {
        $creator = User::factory()->create();
        $series = Series::create([
            'title' => 'Ma Super Série',
            'slug' => 'ma-super-serie',
            'description' => 'Une série de test captivante',
            'cover_url' => 'https://example.com/cover.jpg',
            'user_id' => $creator->id,
            'is_active' => true,
        ]);

        // Créer 2 épisodes ordonnés
        Reel::create([
            'user_id' => $creator->id,
            'series_id' => $series->id,
            'episode_number' => 1,
            'caption' => 'Épisode 1',
            'video_url' => 'https://example.com/ep1.mp4',
            'duration' => 30,
        ]);

        Reel::create([
            'user_id' => $creator->id,
            'series_id' => $series->id,
            'episode_number' => 2,
            'caption' => 'Épisode 2',
            'video_url' => 'https://example.com/ep2.mp4',
            'duration' => 40,
        ]);

        $series->refreshEpisodesCount();

        // GET /api/series
        $listRes = $this->getJson('/api/series');
        $listRes->assertOk();

        // GET /api/series/{slug}
        $showRes = $this->getJson('/api/series/ma-super-serie');
        $showRes->assertOk();
        $showRes->assertJsonPath('data.title', 'Ma Super Série');
        $showRes->assertJsonPath('data.slug', 'ma-super-serie');
        $showRes->assertJsonPath('data.episodes_count', 2);
        expect($showRes->json('data.episodes'))->toHaveCount(2);
        expect($showRes->json('data.episodes.0.episode_number'))->toBe(1);
        expect($showRes->json('data.episodes.1.episode_number'))->toBe(2);
    }

    public function test_cleanup_test_bo_interest_is_removed(): void
    {
        // Insérer une donnée de test simulant le test BO
        DB::table('interests')->insert([
            'id' => 'test-bo-interest',
            'name_fr' => 'Test BO',
            'name_en' => 'Test BO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('interests')->where('id', 'test-bo-interest')->exists())->toBeTrue();

        // Exécuter la migration de nettoyage
        $migration = require database_path('migrations/2026_11_27_150000_cleanup_test_bo_interest.php');
        $migration->up();

        expect(DB::table('interests')->where('id', 'test-bo-interest')->exists())->toBeFalse();
        expect(DB::table('interests')->where('name_fr', 'like', '%Test BO%')->exists())->toBeFalse();
    }
}
