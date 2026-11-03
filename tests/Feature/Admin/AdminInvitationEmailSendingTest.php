<?php

use App\Mail\Admin\AdminAccessRequestRejectedMail;
use App\Mail\Admin\AdminInvitationMail;
use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);

    $this->directionRole = Role::firstOrCreate(['slug' => 'direction'], ['name' => 'Direction']);
    $this->moderationRole = Role::firstOrCreate(['slug' => 'moderation'], ['name' => 'Modérateur']);

    $this->directionUser = User::factory()->create([
        'email' => 'direction@bango.app',
        'role_id' => $this->directionRole->id,
        'status' => 'active',
    ]);

    $this->rawSessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    $this->adminSession = AdminSession::create([
        'id' => hash('sha256', $this->rawSessionId),
        'user_id' => $this->directionUser->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(8),
    ]);
});

it('sends bilingual activation email when direction approves an access request', function () {
    Mail::fake();

    $accessRequest = AdminAccessRequest::create([
        'first_name' => 'Jean',
        'last_name' => 'Dupont',
        'email' => 'jean.dupont@bango.app',
        'department' => 'Modération',
        'requested_role' => 'moderation',
        'reason' => 'Nouveau modérateur',
        'status' => 'pending',
    ]);

    $response = $this->withHeader('X-Bango-Admin-Session', $this->rawSessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson("/api/admin/access-requests/{$accessRequest->id}/approve", [
            'expires_in_days' => 5,
        ]);

    $response->assertStatus(200);

    Mail::assertSent(AdminInvitationMail::class, function (AdminInvitationMail $mail) use ($accessRequest) {
        expect($mail->hasTo($accessRequest->email))->toBeTrue();
        expect($mail->activationUrl)->toContain('/login/activate?token=');
        expect($mail->roleTitle)->toBe('Modérateur');
        expect($mail->roleTitleEn)->toBe('Moderator');
        expect($mail->expiresAtFormattedFr)->not->toBeEmpty();
        expect($mail->expiresAtFormattedEn)->not->toBeEmpty();

        $renderedHtml = $mail->render();
        expect($renderedHtml)->toContain('Activer mon compte opérateur');
        expect($renderedHtml)->toContain('Activate my operator account');
        expect($renderedHtml)->toContain($mail->activationUrl);

        return true;
    });
});

it('sends bilingual activation email when direction creates an invitation via POST /admin/invitations', function () {
    Mail::fake();

    $response = $this->withHeader('X-Bango-Admin-Session', $this->rawSessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson('/api/admin/invitations', [
            'email' => 'sophie.modo@bango.app',
            'role' => 'moderation',
            'expires_in_days' => 7,
        ]);

    $response->assertStatus(201);

    Mail::assertSent(AdminInvitationMail::class, function (AdminInvitationMail $mail) {
        expect($mail->hasTo('sophie.modo@bango.app'))->toBeTrue();
        expect($mail->activationUrl)->toContain('/login/activate?token=');
        expect($mail->expiresAtFormattedFr)->not->toBeEmpty();

        return true;
    });
});

it('sends bilingual rejection email when direction rejects an access request with reason', function () {
    Mail::fake();

    $accessRequest = AdminAccessRequest::create([
        'first_name' => 'Paul',
        'last_name' => 'Martin',
        'email' => 'paul.martin@bango.app',
        'department' => 'Support',
        'requested_role' => 'moderation',
        'reason' => 'Demande initiale',
        'status' => 'pending',
    ]);

    $response = $this->withHeader('X-Bango-Admin-Session', $this->rawSessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson("/api/admin/access-requests/{$accessRequest->id}/reject", [
            'reason' => 'Équipe de modération complète pour ce trimestre.',
        ]);

    $response->assertStatus(200);

    Mail::assertSent(AdminAccessRequestRejectedMail::class, function (AdminAccessRequestRejectedMail $mail) use ($accessRequest) {
        expect($mail->hasTo($accessRequest->email))->toBeTrue();
        expect($mail->name)->toContain('Paul Martin');
        expect($mail->reason)->toBe('Équipe de modération complète pour ce trimestre.');

        $renderedHtml = $mail->render();
        expect($renderedHtml)->toContain('Mise à jour concernant votre demande d\'accès');
        expect($renderedHtml)->toContain('Update Regarding Your Access Request');
        expect($renderedHtml)->toContain('Équipe de modération complète pour ce trimestre.');

        return true;
    });
});
