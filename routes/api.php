<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 🔓 AUTHENTIFICATION PUBLIQUE
// ==========================================
Route::prefix('auth')->group(function () {
    // Création d'un nouveau compte avec email + mot de passe
    Route::post('/signup', \App\Http\Controllers\Auth\SignupController::class);
    
    // Connexion avec email ou téléphone + mot de passe (inclut le Rate Limiting)
    Route::post('/signin', \App\Http\Controllers\Auth\SigninController::class);
});

// ==========================================
// 🔒 ROUTES PROTÉGÉES (Requiert un Token Passport)
// ==========================================
Route::middleware('auth:api')->group(function () {
    
    // Récupérer l'utilisateur actuellement authentifié (route par défaut Laravel)
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ------------------------------------------
    // 👤 PROFIL UTILISATEUR
    // ------------------------------------------
    Route::prefix('profile')->group(function () {
        // Récupérer le profil complet de l'utilisateur (avec ses intérêts)
        Route::get('/', [\App\Http\Controllers\Profile\ProfileController::class, 'show']);
        
        // Vérifier la disponibilité d'un pseudo (handle) et obtenir des suggestions
        Route::post('/handle-check', \App\Http\Controllers\Profile\HandleCheckController::class);
        
        // Mettre à jour l'identité (pseudo et nom d'affichage)
        Route::put('/identity', [\App\Http\Controllers\Profile\ProfileController::class, 'updateIdentity']);
        
        // Mettre à jour la date de naissance (validation serveur des 18 ans minimum)
        Route::put('/birthdate', [\App\Http\Controllers\Profile\ProfileController::class, 'updateBirthdate']);
        
        // Mettre à jour le genre et sa visibilité publique
        Route::put('/gender', [\App\Http\Controllers\Profile\ProfileController::class, 'updateGender']);
        
        // Mettre à jour la localisation (code pays, ex: "SN")
        Route::put('/location', [\App\Http\Controllers\Profile\ProfileController::class, 'updateLocation']);
        
        // Mettre à jour les centres d'intérêt (remplace les anciens choix, min 3 obligatoires)
        Route::put('/interests', [\App\Http\Controllers\Profile\ProfileController::class, 'updateInterests']);
        
        // Uploader et mettre à jour la photo de profil (Avatar)
        Route::put('/avatar', \App\Http\Controllers\Profile\AvatarController::class);
    });
    
    // ------------------------------------------
    // 📱 VALIDATION OTP (Numéro de téléphone)
    // ------------------------------------------
    Route::prefix('otp')->group(function () {
        // Demander l'envoi d'un code OTP par SMS (génère le code en base)
        Route::post('/request', [\App\Http\Controllers\Otp\OtpController::class, 'requestOtp']);
        
        // Vérifier le code OTP saisi (valide le numéro si le code est correct)
        Route::post('/verify', [\App\Http\Controllers\Otp\OtpController::class, 'verifyOtp']);
        
        // Renvoyer un code OTP (avec gestion de timer d'attente)
        Route::post('/resend', [\App\Http\Controllers\Otp\OtpController::class, 'resendOtp']);
    });

    // ------------------------------------------
    // 🚀 ONBOARDING PROGRESSION
    // ------------------------------------------
    // Marquer une étape spécifique de l'onboarding comme "complétée" (sauvegardé en JSON)
    Route::post('/onboarding/complete', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'completeStep']);

    // ------------------------------------------
    // 🏠 RECHERCHE DE LOGEMENT (Housing)
    // ------------------------------------------
    Route::prefix('housing/search')->group(function () {
        // Récupérer les critères de recherche immobilière de l'utilisateur
        Route::get('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'show']);
        
        // Sauvegarder ou mettre à jour les critères de recherche immobilière
        Route::put('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'update']);
    });
});
