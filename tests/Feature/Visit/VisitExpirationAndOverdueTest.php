<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Visit\VisitConfirmedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->agent = User::factory()->create([
        'badge' => 'agent',
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237699112233',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'title' => 'Immeuble du Port',
        'visit_fee' => 5000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);
});

test('expire-pending command expires reservations with expired 15m lock and releases slot capacity', function (): void {
    // 1. Réservation avec verrou expiré (il y a 2 minutes)
    $expiredReservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->subMinutes(2),
        'fee' => 5000,
    ]);

    // 2. Réservation avec verrou encore valide (dans 10 minutes)
    $otherProspect = User::factory()->create();
    $validReservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $otherProspect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->addMinutes(10),
        'fee' => 5000,
    ]);

    $this->artisan('visits:expire-pending')
        ->assertSuccessful();

    // La réservation expirée doit avoir son statut mis à jour et son verrou levé
    expect($expiredReservation->fresh()->status)->toBe(VisitReservationStatus::Expired)
        ->and($expiredReservation->fresh()->lock_expires_at)->toBeNull();

    // La réservation encore valide doit rester intacte
    expect($validReservation->fresh()->status)->toBe(VisitReservationStatus::PendingPayment)
        ->and($validReservation->fresh()->lock_expires_at)->not->toBeNull();
});

test('settle-overdue command auto-declines requests unanswered after delay and triggers refund', function (): void {
    // Demande de visite payée au-delà du délai sans réponse du bailleur
    $answerHours = (int) config('visits.answer_hours', 24);
    $overdueReservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now()->subHours($answerHours + 1),
    ]);

    $tx = PaymentTransaction::create([
        'user_id' => $this->prospect->id,
        'service_type' => PaymentServiceType::Visit,
        'payable_type' => VisitReservation::class,
        'payable_id' => $overdueReservation->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'total_amount' => 5000,
        'amount_charged' => 5000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'OVERDUE-'.uniqid(),
    ]);

    $overdueReservation->transaction_id = $tx->id;
    $overdueReservation->save();

    // Exécuter la commande planifiée
    $this->artisan('visits:settle-overdue')
        ->assertSuccessful();

    $fresh = $overdueReservation->fresh();
    expect($fresh->status)->toBe(VisitReservationStatus::Declined)
        ->and($fresh->decline_reason)->toBe(VisitDeclineReason::NoAnswer)
        ->and((float) $fresh->refund_amount)->toEqual(5000.0)
        ->and($tx->fresh()->status)->toBe(PaymentStatus::Refunded);
});

test('notifications are sent to correct recipients without failing database operations', function (): void {
    Notification::fake();

    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'paid_at' => now(),
    ]);

    // Déclencher les notifications de visite confirmée
    $reservation->prospect->notify(new VisitConfirmedNotification($reservation));
    $reservation->owner->notify(new VisitConfirmedNotification($reservation));
    $reservation->agent->notify(new VisitConfirmedNotification($reservation));

    Notification::assertSentTo($this->prospect, VisitConfirmedNotification::class);
    Notification::assertSentTo($this->owner, VisitConfirmedNotification::class);
    Notification::assertSentTo($this->agent, VisitConfirmedNotification::class);
});
