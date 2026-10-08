<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| PUBLIC
| ├── AUTH
| └── GEO (Lecture seule)
|
| USER (auth:api)
| ├── PROFILE
| ├── OTP
| ├── ONBOARDING
| ├── HOUSING
| └── SETTINGS
|
| ADMIN (auth:api + admin role)
| ├── ROLES
| ├── USERS
| └── GEO (CRUD)
|
*/

// ==========================================
// 🔓 PUBLIC
// ==========================================

Route::prefix('auth')->group(function () {
    // Création d'un nouveau compte utilisateur
    Route::post('/signup', \App\Http\Controllers\Auth\SignupController::class);
    // Connexion et génération du token d'accès (Passport)
    Route::post('/signin', \App\Http\Controllers\Auth\SigninController::class);
    
    // Demande d'envoi d'un code OTP (par SMS/Email) pour récupérer l'accès au compte
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'forgotPassword']);
    // Vérification du code OTP de récupération saisi par l'utilisateur
    Route::post('/forgot-password/verify', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'verify']);
    // Définition du nouveau mot de passe après validation de l'OTP
    Route::post('/forgot-password/reset', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'reset']);

    // Demande d'envoi d'un lien magique de réinitialisation par email
    Route::post('/reset-password/request', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'requestLink']);
    // Définition du nouveau mot de passe après clic sur le lien magique
    Route::post('/reset-password', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset']);
});

Route::prefix('geo')->group(function () {
    // Lister et afficher un pays spécifique
    Route::apiResource('countries', \App\Http\Controllers\Geo\CountryController::class)->only(['index', 'show']);
    // Lister et afficher une devise
    Route::apiResource('currencies', \App\Http\Controllers\Geo\CurrencyController::class)->only(['index', 'show']);
    // Lister et afficher une ville
    Route::apiResource('cities', \App\Http\Controllers\Geo\CityController::class)->only(['index', 'show']);
    // Lister et afficher un quartier
    Route::apiResource('neighbourhoods', \App\Http\Controllers\Geo\NeighbourhoodController::class)->only(['index', 'show']);
    // Lister et afficher un opérateur téléphonique/internet
    Route::apiResource('operators', \App\Http\Controllers\Geo\OperatorController::class)->only(['index', 'show']);
});


// ==========================================
// 🔒 USER (Requiert un Token Passport valide)
// ==========================================
Route::middleware('auth:api')->group(function () {
    
    // --- AUTH ---
    // Révocation du token d'accès actuel (Déconnexion)
    Route::post('/auth/logout', \App\Http\Controllers\Auth\LogoutController::class);

    // Récupérer les informations de l'utilisateur actuellement connecté
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // --- PROFILE ---
    Route::prefix('profile')->group(function () {
        // Afficher les données complètes du profil de l'utilisateur
        Route::get('/', [\App\Http\Controllers\Profile\ProfileController::class, 'show']);
        // Vérifier la disponibilité d'un identifiant (handle/pseudo)
        Route::post('/handle-check', \App\Http\Controllers\Profile\HandleCheckController::class);
        // Mettre à jour le nom et l'identifiant
        Route::put('/identity', [\App\Http\Controllers\Profile\ProfileController::class, 'updateIdentity']);
        // Mettre à jour la date de naissance
        Route::put('/birthdate', [\App\Http\Controllers\Profile\ProfileController::class, 'updateBirthdate']);
        // Mettre à jour le genre/sexe
        Route::put('/gender', [\App\Http\Controllers\Profile\ProfileController::class, 'updateGender']);
        // Mettre à jour la localisation actuelle de l'utilisateur
        Route::put('/location', [\App\Http\Controllers\Profile\ProfileController::class, 'updateLocation']);
        // Mettre à jour les centres d'intérêt
        Route::put('/interests', [\App\Http\Controllers\Profile\ProfileController::class, 'updateInterests']);
        // Mettre à jour la photo de profil (Avatar)
        Route::put('/avatar', \App\Http\Controllers\Profile\AvatarController::class);
    });
    
    // --- OTP (Authentification et vérifications diverses) ---
    Route::prefix('otp')->group(function () {
        // Demander l'envoi d'un nouveau code OTP
        Route::post('/request', [\App\Http\Controllers\Otp\OtpController::class, 'requestOtp']);
        // Soumettre et valider un code OTP
        Route::post('/verify', [\App\Http\Controllers\Otp\OtpController::class, 'verifyOtp']);
        // Redemander un code OTP (souvent après expiration du délai d'attente)
        Route::post('/resend', [\App\Http\Controllers\Otp\OtpController::class, 'resendOtp']);
    });

    // --- ONBOARDING ---
    // Enregistrer la complétion d'une étape du processus d'inscription initial
    Route::post('/onboarding/complete', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'completeStep']);

    // --- HOUSING (Recherche de logement) ---
    Route::prefix('housing/search')->group(function () {
        // Afficher les critères de recherche actuels
        Route::get('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'show']);
        // Mettre à jour les critères de recherche du logement
        Route::put('/', [\App\Http\Controllers\Housing\HousingSearchController::class, 'update']);
    });

    // --- SETTINGS (Paramètres du compte) ---
    Route::prefix('settings')->group(function () {
        // Initier la procédure de changement d'adresse e-mail
        Route::post('/email/request', [\App\Http\Controllers\Profile\EmailController::class, 'requestChange']);
        // Valider le changement d'adresse e-mail avec un code
        Route::post('/email/verify', [\App\Http\Controllers\Profile\EmailController::class, 'verifyChange']);
        // Renvoyer le code de validation à la nouvelle adresse e-mail
        Route::post('/email/resend', [\App\Http\Controllers\Profile\EmailController::class, 'resendCode']);
        // Annuler la procédure de changement d'e-mail en cours
        Route::post('/email/cancel', [\App\Http\Controllers\Profile\EmailController::class, 'cancelChange']);
        
        // Mettre à jour le mot de passe depuis l'espace utilisateur
        Route::put('/password', [\App\Http\Controllers\Profile\PasswordController::class, 'update']);

        // Bouton d'urgence : Sécuriser le compte (déconnecter autres appareils)
        Route::post('/secure', [\App\Http\Controllers\Profile\SecurityController::class, 'secureAccount']);
        // Afficher la liste de tous les appareils connectés au compte
        Route::get('/sessions', [\App\Http\Controllers\Profile\SecurityController::class, 'getSessions']);
        // Valider/Reconnaître manuellement un appareil ou une session
        Route::post('/sessions/{id}/acknowledge', [\App\Http\Controllers\Profile\SecurityController::class, 'acknowledgeSession']);

        // Suspendre temporairement le compte (Pause)
        Route::post('/account/pause', [\App\Http\Controllers\Profile\AccountController::class, 'pause']);
        // Supprimer définitivement le compte
        Route::delete('/account', [\App\Http\Controllers\Profile\AccountController::class, 'delete']);
    });

    // ==========================================
    // 🛡️ ADMIN (Requiert un Token Passport + Rôle système "Admin")
    // ==========================================
    Route::middleware('admin')->group(function () {
        
        // --- ROLES & USERS ---
        // CRUD complet (Créer, Lire, Mettre à jour, Supprimer) sur les Rôles du système
        Route::apiResource('roles', \App\Http\Controllers\Admin\RoleController::class);
        // Lister tous les utilisateurs possédant un rôle spécifique
        Route::get('roles/{role}/users', [\App\Http\Controllers\Admin\RoleController::class, 'users']);
        // Voir le rôle actuel assigné à un utilisateur spécifique
        Route::get('users/{user}/role', [\App\Http\Controllers\Admin\RoleController::class, 'getUserRole']);
        // Modifier/Assigner un nouveau rôle à un utilisateur spécifique
        Route::patch('users/{user}/role', [\App\Http\Controllers\Admin\RoleController::class, 'updateUserRole']);
        
        // --- GEO (CRUD complet pour Admin afin de modifier la base géographique) ---
        Route::prefix('geo')->group(function () {
            // Créer, modifier ou supprimer un Pays (l'index/show est public)
            Route::apiResource('countries', \App\Http\Controllers\Geo\CountryController::class)->except(['index', 'show']);
            // Créer, modifier ou supprimer une Devise
            Route::apiResource('currencies', \App\Http\Controllers\Geo\CurrencyController::class)->except(['index', 'show']);
            // Créer, modifier ou supprimer une Ville
            Route::apiResource('cities', \App\Http\Controllers\Geo\CityController::class)->except(['index', 'show']);
            // Créer, modifier ou supprimer un Quartier
            Route::apiResource('neighbourhoods', \App\Http\Controllers\Geo\NeighbourhoodController::class)->except(['index', 'show']);
            // Créer, modifier ou supprimer un Opérateur
            Route::apiResource('operators', \App\Http\Controllers\Geo\OperatorController::class)->except(['index', 'show']);
        });

        // --- GESTION DES USERS ---
        Route::prefix('users')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\UserController::class, 'index']);
            Route::get('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show']);
            Route::patch('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update']);
            
            Route::post('/{user}/suspend', [\App\Http\Controllers\Admin\UserController::class, 'suspend']);
            Route::post('/{user}/unsuspend', [\App\Http\Controllers\Admin\UserController::class, 'unsuspend']);
            Route::post('/{user}/block', [\App\Http\Controllers\Admin\UserController::class, 'block']);
            Route::post('/{user}/unblock', [\App\Http\Controllers\Admin\UserController::class, 'unblock']);
            Route::delete('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy']);
            
            Route::get('/{user}/sessions', [\App\Http\Controllers\Admin\UserController::class, 'sessions']);
            Route::delete('/{user}/sessions', [\App\Http\Controllers\Admin\UserController::class, 'revokeAllSessions']);
            Route::delete('/{user}/sessions/{session}', [\App\Http\Controllers\Admin\UserController::class, 'revokeSession']);
        });

    });
});
