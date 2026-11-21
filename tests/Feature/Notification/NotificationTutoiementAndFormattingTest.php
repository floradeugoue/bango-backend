<?php

use App\Enums\OtpChannel;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Boost\Campaign;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Page\PageMember;
use App\Models\Subscription\PricingChangeProposal;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Boost\CampaignActivatedNotification;
use App\Notifications\Boost\CampaignCompletedNotification;
use App\Notifications\Boost\CampaignSuspendedNotification;
use App\Notifications\Page\PageCertificationApprovedNotification;
use App\Notifications\Page\PageMemberAcceptedNotification;
use App\Notifications\Subscription\PricingChangeScheduledNotification;
use App\Notifications\Subscription\RenewalUpcomingReminderNotification;
use App\Services\Otp\OtpEmailService;
use App\Services\Otp\OtpService;
use App\Services\Sms\ZomloaSmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('lang/fr/notifications.php uses tutoiement and inclusive writing', function () {
    $fr = include resource_path('../lang/fr/notifications.php');

    // Social
    expect($fr['like']['body'])->toBe(':user a aimé ta publication.')
        ->and($fr['story_like']['body'])->toBe(':user a aimé ta story.')
        ->and($fr['story_question_answer']['title'])->toBe('Réponse à ta question')
        ->and($fr['story_question_answer']['body'])->toBe(':user a répondu à ta question : ":snippet"')
        ->and($fr['comment']['body'])->toBe(':user a commenté ta publication : ":snippet"')
        ->and($fr['reply']['body'])->toBe(':user a répondu à ton commentaire : ":snippet"')
        ->and($fr['follow']['body'])->toBe(':user a commencé à te suivre.')
        ->and($fr['follow_request']['body'])->toBe(':user souhaite s\'abonner à ton compte.')
        ->and($fr['mention']['body'])->toBe(':user t\'a mentionné·e dans une publication ou un commentaire.');

    // Visit
    expect($fr['visit_proposed']['body'])->toBe('Un nouveau créneau (:time) t\'est proposé pour :title')
        ->and($fr['visit_declined']['body'])->toBe('Ta demande de visite pour :title a été déclinée. Tu as été remboursé·e.')
        ->and($fr['listing_visit_slot_chosen']['body'])->toBe('Ton créneau du :time a été retenu pour la visite de :title');

    // Message
    expect($fr['new_story_reply']['title'])->toBe(':user a répondu à ta story')
        ->and($fr['added_to_group']['body'])->toBe(':user t\'a ajouté·e au groupe « :group »');

    // Listing
    expect($fr['listing_decision']['title'])->toBe('Statut de ton annonce')
        ->and($fr['listing_decision']['reject'])->toContain('Ton annonce')
        ->and($fr['listing_decision']['default'])->toContain('ton annonce');

    // KYC
    expect($fr['kyc_submitted']['body'])->toContain('Ton dossier de vérification KYC')
        ->and($fr['kyc_decision']['approved_name'])->toContain('Ta pièce d\'identité a été validée. Ton profil s\'appelle désormais')
        ->and($fr['kyc_decision']['approved'])->toContain('Ton dossier de vérification d\'identité')
        ->and($fr['kyc_decision']['rejected'])->toContain('Ton dossier de vérification KYC');

    // Boost
    expect($fr['campaign_activated']['body'])->toBe('Ta campagne de boost est désormais active et en cours de diffusion.')
        ->and($fr['campaign_completed']['body'])->toBe('Ta campagne de boost est terminée. Consulte tes statistiques.')
        ->and($fr['campaign_suspended']['body'])->toBe('Ta campagne de boost a été suspendue : :reason.');

    // Subscription
    expect($fr['renewal_reminder']['body'])->toBe('Ton abonnement :product expire le :date (:amount :currency). Renouvelle manuellement pour conserver ton badge.')
        ->and($fr['payment_failed']['body'])->toBe('Le paiement pour :product a échoué. Régularise ta situation pour conserver ton badge actif.');

    // Page
    expect($fr['page_invitation']['body'])->toBe('Tu as reçu une invitation pour rejoindre la page :page.')
        ->and($fr['page_member_accepted']['body'])->toBe(':user a accepté l\'invitation à rejoindre la Page.')
        ->and($fr['page_member_revoked']['body'])->toBe('Tes droits sur la Page Professionnelle ont été révoqués avec prise d\'effet immédiate.')
        ->and($fr['page_certification_submitted']['body'])->toBe('Le dossier de certification de ta Page a été transmis aux équipes d\'examen.')
        ->and($fr['page_certification_approved']['body'])->toBe('Félicitations, ta Page Professionnelle a été officiellement certifiée !')
        ->and($fr['page_certification_rejected']['body'])->toBe('Le dossier de certification de ta Page a été rejeté.')
        ->and($fr['page_certification_supplement']['body'])->toBe('Des informations complémentaires sont requises pour ton dossier de certification.');

    // Delegation & Moderation & Auth
    expect($fr['agent_accepted']['body'])->toBe('L\'agent a accepté ton invitation de mandat')
        ->and($fr['agent_declined']['body'])->toBe('L\'agent a décliné ton invitation de mandat')
        ->and($fr['moderation_decision']['warn'])->toBe('Tu as reçu un avertissement officiel de modération.')
        ->and($fr['moderation_decision']['suspend'])->toBe('Ton compte a été suspendu jusqu\'au :until suite à un manquement aux règles.')
        ->and($fr['moderation_decision']['action'])->toBe('Une action de modération a été appliquée sur l\'un de tes contenus.')
        ->and($fr['moderation_appeal']['revoked'])->toBe('Ton recours :reference a été accepté et la sanction a été levée.')
        ->and($fr['moderation_appeal']['rejected'])->toBe('Ton recours :reference a été rejeté. Motif : :notes')
        ->and($fr['unknown_device_reported']['body'])->toBe('Ton compte a été sécurisé et verrouillé suite au signalement d\'un appareil inconnu.')
        ->and($fr['device_verification_code']['body'])->toBe('Ton code de confirmation d\'appareil est :code.');
});

test('OtpService::sendOtp uses tutoiement for SMS message', function () {
    $emailMock = Mockery::mock(OtpEmailService::class);
    $smsMock = Mockery::mock(ZomloaSmsService::class);

    $sentMessage = null;
    $smsMock->shouldReceive('send')
        ->once()
        ->with('+237699001122', Mockery::on(function ($msg) use (&$sentMessage) {
            $sentMessage = $msg;

            return str_starts_with($msg, 'Ton code BANGO est :')
                && str_contains($msg, 'Il expire dans 10 minutes.');
        }))
        ->andReturn(true);

    $service = new OtpService($emailMock, $smsMock);
    $res = $service->sendOtp('+237699001122', OtpChannel::Sms, 'test', 10);

    expect($res)->toBeTrue()
        ->and($sentMessage)->toMatch('/^Ton code BANGO est : \d{6}\. Il expire dans 10 minutes\.$/');
});

test('PageMemberAcceptedNotification contains the member user name and tutoiement', function () {
    $owner = User::factory()->create(['name' => 'Owner', 'locale' => 'fr']);
    $memberUser = User::factory()->create([
        'name' => 'alice_wonder',
        'display_name' => 'Alice Wonder',
        'locale' => 'fr',
    ]);
    $page = Page::factory()->create(['owner_id' => $owner->id, 'name' => 'Immo Plus']);

    $pageMember = PageMember::create([
        'page_id' => $page->id,
        'user_id' => $memberUser->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $notification = new PageMemberAcceptedNotification($pageMember);
    $array = $notification->toArray($owner);

    expect($array['type'])->toBe('page_member_accepted')
        ->and($array['user_name'])->toBe('Alice Wonder')
        ->and($array['message'])->toBe('Alice Wonder a accepté l\'invitation à rejoindre la Page.');
});

test('PageCertificationApprovedNotification uses tutoiement in message', function () {
    $owner = User::factory()->create(['name' => 'Owner', 'locale' => 'fr']);
    $page = Page::factory()->create(['owner_id' => $owner->id, 'name' => 'Agence Prestige']);
    $cert = PageCertification::create([
        'page_id' => $page->id,
        'submitted_by_id' => $owner->id,
        'status' => PageCertificationStatus::Approved,
        'submitted_at' => now()->subDays(2),
        'decided_at' => now(),
    ]);

    $notification = new PageCertificationApprovedNotification($cert);
    $array = $notification->toArray($owner);

    expect($array['message'])->toBe('Félicitations, ta Page Professionnelle a été officiellement certifiée !');
});

test('Campaign notifications use tutoiement in messages and toMail', function () {
    $user = User::factory()->create(['locale' => 'fr']);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $actNotif = new CampaignActivatedNotification($campaign);
    expect($actNotif->toArray($user)['message'])->toBe('Ta campagne de boost est désormais active et en cours de diffusion.')
        ->and($actNotif->toMail($user)->subject)->toBe('Ta campagne de boost est active');

    $compNotif = new CampaignCompletedNotification($campaign);
    expect($compNotif->toArray($user)['message'])->toBe('Ta campagne de boost est terminée. Consulte tes statistiques.')
        ->and($compNotif->toMail($user)->subject)->toBe('Ta campagne de boost est terminée');

    $suspNotif = new CampaignSuspendedNotification($campaign, 'Budget épuisé');
    expect($suspNotif->toArray($user)['message'])->toBe('Ta campagne de boost a été suspendue : Budget épuisé.')
        ->and($suspNotif->toMail($user)->subject)->toBe('Ta campagne de boost a été suspendue');
});

test('RenewalUpcomingReminderNotification formats date in account timezone (Douala UTC+1)', function () {
    // 2026-11-19 23:21:00 UTC corresponds to 2026-11-20 00:21:00 in Africa/Douala (UTC+1)
    $utcDate = Carbon::create(2026, 11, 19, 23, 21, 0, 'UTC');

    $userDouala = User::factory()->create([
        'city' => 'Douala',
        'country_code' => 'CM',
        'locale' => 'fr',
    ]);

    $product = SubscriptionProduct::create([
        'code' => 'agent',
        'name' => 'Badge Agent',
        'badge_type' => 'agent',
        'target_role' => 'agent',
        'is_active' => true,
        'display_order' => 1,
    ]);
    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'currency' => 'XAF',
        'price_amount' => 5000,
        'cadence' => 'monthly',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'user_id' => $userDouala->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'amount' => 5000,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => $utcDate->copy()->subMonth(),
        'current_period_end' => $utcDate,
    ]);

    $notification = new RenewalUpcomingReminderNotification($subscription, 1, 'upcoming_1d');
    $data = $notification->toArray($userDouala);

    // In UTC, date would be 19/11/2026. In Douala timezone, it must be 20/11/2026!
    expect($data['message'])->toContain('expire le 20/11/2026')
        ->and($data['message'])->toContain('Ton abonnement Badge Agent expire le 20/11/2026');
});

test('PricingChangeScheduledNotification formats effective date in account timezone and uses tutoiement', function () {
    // 2026-11-19 23:21:00 UTC corresponds to 2026-11-20 00:21:00 in Africa/Douala (UTC+1)
    $utcDate = Carbon::create(2026, 11, 19, 23, 21, 0, 'UTC');

    $userDouala = User::factory()->create([
        'city' => 'Douala',
        'country_code' => 'CM',
        'locale' => 'fr',
    ]);

    $product = SubscriptionProduct::create([
        'code' => 'landlord',
        'name' => 'Badge Bailleur',
        'badge_type' => 'landlord',
        'target_role' => 'landlord',
        'is_active' => true,
        'display_order' => 1,
    ]);
    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'currency' => 'XAF',
        'price_amount' => 10000,
        'cadence' => 'monthly',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    $proposal = PricingChangeProposal::create([
        'price_id' => $price->id,
        'proposed_by_id' => $userDouala->id,
        'new_price_amount' => 12000,
        'effective_date' => $utcDate,
        'status' => 'pending',
    ]);

    $notification = new PricingChangeScheduledNotification($proposal);
    $data = $notification->toArray($userDouala);

    // Date must be formatted in Douala timezone (20/11/2026) and message must use tutoiement
    expect($data['message'])->toContain('Le tarif de ton abonnement Badge Bailleur passera à 12 000 XAF à compter du 20/11/2026.')
        ->and($data['message'])->toContain('Tu conserves ton tarif actuel jusqu\'à ton prochain renouvellement.');
});
