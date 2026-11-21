<?php

namespace Tests\Feature\Reels;

use App\Enums\Reel\ReelStatus;
use App\Enums\Support\ReportReason;
use App\Http\Resources\Reel\ReelResource;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\Support\CopyrightClaim;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Reel\ReelAudioMutedCopyrightNotification;
use App\Services\Admin\CopyrightService;
use App\Services\Admin\ModerationQueueService;
use App\Services\Reel\SoundService;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);

    $this->modRole = Role::where('slug', 'moderation')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'mod.copyright.'.uniqid().'@bango.app',
        'role_id' => $this->modRole->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

it('mutes derived reels and marks original sound removed when original sound is taken down for copyright', function () {
    Notification::fake();

    $authorA = User::factory()->create(['name' => 'Auteur Original']);
    $authorB = User::factory()->create(['name' => 'Auteur Derive 1']);
    $authorC = User::factory()->create(['name' => 'Auteur Derive 2']);

    // Reel source (qui produit le son original orig:<reelA_id>)
    $reelA = Reel::factory()->create([
        'user_id' => $authorA->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Reel avec bande sonore originale',
        'sound_id' => null,
    ]);

    $soundId = "orig:{$reelA->id}";

    Sound::create([
        'id' => $soundId,
        'title' => 'Son original',
        'artist' => $authorA->name,
        'genre' => 'afro',
        'is_original' => true,
        'original_reel_id' => $reelA->id,
        'user_id' => $authorA->id,
    ]);

    // Reels dérivés utilisant le son original du Reel A
    $reelB = Reel::factory()->create([
        'user_id' => $authorB->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Reel reprenant le son original',
        'sound_id' => $soundId,
        'is_muted' => false,
    ]);

    $reelC = Reel::factory()->create([
        'user_id' => $authorC->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Autre reel reprenant le son original',
        'sound_id' => $soundId,
        'is_muted' => false,
    ]);

    /** @var SoundService $soundService */
    $soundService = app(SoundService::class);
    $result = $soundService->handleOriginalSoundCopyrightInfringement($reelA, $this->operator, 'Musique commerciale sous copyright non autorisée');

    // 1. Vérification du son original
    expect($result['muted_reels_count'])->toBe(2);
    expect($result['affected_users_count'])->toBe(2);

    $sound = Sound::find($soundId);
    expect($sound)->not->toBeNull();
    expect($sound->is_removed)->toBeTrue();
    expect($sound->is_published)->toBeFalse();
    expect($sound->rights_status)->toBe('disputed');

    // 2. Vérification des reels dérivés : vidéos conservées mais son coupé
    $freshReelB = $reelB->fresh();
    expect($freshReelB->trashed())->toBeFalse();
    expect($freshReelB->is_muted)->toBeTrue();

    $freshReelC = $reelC->fresh();
    expect($freshReelC->trashed())->toBeFalse();
    expect($freshReelC->is_muted)->toBeTrue();

    // 3. Vérification des notifications envoyées aux auteurs B et C
    Notification::assertSentTo($authorB, ReelAudioMutedCopyrightNotification::class, function ($notif) use ($reelB, $soundId) {
        return $notif->reel->id === $reelB->id && (is_string($notif->sound) ? $notif->sound : $notif->sound->id) === $soundId;
    });

    Notification::assertSentTo($authorC, ReelAudioMutedCopyrightNotification::class, function ($notif) use ($reelC) {
        return $notif->reel->id === $reelC->id;
    });

    // 4. Vérification de ReelResource pour le reel dérivé
    $resourceData = (new ReelResource($freshReelB))->toArray(Request::create('/api/reels'));
    expect($resourceData['sound_removed'])->toBeTrue();
    expect($resourceData['soundRemoved'])->toBeTrue();
    expect($resourceData['is_muted'])->toBeTrue();
    expect($resourceData['isMuted'])->toBeTrue();
});

it('mutes all derived reels when a copyright claim is resolved with content_removed', function () {
    Notification::fake();

    $authorA = User::factory()->create(['name' => 'Créateur Reel A']);
    $authorB = User::factory()->create(['name' => 'Créateur Reel B']);

    $reelA = Reel::factory()->create([
        'user_id' => $authorA->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Reel contesté pour droits d\'auteur',
    ]);

    $soundId = "orig:{$reelA->id}";

    Sound::create([
        'id' => $soundId,
        'title' => 'Son original',
        'artist' => $authorA->name,
        'genre' => 'afro',
        'is_original' => true,
        'original_reel_id' => $reelA->id,
        'user_id' => $authorA->id,
    ]);

    $reelB = Reel::factory()->create([
        'user_id' => $authorB->id,
        'status' => ReelStatus::Ready,
        'sound_id' => $soundId,
        'is_muted' => false,
    ]);

    $claim = CopyrightClaim::create([
        'claimant_name' => 'Label Musical',
        'claimant_email' => 'legal@label.com',
        'content_type' => 'reel',
        'content_id' => (string) $reelA->id,
        'work_title' => 'Titre Musical Sous Licence',
        'reason' => 'Bande-son intégrale d\'une chanson protégée.',
        'status' => 'pending',
    ]);

    /** @var CopyrightService $copyrightService */
    $copyrightService = app(CopyrightService::class);
    $resolvedClaim = $copyrightService->resolveClaim(
        claimRef: $claim->reference,
        decision: 'content_removed',
        decisionNotes: 'Violation de copyright avérée, retrait du reel et coupure de son.',
        operator: $this->operator
    );

    expect($resolvedClaim->status)->toBe('approved');
    expect($resolvedClaim->decision)->toBe('content_removed');

    // Le reel source A est retiré
    expect(Reel::find($reelA->id))->toBeNull();
    expect(Reel::withTrashed()->find($reelA->id)->trashed())->toBeTrue();

    // Le son original est marqué retiré
    $sound = Sound::find($soundId);
    expect($sound)->not->toBeNull();
    expect($sound->is_removed)->toBeTrue();

    // Le reel dérivé B est conservé (non supprimé) mais muté
    $freshReelB = $reelB->fresh();
    expect($freshReelB->trashed())->toBeFalse();
    expect($freshReelB->is_muted)->toBeTrue();

    // Notification reçue
    Notification::assertSentTo($authorB, ReelAudioMutedCopyrightNotification::class);
});

it('mutes all derived reels when a moderation report for copyright removes a reel', function () {
    Notification::fake();

    $authorA = User::factory()->create(['name' => 'Utilisateur Source']);
    $authorB = User::factory()->create(['name' => 'Utilisateur Réutilisateur']);

    $reelA = Reel::factory()->create([
        'user_id' => $authorA->id,
        'status' => ReelStatus::Ready,
    ]);

    $soundId = "orig:{$reelA->id}";

    Sound::create([
        'id' => $soundId,
        'title' => 'Son original',
        'artist' => $authorA->name,
        'genre' => 'afro',
        'is_original' => true,
        'original_reel_id' => $reelA->id,
        'user_id' => $authorA->id,
    ]);

    $reelB = Reel::factory()->create([
        'user_id' => $authorB->id,
        'status' => ReelStatus::Ready,
        'sound_id' => $soundId,
        'is_muted' => false,
    ]);

    $report = Report::create([
        'reference' => 'REP-CPY-'.bin2hex(random_bytes(4)),
        'reporter_id' => $this->operator->id,
        'reportable_type' => 'reel',
        'reportable_id' => $reelA->id,
        'reason' => ReportReason::Copyright->value,
        'description' => 'Diffusion d\'un titre protégé sans autorisation',
        'status' => 'pending',
    ]);

    /** @var ModerationQueueService $modService */
    $modService = app(ModerationQueueService::class);
    $decision = $modService->applyDecision(
        report: $report,
        action: 'remove',
        justification: 'Atteinte avérée aux droits d\'auteur (copyright)',
        operator: $this->operator
    );

    // Reel source supprimé
    expect(Reel::find($reelA->id))->toBeNull();

    // Le son original est retiré
    $sound = Sound::find($soundId);
    expect($sound)->not->toBeNull();
    expect($sound->is_removed)->toBeTrue();

    // Le reel dérivé B est conservé mais son coupé
    $freshReelB = $reelB->fresh();
    expect($freshReelB->trashed())->toBeFalse();
    expect($freshReelB->is_muted)->toBeTrue();

    Notification::assertSentTo($authorB, ReelAudioMutedCopyrightNotification::class);
});
