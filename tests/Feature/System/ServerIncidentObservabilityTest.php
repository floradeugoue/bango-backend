<?php

use App\Enums\Support\TicketCategory;
use App\Models\User;
use App\Services\System\IncidentTrackerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // Enregistrement d'une route API de test simulant une panne serveur 500 inattendue
    Route::get('/api/test-trigger-500', function () {
        throw new RuntimeException('Panne de connexion inattendue au cluster.');
    });

    Route::post('/api/test-trigger-500-post', function () {
        throw new RuntimeException('Échec d\'écriture base de données inattendu.');
    });
});

it('generates an incident_id and standard json payload when an unexpected 500 occurs', function () {
    config(['app.debug' => false]);

    $response = getJson('/api/test-trigger-500');

    $response->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_SERVER_ERROR')
        ->assertJsonStructure([
            'message',
            'code',
            'incident_id',
        ]);

    $incidentId = $response->json('incident_id');
    expect($incidentId)->toMatch('/^ERR-BANGO-\d{8}-[A-Z0-9]{6}$/');
    expect($response->headers->get('X-Incident-Id'))->toBe($incidentId);

    // En mode production, aucune trace de pile ou debug n'est retournée
    expect($response->json('debug'))->toBeNull();
});

it('logs the incident with structured context and the exact incident_id', function () {
    config(['app.debug' => false]);
    Log::spy();

    $response = getJson('/api/test-trigger-500');
    $response->assertStatus(500);

    $incidentId = $response->json('incident_id');

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context) use ($incidentId) {
            return str_contains($message, "[INCIDENT:{$incidentId}]")
                && isset($context['incident_id'])
                && $context['incident_id'] === $incidentId
                && isset($context['status_code'])
                && $context['status_code'] === 500;
        });
});

it('assigns unique incident identifiers to distinct incidents', function () {
    config(['app.debug' => false]);

    $res1 = getJson('/api/test-trigger-500');
    $res2 = getJson('/api/test-trigger-500');

    $id1 = $res1->json('incident_id');
    $id2 = $res2->json('incident_id');

    expect($id1)->not->toBe($id2);
});

it('sanitizes sensitive data and headers in incident logs', function () {
    $service = new IncidentTrackerService;
    $request = Request::create('/api/test', 'POST', [
        'username' => 'client_test',
        'password' => 'secret_mot_de_passe_123',
        'momo_pin' => '1234',
        'email' => 'client@bango.africa',
    ]);
    $request->headers->set('Authorization', 'Bearer token_secret_xyz');
    $request->headers->set('X-Custom-Secret', 'super_secret');

    $context = $service->logIncident(new RuntimeException('Erreur test'), $request);

    expect($context['sanitized_inputs']['password'])->toContain('[MASQUÉ]');
    expect($context['sanitized_inputs']['momo_pin'])->toContain('[MASQUÉ]');
    expect($context['sanitized_inputs']['username'])->toBe('client_test');

    expect($context['sanitized_headers']['authorization'][0])->toContain('[MASQUÉ]');
});

it('preserves 4xx client errors without converting them to 500 incidents', function () {
    // 401 Unauthorized
    getJson('/api/payments/methods')->assertStatus(401);

    // 404 Not Found
    getJson('/api/non-existent-endpoint-404')->assertStatus(404);

    // 422 Validation Error
    $user = User::factory()->create();
    Passport::actingAs($user);

    $validationResponse = postJson('/api/payments/methods', []);
    $validationResponse->assertStatus(422)
        ->assertJsonValidationErrors(['operator', 'phone_number']);

    // La réponse 422 ne doit pas contenir de code INTERNAL_SERVER_ERROR
    expect($validationResponse->json('code'))->not->toBe('INTERNAL_SERVER_ERROR');
});

it('allows customer support tickets to attach the incident_id for investigation', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = postJson('/api/support/tickets', [
        'subject' => 'Erreur inattendue lors de la publication',
        'category' => TicketCategory::PublishingContent->value,
        'message' => 'L\'écran a affiché un incident lors de ma tentative.',
        'incident_id' => 'ERR-BANGO-20261016-ABC123',
    ]);

    $response->assertStatus(201);

    // Vérifier que le premier message du ticket contient la référence d'incident
    $ticketId = $response->json('data.id');
    $detail = getJson("/api/support/tickets/{$ticketId}");

    $detail->assertStatus(200);
    $firstMessage = $detail->json('data.messages.0.message');
    expect($firstMessage)->toContain('ERR-BANGO-20261016-ABC123');
});
