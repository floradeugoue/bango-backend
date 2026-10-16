<?php

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Legal\DataExportStatus;
use App\Jobs\Legal\GenerateDataExportJob;
use App\Models\Boost\Campaign;
use App\Models\Legal\DataExportRequest;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Legal\DataExportService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use ZipArchive;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create([
        'name' => 'Claire Export',
        'email' => 'claire.rgpd@bango.cm',
        'password' => bcrypt('Secret12345!'),
        'two_factor_secret' => 'super_secret_2fa_key',
        'backup_codes' => json_encode(['code1', 'code2']),
        'city' => 'Douala',
    ]);

    $this->otherUser = User::factory()->create(['name' => 'Autre Utilisateur']);
});

test('POST /api/user/data-export enregistre la demande et dispatche GenerateDataExportJob', function () {
    Queue::fake();
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/user/data-export');

    $response->assertStatus(202)
        ->assertJsonPath('export.status.value', DataExportStatus::Pending->value);

    $this->assertDatabaseHas('data_export_requests', [
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Pending->value,
    ]);

    Queue::assertPushed(GenerateDataExportJob::class, function ($job) {
        return $job->exportRequest->user_id === $this->user->id;
    });
});

test('POST /api/user/data-export empêche les demandes répétées si une demande est déjà en cours', function () {
    Passport::actingAs($this->user);

    DataExportRequest::create([
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Pending,
        'disk' => 'local',
    ]);

    $response = $this->postJson('/api/user/data-export');

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Une demande d\'export est déjà en cours de génération.');
});

test('GET /api/user/data-export/status retourne l\'état de la dernière demande', function () {
    Passport::actingAs($this->user);

    $req = DataExportRequest::create([
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Ready,
        'file_path' => 'exports/dummy.zip',
        'file_size_bytes' => 1024,
        'expires_at' => now()->addHours(24),
        'completed_at' => now(),
    ]);

    $response = $this->getJson('/api/user/data-export/status');

    $response->assertOk()
        ->assertJsonPath('export.id', $req->id)
        ->assertJsonPath('export.status.value', DataExportStatus::Ready->value)
        ->assertJsonPath('export.file_size_bytes', 1024)
        ->assertJsonStructure(['export' => ['download_url', 'expires_at']]);
});

test('DataExportService assemble l\'archive ZIP avec les données réelles et exclut formellement les secrets', function () {
    Storage::fake('local');

    // Générer du contenu associé à l'utilisateur
    $post = Post::factory()->create(['user_id' => $this->user->id, 'body' => 'Mon post personnel']);
    $reel = Reel::factory()->create(['user_id' => $this->user->id, 'caption' => 'Mon reel personnel']);
    $listing = Listing::factory()->create(['owner_id' => $this->user->id, 'created_by_id' => $this->user->id, 'title' => 'Mon annonce personnelle']);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Reach,
        'status' => CampaignStatus::Active,
        'budget_amount' => '10000.00',
    ]);

    $exportRequest = DataExportRequest::create([
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Pending,
        'disk' => 'local',
    ]);

    $service = app(DataExportService::class);
    $service->generateExport($exportRequest);

    $exportRequest->refresh();
    expect($exportRequest->status)->toBe(DataExportStatus::Ready)
        ->and($exportRequest->file_path)->not->toBeNull()
        ->and($exportRequest->file_size_bytes)->toBeGreaterThan(0);

    $zipPath = storage_path('app/'.$exportRequest->file_path);
    expect(file_exists($zipPath))->toBeTrue();

    // Inspecter le contenu du ZIP
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();

    // Vérifier les fichiers structurés présents
    expect($zip->locateName('profile.json'))->not->toBeFalse()
        ->and($zip->locateName('preferences_and_privacy.json'))->not->toBeFalse()
        ->and($zip->locateName('square_posts.json'))->not->toBeFalse()
        ->and($zip->locateName('reels.json'))->not->toBeFalse()
        ->and($zip->locateName('listings.json'))->not->toBeFalse()
        ->and($zip->locateName('boost_campaigns.json'))->not->toBeFalse();

    // Vérifier l'exclusion absolue des secrets dans profile.json
    $profileJson = $zip->getFromName('profile.json');
    $profileData = json_decode($profileJson, true);

    expect($profileData['name'])->toBe('Claire Export')
        ->and($profileData['email'])->toBe('claire.rgpd@bango.cm')
        ->and(isset($profileData['password']))->toBeFalse()
        ->and(isset($profileData['two_factor_secret']))->toBeFalse()
        ->and(isset($profileData['backup_codes']))->toBeFalse()
        ->and(str_contains($profileJson, 'Secret12345!'))->toBeFalse()
        ->and(str_contains($profileJson, 'super_secret_2fa_key'))->toBeFalse();

    $zip->close();

    // Nettoyage après test
    if (file_exists($zipPath)) {
        unlink($zipPath);
    }
});

test('GET /api/user/data-export/download/{id} refuse l\'accès aux archives d\'un tiers (protection IDOR 403)', function () {
    Passport::actingAs($this->reporter ?? User::factory()->create());

    $foreignExport = DataExportRequest::create([
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Ready,
        'file_path' => 'exports/dummy.zip',
        'expires_at' => now()->addHours(24),
    ]);

    $response = $this->getJson("/api/user/data-export/download/{$foreignExport->id}");
    $response->assertStatus(403);
});

test('GET /api/user/data-export/download/{id} renvoie 410 si l\'archive a expiré', function () {
    Passport::actingAs($this->user);

    $expiredExport = DataExportRequest::create([
        'user_id' => $this->user->id,
        'status' => DataExportStatus::Ready,
        'file_path' => 'exports/dummy.zip',
        'expires_at' => now()->subHour(), // Expiré
    ]);

    $response = $this->getJson("/api/user/data-export/download/{$expiredExport->id}");
    $response->assertStatus(410);
});
