<?php

use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Boost\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('Visit receipt PDF formats timezone, UTF-8 DejaVu font, display name, translated status, FCFA currency, and CCEDA Finance publisher', function (): void {
    $owner = User::factory()->create([
        'name' => 'jeanpaul',
        'display_name' => 'Jean-Paul Bailleur',
        'country_code' => 'CM',
    ]);

    $prospect = User::factory()->create([
        'name' => 'arnaud',
        'display_name' => 'Arnaud T.',
        'country_code' => 'CM',
        'phone' => '+237699000111',
    ]);

    // Annonce à Douala, Cameroun (UTC+1) avec accents dans le titre
    $listing = Listing::factory()->published()->create([
        'owner_id' => $owner->id,
        'title' => 'Chambre moderne à Makepe',
        'city' => 'Douala',
        'neighbourhood' => 'Makepe',
        'currency' => 'XAF',
    ]);

    // Créneau à 09:30:00 UTC (qui correspond à 10:30:00 à Douala UTC+1)
    $slotTimeUtc = Carbon::create(2026, 11, 3, 9, 30, 0, 'UTC');
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => $slotTimeUtc,
        'end_at' => $slotTimeUtc->copy()->addMinutes(30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 2000,
        'fee_amount' => 2000,
        'currency' => 'XAF',
        'paid_at' => Carbon::create(2026, 11, 3, 8, 0, 0, 'UTC'),
        'payment_reference' => 'VISIT-CREDIT-998877',
    ]);

    Passport::actingAs($prospect);
    $response = $this->get("/api/visits/{$reservation->id}/receipt.pdf");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');

    $pdfContent = $response->getContent();

    // 1. Police DejaVuSans et encodage WinAnsi
    expect($pdfContent)->toStartWith('%PDF-1.4')
        ->and($pdfContent)->toContain('/BaseFont /DejaVuSans')
        ->and($pdfContent)->toContain('/Encoding /WinAnsiEncoding');

    // 2. Heure locale dans le fuseau de Douala (UTC+1) : 09:30 UTC -> 10:30
    expect($pdfContent)->toContain('03/11/2026')
        ->and($pdfContent)->toContain('10:30');

    // 3. Caractères accentués ("à") encodés en WinAnsi sans corruption
    $accentedMakepe = mb_convert_encoding('Chambre moderne à Makepe', 'Windows-1252', 'UTF-8');
    expect($pdfContent)->toContain($accentedMakepe);

    // 4. Nom affiché du prospect (Arnaud T. au lieu de l'identifiant arnaud)
    expect($pdfContent)->toContain('Arnaud T.')
        ->and($pdfContent)->not->toContain('Nom et Prenom        : arnaud');

    // 5. Statut traduit en français ("CONFIRMÉE" au lieu de "confirmed")
    $statusConfirmed = mb_convert_encoding('CONFIRMÉE', 'Windows-1252', 'UTF-8');
    expect($pdfContent)->toContain($statusConfirmed)
        ->and($pdfContent)->not->toContain('Statut reservation   : confirmed');

    // 6. Devise formatée selon l'usage local ("2 000 FCFA" au lieu de "2 000,00 XAF")
    expect($pdfContent)->toContain('2 000 FCFA')
        ->and($pdfContent)->not->toContain('2 000,00 XAF');

    // 7. Éditeur unique conforme aux CGU : CCEDA Finance
    expect($pdfContent)->toContain('CCEDA Finance')
        ->and($pdfContent)->not->toContain('BANGO TECHNOLOGIES LTD')
        ->and($pdfContent)->not->toContain('BANGO SAS');
});

test('Overdue unanswered requested visits are declined and refunded via credits when settle command runs', function (): void {
    $prospect = User::factory()->create([
        'name' => 'client_test',
        'display_name' => 'Client Test',
    ]);

    $owner = User::factory()->create();

    $listing = Listing::factory()->published()->create([
        'owner_id' => $owner->id,
        'city' => 'Douala',
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addMinutes(30),
        'capacity' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    // Visite payée le 22/10 par crédit, restée sans réponse en statut 'requested'
    $paidAt = now()->subDays(10);
    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 2000,
        'fee_amount' => 2000,
        'currency' => 'XAF',
        'paid_at' => $paidAt,
        'payment_reference' => 'VISIT-CREDIT-OLD-1234',
    ]);

    // Solde de départ du prospect : 0
    $walletService = app(CreditWalletService::class);
    $initialBalance = $walletService->getBalance($prospect);
    expect($initialBalance)->toBe(0.0);

    // Exécution de la commande planifiée
    $this->artisan('visits:settle-overdue')->assertExitCode(0);

    // Vérification : la réservation passe à Declined avec motif no_answer
    $reservation->refresh();
    expect($reservation->status)->toBe(VisitReservationStatus::Declined)
        ->and($reservation->decline_reason)->toBe(VisitDeclineReason::NoAnswer);

    // Vérification : le prospect a été recrédité de 2000 FCFA de crédits BANGO
    $newBalance = $walletService->getBalance($prospect);
    expect($newBalance)->toBe(2000.0);
});
