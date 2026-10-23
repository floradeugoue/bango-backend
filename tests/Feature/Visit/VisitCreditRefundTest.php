<?php

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Boost\CreditTransaction;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Boost\CreditWalletService;
use App\Services\Visit\VisitReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237699112233',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Akwa',
        'visit_fee' => 5000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(3)->setTime(14, 0),
        'end_at' => now()->addDays(3)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    $this->walletService = app(CreditWalletService::class);
    // Solde initial du prospect à 0 crédit
    $this->walletService->getOrCreateWallet($this->prospect);
});

test('prospect cancellation more than 24h before visit paid with credit triggers full refund to credit wallet', function (): void {
    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);
    $reservation->payment_reference = 'VISIT-CREDIT-'.$reservation->id;
    $reservation->save();

    Passport::actingAs($this->prospect);

    $response = $this->postJson("/api/visits/{$reservation->id}/cancel", [
        'reason' => 'Imprévu professionnel',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Refunded->value)
        ->and((float) $data['refund_amount'])->toEqual(5000.0)
        ->and($data['refund_channel'])->toBe('bango_credit')
        ->and($data['cancellation_reason'])->toBe('Imprévu professionnel');

    // Vérification du solde du portefeuille crédité
    expect($this->walletService->getBalance($this->prospect))->toEqual(5000.0);

    // Vérification de la transaction de crédit
    $creditTx = CreditTransaction::where('user_id', $this->prospect->id)
        ->where('reference', 'REFUND-VISIT-'.$reservation->id)
        ->first();

    expect($creditTx)->not->toBeNull()
        ->and($creditTx->source)->toBe('visit_refund')
        ->and($creditTx->amount)->toBe(5000)
        ->and($creditTx->idempotency_key)->toBe('REFUND-VISIT-'.$reservation->id)
        ->and($creditTx->type)->toBe(CreditTransactionType::Deposit);

    // Vérification de la libération du créneau
    expect($this->slot->fresh()->booked_count)->toBe(0);
});

test('prospect cancellation less than 24h before visit paid with credit does not trigger refund', function (): void {
    $this->slot->start_at = now()->addHours(5);
    $this->slot->end_at = now()->addHours(5)->addMinutes(30);
    $this->slot->save();

    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);
    $reservation->payment_reference = 'VISIT-CREDIT-'.$reservation->id;
    $reservation->save();

    Passport::actingAs($this->prospect);

    $response = $this->postJson("/api/visits/{$reservation->id}/cancel", [
        'reason' => 'Empêchement tardif',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Cancelled->value)
        ->and($data['refund_amount'])->toBeNull();

    // Solde inchangé
    expect($this->walletService->getBalance($this->prospect))->toEqual(0.0);
    expect(CreditTransaction::where('user_id', $this->prospect->id)->count())->toBe(0);
});

test('landlord decline of visit paid with credit triggers full refund to credit wallet', function (): void {
    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);
    $reservation->payment_reference = 'VISIT-CREDIT-'.$reservation->id;
    $reservation->save();

    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/landlord/visits/{$reservation->id}/answer", [
        'action' => 'decline',
        'reason' => 'Bailleur indisponible',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Declined->value)
        ->and((float) $data['refund_amount'])->toEqual(5000.0)
        ->and($data['refund_channel'])->toBe('bango_credit')
        ->and($data['decline_reason'])->toBe('Bailleur indisponible');

    expect($this->walletService->getBalance($this->prospect))->toEqual(5000.0);

    $creditTx = CreditTransaction::where('user_id', $this->prospect->id)
        ->where('reference', 'REFUND-VISIT-'.$reservation->id)
        ->first();

    expect($creditTx)->not->toBeNull()
        ->and($creditTx->source)->toBe('visit_refund')
        ->and($creditTx->amount)->toBe(5000)
        ->and($creditTx->idempotency_key)->toBe('REFUND-VISIT-'.$reservation->id);
});

test('overdue unanswered visit paid with credit triggers full refund to credit wallet', function (): void {
    $answerHours = (int) config('visits.answer_hours', 24);

    // Une demande à 20h ne doit pas expirer quand le délai est à 24h
    $notYetOverdue = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now()->subHours(20),
    ]);
    $notYetOverdue->payment_reference = 'VISIT-CREDIT-'.$notYetOverdue->id;
    $notYetOverdue->save();

    $service = app(VisitReservationService::class);
    expect($service->settleOverdueUnanswered())->toBe(0);
    expect($notYetOverdue->fresh()->status)->toBe(VisitReservationStatus::Requested);

    // Une demande payée il y a plus de 24h doit expirer et être remboursée
    $notYetOverdue->update(['paid_at' => now()->subHours($answerHours + 1)]);
    $count = $service->settleOverdueUnanswered();

    expect($count)->toBe(1);

    $fresh = $notYetOverdue->fresh();
    expect($fresh->status)->toBe(VisitReservationStatus::Declined)
        ->and($fresh->decline_reason)->toBe(VisitDeclineReason::NoAnswer)
        ->and((float) $fresh->refund_amount)->toEqual(5000.0)
        ->and($fresh->refund_channel)->toBe('bango_credit')
        ->and($fresh->refunded_at)->not->toBeNull();

    expect($this->walletService->getBalance($this->prospect))->toEqual(5000.0);

    $creditTx = CreditTransaction::where('user_id', $this->prospect->id)
        ->where('reference', 'REFUND-VISIT-'.$notYetOverdue->id)
        ->first();

    expect($creditTx)->not->toBeNull()
        ->and($creditTx->source)->toBe('visit_refund')
        ->and($creditTx->amount)->toBe(5000)
        ->and($creditTx->idempotency_key)->toBe('REFUND-VISIT-'.$notYetOverdue->id);

    // Vérifier l'idempotence : ré-exécuter le règlement ne recrédite pas
    $secondCount = $service->settleOverdueUnanswered();
    expect($secondCount)->toBe(0)
        ->and($this->walletService->getBalance($this->prospect))->toEqual(5000.0);
});
