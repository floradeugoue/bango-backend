<?php

use App\Http\Middleware\ValidateUploadLimits;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
});

test('un fichier depassant upload_max_filesize (UPLOAD_ERR_INI_SIZE) renvoie un 413 clair avant le plantage Passport 500', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $listing = Listing::factory()->create(['owner_id' => $user->id]);

    Passport::actingAs($user);

    // Simulation exacte de l'incident ERR-BANGO-20261115-5PECRD :
    // PHP refuse le fichier car il dépasse upload_max_filesize : path vide et code UPLOAD_ERR_INI_SIZE
    $failedUpload = new UploadedFile(
        path: '',
        originalName: 'visite_video_4mo.mp4',
        mimeType: 'video/mp4',
        error: UPLOAD_ERR_INI_SIZE,
        test: true
    );

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $failedUpload,
        'type' => 'video',
    ]);

    // Doit retourner 413 (Payload Too Large) et NON un crash 500
    $response->assertStatus(413)
        ->assertJsonPath('error', 'file_too_large')
        ->assertJsonPath('code', 'UPLOAD_ERR_INI_SIZE')
        ->assertJsonStructure([
            'message',
            'error',
            'code',
            'errors' => ['file'],
        ]);
});

test('un fichier avec code UPLOAD_ERR_FORM_SIZE renvoie un 413 clair', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $listing = Listing::factory()->create(['owner_id' => $user->id]);

    Passport::actingAs($user);

    $failedUpload = new UploadedFile(
        path: '',
        originalName: 'photo.jpg',
        mimeType: 'image/jpeg',
        error: UPLOAD_ERR_FORM_SIZE,
        test: true
    );

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $failedUpload,
        'type' => 'image',
    ]);

    $response->assertStatus(413)
        ->assertJsonPath('error', 'file_too_large')
        ->assertJsonPath('code', 'UPLOAD_ERR_FORM_SIZE');
});

test('un fichier avec code UPLOAD_ERR_PARTIAL renvoie un 422 clair', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $listing = Listing::factory()->create(['owner_id' => $user->id]);

    Passport::actingAs($user);

    $partialUpload = new UploadedFile(
        path: '',
        originalName: 'video_part.mp4',
        mimeType: 'video/mp4',
        error: UPLOAD_ERR_PARTIAL,
        test: true
    );

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $partialUpload,
        'type' => 'video',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'upload_partial')
        ->assertJsonPath('code', 'UPLOAD_ERR_PARTIAL');
});

test('une requete dont le Content-Length depasse post_max_size renvoie un 413 avant traitement', function () {
    $middleware = new ValidateUploadLimits;

    // Créer une requête simulant un CONTENT_LENGTH de 500M alors que post_max_size est inférieur
    $request = Request::create('/api/listings/123/media', 'POST', [], [], [], [
        'CONTENT_LENGTH' => 600 * 1024 * 1024, // 600 Mo
    ]);

    $response = $middleware->handle($request, function () {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(413);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('post_too_large')
        ->and($data['code'])->toBe('UPLOAD_ERR_POST_SIZE');
});

test('l exception handler intercepte l erreur Unable to open mode r et la transforme en 413 plutot qu en 500', function () {
    // Si une RuntimeException "Unable to open ... using mode r" est lancée
    $exception = new RuntimeException('Unable to open /tmp/public using mode r');

    $request = Request::create('/api/listings/media', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)->render($request, $exception);

    expect($response->getStatusCode())->toBe(413);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('file_too_large')
        ->and($data['code'])->toBe('UPLOAD_ERR_INI_SIZE');
});

test('un fichier valide passe normalement la validation et s enregistre sans erreur', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $listing = Listing::factory()->create(['owner_id' => $user->id]);

    Passport::actingAs($user);

    $file = UploadedFile::fake()->create('room_photo.jpg', 500, 'image/jpeg');

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $file,
        'type' => 'image',
        'room' => 'living',
    ]);

    $response->assertStatus(201);
});
