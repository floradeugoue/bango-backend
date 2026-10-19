<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Otp\OtpController;
use App\Models\Auth\Otp;
use App\Models\Auth\ResetPasswordToken;
use App\Models\User;
use App\Services\Auth\ResetPasswordService;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

Artisan::command('test:otp {identifier}', function () {
    $identifier = $this->argument('identifier');
    $channel = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';

    $request = Request::create('/api/otp/request', 'POST', [
        'channel' => $channel,
        'identifier' => $identifier,
    ]);

    $controller = app(OtpController::class);

    $this->info("Sending OTP {$channel} to {$identifier}...");

    // We capture the response
    $response = $controller->requestOtp($request, app(OtpService::class));

    $this->info('Response: '.$response->getContent());

    // Let's check DB
    $otp = Otp::where('identifier', $identifier)->where('channel', $channel)->first();
    if ($otp) {
        $this->info('OTP was successfully generated in DB.');
    } else {
        $this->error('OTP not found in DB (probably deleted because sending failed).');
    }
});

Artisan::command('test:reset-password {email}', function () {
    $email = $this->argument('email');

    // Assurer que l'utilisateur existe
    $user = User::firstOrCreate(
        ['email' => $email],
        [
            'name' => 'Test User',
            'password' => bcrypt('password'),
            'handle' => 'testuser_'.rand(1000, 9999),
            'display_name' => 'Test User',
            'phone' => '237'.rand(600000000, 699999999),
        ]
    );

    $this->info("Utilisateur préparé (ID: {$user->id}).");
    $this->info("Demande du lien de réinitialisation pour {$email}...");

    $request = Request::create('/api/auth/reset-password/request', 'POST', [
        'email' => $email,
    ]);

    $controller = app(ResetPasswordController::class);
    $response = $controller->requestLink($request, app(ResetPasswordService::class));

    $this->info('Response API: '.$response->getContent());

    $token = ResetPasswordToken::where('user_id', $user->id)->latest()->first();

    if ($token) {
        $this->info('Token généré en base de données : '.$token->token);
        $this->info("L'email de réinitialisation devrait être en cours d'envoi vers {$email}.");
    } else {
        $this->error("Aucun token trouvé en DB, l'email a probablement échoué.");
    }
});

Artisan::command('test:forgot-password {identifier}', function () {
    $identifier = $this->argument('identifier');
    $channel = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';

    // Assurer que l'utilisateur existe
    $field = $channel === 'sms' ? 'phone' : 'email';
    $user = User::firstOrCreate(
        [$field => $identifier],
        [
            'name' => 'Test User FP',
            'email' => $channel === 'email' ? $identifier : 'fp'.rand(1000, 9999).'@example.com',
            'password' => bcrypt('password'),
            'handle' => 'testfp_'.rand(1000, 9999),
            'display_name' => 'Test User FP',
            'phone' => $channel === 'sms' ? $identifier : '237'.rand(600000000, 699999999),
        ]
    );

    $this->info("Utilisateur préparé (ID: {$user->id}).");
    $this->info("Demande d'OTP Forgot Password via {$channel} pour {$identifier}...");

    $request = Request::create('/api/auth/forgot-password', 'POST', [
        'channel' => $channel,
        'identifier' => $identifier,
    ]);

    $controller = app(ForgotPasswordController::class);
    $response = $controller->forgotPassword($request, app(OtpService::class));

    $this->info('Response API: '.$response->getContent());

    $otp = Otp::where('identifier', $identifier)
        ->where('channel', $channel)
        ->where('context', 'forgot_password')
        ->first();

    if ($otp) {
        $this->info('Code OTP généré en base de données : '.$otp->code);
        $this->info("Le message (Email ou SMS) devrait être en cours d'envoi vers {$identifier}.");
    } else {
        $this->error("Aucun code OTP trouvé en DB (probablement supprimé suite à un échec d'envoi).");
    }
});

// --------------------------------------------------------------------------
// PLANIFICATEUR DES ABONNEMENTS (Rappels, Relances et Cycle de vie)
// --------------------------------------------------------------------------
use Illuminate\Support\Facades\Schedule;

// 1. Rappels avant échéance (J-7, J-3, J-1) tous les matins à 08:00
Schedule::command('subscription:send-renewal-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// 2. Relances après échec de paiement toutes les heures
Schedule::command('subscription:send-failed-payment-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// 3. Traitement du cycle de vie (période de grâce & expiration) deux fois par jour
Schedule::command('subscription:process-lifecycle')
    ->twiceDaily(0, 12)
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// PLANIFICATEUR DES PAIEMENTS (Réconciliation serveur-à-serveur)
// --------------------------------------------------------------------------
// 4. Réconciliation automatique des paiements en cours ou indéterminés
Schedule::command('payment:reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// --------------------------------------------------------------------------
// PLANIFICATEUR DES STORIES (CR3 — Expiration 24h & Archivage)
// --------------------------------------------------------------------------
// 5. Traitement de l'expiration des Stories toutes les 15 minutes
Schedule::command('stories:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// PLANIFICATEUR DES VISITES (Étape 2 — Verrous 15min & Délais 18h)
// --------------------------------------------------------------------------
// 6. Expiration des verrous temporaires de 15 minutes toutes les minutes
Schedule::command('visits:expire-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// 7. Règlement des demandes sans réponse après 18h toutes les heures
Schedule::command('visits:settle-overdue')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// PLANIFICATEUR DES REVERSEMENTS BAILLEURS (Étape 4 — Règle J+1 à 08:00)
// --------------------------------------------------------------------------
// 8. Reversements des frais de visite aux bailleurs à J+1 à 08:00
Schedule::command('payouts:process-scheduled')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();
