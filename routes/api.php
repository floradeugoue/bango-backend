<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AccountStateController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SecondFactorController;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Geo\CityController;
use App\Http\Controllers\Geo\CountryController;
use App\Http\Controllers\Geo\CurrencyController;
use App\Http\Controllers\Geo\NeighbourhoodController;
use App\Http\Controllers\Geo\OperatorController;
use App\Http\Controllers\Housing\HousingSearchController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Otp\OtpController;
use App\Http\Controllers\Profile\AccountController;
use App\Http\Controllers\Profile\AvatarController;
use App\Http\Controllers\Profile\EmailController;
use App\Http\Controllers\Profile\HandleCheckController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\SecurityController;
use App\Http\Resources\UserResource;
use App\Models\Interest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| API Routes
|==========================================================================
|
| 1. 🌍 COMMUN (Mobile + Back-office)
|    ├── AUTH (public)          → inscription, connexion, mot de passe oublié
|    ├── GEO  (public, lecture) → pays, devises, villes, quartiers, opérateurs
|    └── SESSION (auth:api)     → déconnexion, utilisateur courant
|
| 2. 📱 MOBILE (auth:api)
|    ├── PROFILE
|    ├── OTP
|    ├── ONBOARDING
|    ├── HOUSING
|    └── SETTINGS
|
| 3. 🖥️ BACK-OFFICE (auth:api + admin)
|    ├── ROLES
|    ├── USERS
|    └── GEO (CRUD)
|
*/

/*
|==========================================================================
| 1. 🌍 COMMUN — Utilisé par l'application MOBILE et le BACK-OFFICE
|==========================================================================
*/

// --------------------------------------------------------------------------
// AUTH (public)
// --------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    // Création d'un nouveau compte utilisateur
    Route::post('/signup', SignupController::class);
    // Connexion et génération du token d'accès (Passport)
    Route::post('/signin', SigninController::class);
    // Validation du second facteur (2FA)
    Route::post('/signin/second-factor', SecondFactorController::class);

    // Demande d'envoi d'un code OTP (par SMS/Email) pour récupérer l'accès au compte
    Route::post('/forgot-password', [ForgotPasswordController::class, 'forgotPassword']);
    // Vérification du code OTP de récupération saisi par l'utilisateur
    Route::post('/forgot-password/verify', [ForgotPasswordController::class, 'verify']);
    // Définition du nouveau mot de passe après validation de l'OTP
    Route::post('/forgot-password/reset', [ForgotPasswordController::class, 'reset']);

    // Demande d'envoi d'un lien magique de réinitialisation par email
    Route::post('/reset-password/request', [ResetPasswordController::class, 'requestLink']);
    // Définition du nouveau mot de passe après clic sur le lien magique
    Route::post('/reset-password', [ResetPasswordController::class, 'reset']);

    // État d'un compte suspendu ou supprimé (écrans AU-26 / AU-27)
    Route::post('/account-state', AccountStateController::class);

    // Restaurer un compte supprimé (soft delete)
    Route::post('/restore-account', [AccountController::class, 'restore']);
});

// Centres d'intérêt (public)
Route::get('/interests', function () {
    return response()->json(Interest::all());
});

// --------------------------------------------------------------------------
// GEO (public, lecture seule)
// --------------------------------------------------------------------------
Route::prefix('geo')->group(function () {
    // Lister et afficher un pays spécifique
    Route::apiResource('countries', CountryController::class)->only(['index', 'show']);
    // Lister et afficher une devise
    Route::apiResource('currencies', CurrencyController::class)->only(['index', 'show']);
    // Lister et afficher une ville
    Route::apiResource('cities', CityController::class)->only(['index', 'show']);
    // Lister et afficher un quartier
    Route::apiResource('neighbourhoods', NeighbourhoodController::class)->only(['index', 'show']);
    // Lister et afficher un opérateur téléphonique/internet
    Route::apiResource('operators', OperatorController::class)->only(['index', 'show']);
});

// --------------------------------------------------------------------------
// SESSION (Requiert un Token Passport valide)
// --------------------------------------------------------------------------
Route::middleware('auth:api')->group(function () {
    // Révocation du token d'accès actuel (Déconnexion)
    Route::post('/auth/logout', LogoutController::class);

    // Récupérer les informations de l'utilisateur actuellement connecté
    Route::get('/user', function (Request $request) {
        return new UserResource($request->user()->load('interests'));
    });
});

/*
|==========================================================================
| 2. 📱 MOBILE — Application mobile (Requiert un Token Passport valide)
|==========================================================================
*/
Route::middleware('auth:api')->group(function () {

    // --------------------------------------------------------------------------
    // PROFILE
    // --------------------------------------------------------------------------
    Route::prefix('profile')->group(function () {
        // Afficher les données complètes du profil de l'utilisateur
        Route::get('/', [ProfileController::class, 'show']);
        // Vérifier la disponibilité d'un identifiant (handle/pseudo)
        Route::post('/handle-check', HandleCheckController::class);
        // Mettre à jour le nom et l'identifiant
        Route::put('/identity', [ProfileController::class, 'updateIdentity']);
        // Mettre à jour la date de naissance
        Route::put('/birthdate', [ProfileController::class, 'updateBirthdate']);
        // Mettre à jour le genre/sexe
        Route::put('/gender', [ProfileController::class, 'updateGender']);
        // Mettre à jour la localisation actuelle de l'utilisateur
        Route::put('/location', [ProfileController::class, 'updateLocation']);
        // Mettre à jour la biographie
        Route::put('/bio', [ProfileController::class, 'updateBio']);
        // Mettre à jour les centres d'intérêt
        Route::put('/interests', [ProfileController::class, 'updateInterests']);
        // Mettre à jour la photo de profil (Avatar)
        Route::put('/avatar', AvatarController::class);
    });

    // --------------------------------------------------------------------------
    // OTP (Authentification et vérifications diverses)
    // --------------------------------------------------------------------------
    Route::prefix('otp')->group(function () {
        // Demander l'envoi d'un nouveau code OTP
        Route::post('/request', [OtpController::class, 'requestOtp']);
        // Soumettre et valider un code OTP
        Route::post('/verify', [OtpController::class, 'verifyOtp']);
        // Redemander un code OTP (souvent après expiration du délai d'attente)
        Route::post('/resend', [OtpController::class, 'resendOtp']);
    });

    // --------------------------------------------------------------------------
    // ONBOARDING
    // --------------------------------------------------------------------------
    // Enregistrer la complétion d'une étape du processus d'inscription initial
    Route::post('/onboarding/complete', [OnboardingController::class, 'completeStep']);

    // --------------------------------------------------------------------------
    // HOUSING (Recherche de logement)
    // --------------------------------------------------------------------------
    Route::prefix('housing/search')->group(function () {
        // Afficher les critères de recherche actuels
        Route::get('/', [HousingSearchController::class, 'show']);
        // Mettre à jour les critères de recherche du logement
        Route::put('/', [HousingSearchController::class, 'update']);
    });

    // --------------------------------------------------------------------------
    // SETTINGS (Paramètres du compte)
    // --------------------------------------------------------------------------
    Route::prefix('settings')->group(function () {
        // Initier la procédure de changement d'adresse e-mail
        Route::post('/email/request', [EmailController::class, 'requestChange']);
        // Valider le changement d'adresse e-mail avec un code
        Route::post('/email/verify', [EmailController::class, 'verifyChange']);
        // Renvoyer le code de validation à la nouvelle adresse e-mail
        Route::post('/email/resend', [EmailController::class, 'resendCode']);
        // Annuler la procédure de changement d'e-mail en cours
        Route::post('/email/cancel', [EmailController::class, 'cancelChange']);

        // Mettre à jour le mot de passe depuis l'espace utilisateur
        Route::put('/password', [PasswordController::class, 'update']);

        // Bouton d'urgence : Sécuriser le compte (déconnecter autres appareils)
        Route::post('/secure', [SecurityController::class, 'secureAccount']);
        // Afficher la liste de tous les appareils connectés au compte
        Route::get('/sessions', [SecurityController::class, 'getSessions']);
        // Déconnecter un appareil / session spécifique
        Route::delete('/sessions/{id}', [SecurityController::class, 'revokeSession']);
        // Valider/Reconnaître manuellement un appareil ou une session
        Route::post('/sessions/{id}/acknowledge', [SecurityController::class, 'acknowledgeSession']);

        // Suspendre temporairement le compte (Pause)
        Route::post('/account/pause', [AccountController::class, 'pause']);
        // Supprimer définitivement le compte
        Route::delete('/account', [AccountController::class, 'delete']);
    });
});

/*
|==========================================================================
| 3. 🖥️ BACK-OFFICE — Administration (Token Passport + Rôle système "Admin")
|==========================================================================
*/
Route::middleware(['auth:api', 'admin'])->group(function () {

    // --------------------------------------------------------------------------
    // ROLES
    // --------------------------------------------------------------------------
    // CRUD complet (Créer, Lire, Mettre à jour, Supprimer) sur les Rôles du système
    Route::apiResource('roles', RoleController::class);
    // Lister tous les utilisateurs possédant un rôle spécifique
    Route::get('roles/{role}/users', [RoleController::class, 'users']);

    // --------------------------------------------------------------------------
    // USERS
    // --------------------------------------------------------------------------
    Route::prefix('users')->group(function () {
        // Lister tous les utilisateurs (avec filtres, tris et pagination)
        Route::get('/', [UserController::class, 'index']);
        // Afficher les détails complets d'un utilisateur spécifique
        Route::get('/{user}', [UserController::class, 'show']);
        // Mettre à jour les informations de base d'un utilisateur (sauf mot de passe)
        Route::patch('/{user}', [UserController::class, 'update']);
        // Supprimer un utilisateur (Soft delete : passe l'utilisateur en corbeille)
        Route::delete('/{user}', [UserController::class, 'destroy']);

        // Voir le rôle actuel assigné à un utilisateur spécifique
        Route::get('/{user}/role', [RoleController::class, 'getUserRole']);
        // Modifier/Assigner un nouveau rôle à un utilisateur spécifique
        Route::patch('/{user}/role', [RoleController::class, 'updateUserRole']);

        // Suspendre temporairement le compte d'un utilisateur (déconnexion immédiate)
        Route::post('/{user}/suspend', [UserController::class, 'suspend']);
        // Lever la suspension d'un compte utilisateur
        Route::post('/{user}/unsuspend', [UserController::class, 'unsuspend']);
        // Bloquer définitivement un utilisateur (accès interdit)
        Route::post('/{user}/block', [UserController::class, 'block']);
        // Débloquer un utilisateur précédemment bloqué
        Route::post('/{user}/unblock', [UserController::class, 'unblock']);

        // Lister toutes les sessions (appareils) actives d'un utilisateur
        Route::get('/{user}/sessions', [UserController::class, 'sessions']);
        // Révoquer/Déconnecter toutes les sessions actives de l'utilisateur
        Route::delete('/{user}/sessions', [UserController::class, 'revokeAllSessions']);
        // Révoquer/Déconnecter une session spécifique de l'utilisateur
        Route::delete('/{user}/sessions/{session}', [UserController::class, 'revokeSession']);
    });

    // --------------------------------------------------------------------------
    // GEO (CRUD — l'index/show est public, voir section COMMUN)
    // --------------------------------------------------------------------------
    Route::prefix('geo')->group(function () {
        // Créer, modifier ou supprimer un Pays
        Route::apiResource('countries', CountryController::class)->except(['index', 'show']);
        // Créer, modifier ou supprimer une Devise
        Route::apiResource('currencies', CurrencyController::class)->except(['index', 'show']);
        // Créer, modifier ou supprimer une Ville
        Route::apiResource('cities', CityController::class)->except(['index', 'show']);
        // Créer, modifier ou supprimer un Quartier
        Route::apiResource('neighbourhoods', NeighbourhoodController::class)->except(['index', 'show']);
        // Créer, modifier ou supprimer un Opérateur
        Route::apiResource('operators', OperatorController::class)->except(['index', 'show']);
    });
});
