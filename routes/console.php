<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Otp\OtpController;
use App\Services\Otp\OtpEmailService;
use App\Services\Sms\ZomloaSmsService;
use Illuminate\Http\Request;
use App\Models\Otp;

Artisan::command('test:otp {identifier}', function () {
    $identifier = $this->argument('identifier');
    $channel = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';
    
    $request = Request::create('/api/otp/request', 'POST', [
        'channel' => $channel,
        'identifier' => $identifier
    ]);
    
    $controller = app(OtpController::class);
    
    $this->info("Sending OTP {$channel} to {$identifier}...");

    // We capture the response
    $response = $controller->requestOtp($request, app(\App\Services\Otp\OtpService::class));
    
    $this->info("Response: " . $response->getContent());
    
    // Let's check DB
    $otp = Otp::where('identifier', $identifier)->where('channel', $channel)->first();
    if ($otp) {
        $this->info("OTP was successfully generated in DB.");
    } else {
        $this->error("OTP not found in DB (probably deleted because sending failed).");
    }
});

Artisan::command('test:reset-password {email}', function () {
    $email = $this->argument('email');
    
    // Assurer que l'utilisateur existe
    $user = \App\Models\User::firstOrCreate(
        ['email' => $email],
        [
            'name' => 'Test User',
            'password' => bcrypt('password'),
            'handle' => 'testuser_' . rand(1000, 9999),
            'display_name' => 'Test User',
            'phone' => '237' . rand(600000000, 699999999)
        ]
    );

    $this->info("Utilisateur préparé (ID: {$user->id}).");
    $this->info("Demande du lien de réinitialisation pour {$email}...");

    $request = Request::create('/api/auth/reset-password/request', 'POST', [
        'email' => $email
    ]);
    
    $controller = app(\App\Http\Controllers\Auth\ResetPasswordController::class);
    $response = $controller->requestLink($request, app(\App\Services\Auth\ResetPasswordService::class));
    
    $this->info("Response API: " . $response->getContent());
    
    $token = \App\Models\ResetPasswordToken::where('user_id', $user->id)->latest()->first();
    
    if ($token) {
        $this->info("Token généré en base de données : " . $token->token);
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
    $user = \App\Models\User::firstOrCreate(
        [$field => $identifier],
        [
            'name' => 'Test User FP',
            'email' => $channel === 'email' ? $identifier : 'fp' . rand(1000,9999) . '@example.com',
            'password' => bcrypt('password'),
            'handle' => 'testfp_' . rand(1000, 9999),
            'display_name' => 'Test User FP',
            'phone' => $channel === 'sms' ? $identifier : '237' . rand(600000000, 699999999)
        ]
    );

    $this->info("Utilisateur préparé (ID: {$user->id}).");
    $this->info("Demande d'OTP Forgot Password via {$channel} pour {$identifier}...");

    $request = Request::create('/api/auth/forgot-password', 'POST', [
        'channel' => $channel,
        'identifier' => $identifier
    ]);
    
    $controller = app(\App\Http\Controllers\Auth\ForgotPasswordController::class);
    $response = $controller->forgotPassword($request, app(\App\Services\Otp\OtpService::class));
    
    $this->info("Response API: " . $response->getContent());
    
    $otp = Otp::where('identifier', $identifier)
        ->where('channel', $channel)
        ->where('context', 'forgot_password')
        ->first();
        
    if ($otp) {
        $this->info("Code OTP généré en base de données : " . $otp->code);
        $this->info("Le message (Email ou SMS) devrait être en cours d'envoi vers {$identifier}.");
    } else {
        $this->error("Aucun code OTP trouvé en DB (probablement supprimé suite à un échec d'envoi).");
    }
});
