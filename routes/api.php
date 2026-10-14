<?php

use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionCatalogController;
use App\Http\Controllers\Admin\SubscriptionManagementController;
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
use App\Http\Controllers\Payment\MonetbilWebhookController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Profile\AccountController;
use App\Http\Controllers\Profile\AvatarController;
use App\Http\Controllers\Profile\BlockController;
use App\Http\Controllers\Profile\EmailController;
use App\Http\Controllers\Profile\FollowController;
use App\Http\Controllers\Profile\FollowRequestController;
use App\Http\Controllers\Profile\HandleCheckController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\PrivacyController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\QrController;
use App\Http\Controllers\Profile\SecurityController;
use App\Http\Controllers\Subscription\CatalogController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Subscription\SubscriptionRenewalController;
use App\Http\Resources\UserResource;
use App\Models\Profile\Interest;
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
// Lister tous les centres d'intérêt disponibles
Route::get('/interests', function () {
    return response()->json(Interest::all());
});

// Résolution de QR Code & Profils publics (public, PR-16 / PR-17)
// Résoudre un lien ou QR Code de profil (redirection ou données de contact)
Route::match(['get', 'post'], '/qr/resolve', [QrController::class, 'resolve']);
// Consulter un profil public par son identifiant ou son handle
Route::get('/profiles/{identifier}', [ProfileController::class, 'showPublic']);

// Catalogue des offres & abonnements (public)
Route::prefix('catalog')->group(function () {
    // Lister les offres et badges disponibles au catalogue public
    Route::get('/', [CatalogController::class, 'index']);
    // Afficher les détails d'une offre spécifique par son code produit
    Route::get('/{code}', [CatalogController::class, 'show']);
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
// WEBHOOKS (Publics avec contrôle d'intégrité et signature cryptographique)
// --------------------------------------------------------------------------
Route::prefix('webhooks/payments')->group(function () {
    // Webhook IPN Monetbil (vérification de signature MD5 timing-safe)
    Route::post('/monetbil', [MonetbilWebhookController::class, 'handle'])->name('api.webhooks.payments.monetbil');
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
        // Consulter un profil public (par ID ou handle, PR-07 / PR-08 / PR-09)
        Route::get('/public/{identifier}', [ProfileController::class, 'showPublic']);
        // Alias : consulter un profil utilisateur public par ID ou handle
        Route::get('/user/{identifier}', [ProfileController::class, 'showPublic']);

        // Vérifier la disponibilité d'un identifiant (handle/pseudo)
        Route::post('/handle-check', HandleCheckController::class);
        // Mettre à jour le nom et l'identifiant (et bio)
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

        // Confidentialité du profil (ST-14)
        Route::get('/privacy', [PrivacyController::class, 'show']);
        Route::put('/privacy', [PrivacyController::class, 'update']);

        // Abonnés & Abonnements (PR-11, PR-12)
        // Obtenir la liste des abonnés du compte connecté
        Route::get('/followers', [FollowController::class, 'followers']);
        // Obtenir la liste des abonnements (comptes suivis) du compte connecté
        Route::get('/following', [FollowController::class, 'following']);
        // Obtenir la liste des abonnés d'un utilisateur cible
        Route::get('/{id}/followers', [FollowController::class, 'followers']);
        // Obtenir la liste des abonnements d'un utilisateur cible
        Route::get('/{id}/following', [FollowController::class, 'following']);
        // Suivre un utilisateur ou soumettre une demande d'abonnement
        Route::post('/{id}/follow', [FollowController::class, 'follow']);
        // Ne plus suivre un utilisateur (verbe DELETE)
        Route::delete('/{id}/follow', [FollowController::class, 'unfollow']);
        // Ne plus suivre un utilisateur (verbe POST)
        Route::post('/{id}/unfollow', [FollowController::class, 'unfollow']);
        // Basculer l'état de suivi (suivre / ne plus suivre)
        Route::post('/{id}/toggle-follow', [FollowController::class, 'toggle']);

        // Demandes d'abonnement pour comptes privés (PR-08)
        // Liste des demandes d'abonnement reçues en attente
        Route::get('/follow-requests', [FollowRequestController::class, 'index']);
        // Liste des demandes d'abonnement envoyées par l'utilisateur
        Route::get('/follow-requests/sent', [FollowRequestController::class, 'sent']);
        // Accepter toutes les demandes d'abonnement reçues en attente
        Route::post('/follow-requests/accept-all', [FollowRequestController::class, 'acceptAll']);
        // Accepter une demande d'abonnement spécifique
        Route::post('/follow-requests/{id}/accept', [FollowRequestController::class, 'accept']);
        // Refuser une demande d'abonnement spécifique
        Route::post('/follow-requests/{id}/reject', [FollowRequestController::class, 'reject']);

        // Comptes bloqués (PR-09)
        // Liste des utilisateurs bloqués par l'utilisateur connecté
        Route::get('/blocked', [BlockController::class, 'index']);
        // Bloquer un utilisateur
        Route::post('/{id}/block', [BlockController::class, 'block']);
        // Débloquer un utilisateur (verbe DELETE)
        Route::delete('/{id}/block', [BlockController::class, 'unblock']);
        // Débloquer un utilisateur (verbe POST)
        Route::post('/{id}/unblock', [BlockController::class, 'unblock']);
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

        // Confidentialité du compte (ST-14)
        Route::get('/privacy', [PrivacyController::class, 'show']);
        Route::put('/privacy', [PrivacyController::class, 'update']);

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

    // --------------------------------------------------------------------------
    // SUB & BADGES (Abonnements, Offres & Badges)
    // --------------------------------------------------------------------------
    Route::prefix('subscriptions')->group(function () {
        // Lister l'historique des abonnements de l'utilisateur connecté
        Route::get('/', [SubscriptionController::class, 'index']);
        // Consulter le catalogue des offres et plans d'abonnement disponibles
        Route::get('/plans', [CatalogController::class, 'index']);
        // Obtenir le statut courant des abonnements et badges actifs de l'utilisateur
        Route::get('/status', [SubscriptionController::class, 'status']);
        // Souscrire à une nouvelle formule d'abonnement
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
        // Afficher les détails d'un abonnement spécifique
        Route::get('/{id}', [SubscriptionController::class, 'show']);
        // Changer de plan d'abonnement (migration de formule)
        Route::post('/{id}/change-plan', [SubscriptionController::class, 'changePlan']);
        // Résilier le renouvellement automatique d'un abonnement
        Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel']);

        // --------------------------------------------------------------------------
        // PHASE 6 : RENOUVELLEMENT MANUEL ASSISTÉ (Info, Devis Certifié & Confirmation)
        // --------------------------------------------------------------------------
        Route::get('/{id}/renewal', [SubscriptionRenewalController::class, 'renewalInfo']);
        Route::post('/{id}/renewal/quote', [SubscriptionRenewalController::class, 'quote']);
        Route::post('/{id}/renewal/confirm', [SubscriptionRenewalController::class, 'confirm']);
    });

    Route::prefix('badges')->group(function () {
        // Obtenir la liste et le statut des badges actifs de l'utilisateur
        Route::get('/status', [SubscriptionController::class, 'status']);
        // Consulter l'historique d'attribution, expiration et révocation des badges
        Route::get('/history', [SubscriptionController::class, 'history']);
    });

    // --------------------------------------------------------------------------
    // PAY (Paiements Mobile Money, Devis, Statut & Historique)
    // --------------------------------------------------------------------------
    Route::prefix('payments')->group(function () {
        // Consulter le catalogue des offres et prestations payantes
        Route::get('/catalog', [PaymentController::class, 'catalog']);
        // Établir un devis certifié serveur avant confirmation
        Route::post('/quote', [PaymentController::class, 'quote']);
        // Initier un paiement Mobile Money (Push USSD)
        Route::post('/initiate', [PaymentController::class, 'initiate']);
        // Obtenir le statut en temps réel d'un paiement (polling 120s / reprise réseau)
        Route::get('/{id}/status', [PaymentController::class, 'status']);
        // Annuler un paiement en attente
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel']);
        // Consulter l'historique paginé des transactions du client
        Route::get('/history', [PaymentController::class, 'history']);
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

    // --------------------------------------------------------------------------
    // SUB CATALOG (Administration des offres & tarifs)
    // --------------------------------------------------------------------------
    Route::prefix('admin/catalog')->group(function () {
        // Lister tous les produits d'abonnement du catalogue (actifs et archivés)
        Route::get('/products', [SubscriptionCatalogController::class, 'index']);
        // Créer un nouveau produit d'abonnement
        Route::post('/products', [SubscriptionCatalogController::class, 'store']);
        // Afficher les détails d'un produit avec ses tarifs associés
        Route::get('/products/{id}', [SubscriptionCatalogController::class, 'show']);
        // Mettre à jour les informations d'un produit d'abonnement
        Route::put('/products/{id}', [SubscriptionCatalogController::class, 'update']);
        // Ajouter une nouvelle grille tarifaire (prix / période) à un produit
        Route::post('/products/{id}/prices', [SubscriptionCatalogController::class, 'addPrice']);
        // Modifier un tarif existant (ajustement de prix, activation/désactivation)
        Route::put('/prices/{priceId}', [SubscriptionCatalogController::class, 'updatePrice']);
    });

    // --------------------------------------------------------------------------
    // SUBSCRIPTIONS & BADGES (Supervision & Support)
    // --------------------------------------------------------------------------
    Route::prefix('admin/subscriptions')->group(function () {
        // Lister et filtrer tous les abonnements de la plateforme
        Route::get('/', [SubscriptionManagementController::class, 'index']);
        // Afficher les détails complets d'un abonnement et son journal d'événements
        Route::get('/{id}', [SubscriptionManagementController::class, 'show']);
        // Suspendre administrativement un abonnement
        Route::post('/{id}/suspend', [SubscriptionManagementController::class, 'suspend']);
        // Réactiver un abonnement précédemment suspendu
        Route::post('/{id}/reactivate', [SubscriptionManagementController::class, 'reactivate']);
        // Prolonger la durée de validité d'un abonnement
        Route::post('/{id}/extend', [SubscriptionManagementController::class, 'extend']);
        // Révoquer définitivement un abonnement et supprimer le badge associé
        Route::post('/{id}/revoke', [SubscriptionManagementController::class, 'revoke']);
    });

    // Journal d'audit global des attributions et modifications de badges
    Route::get('admin/badges/history', [SubscriptionManagementController::class, 'badgeHistories']);

    // --------------------------------------------------------------------------
    // PAY MODULE - BACK-OFFICE (Supervision, Audits, Litiges, Remboursements)
    // --------------------------------------------------------------------------
    Route::prefix('admin/payments')->group(function () {
        // Transactions : Supervision, recherche et détail
        Route::get('/transactions', [AdminPaymentController::class, 'index']);
        Route::get('/transactions/{id}', [AdminPaymentController::class, 'show']);
        Route::get('/transactions/{id}/events', [AdminPaymentController::class, 'events']);
        Route::post('/transactions/{id}/refund', [AdminPaymentController::class, 'refund']);

        // Litiges & Contestations
        Route::get('/disputes', [AdminPaymentController::class, 'disputes']);
        Route::post('/disputes', [AdminPaymentController::class, 'createDispute']);
        Route::get('/disputes/{id}', [AdminPaymentController::class, 'showDispute']);
        Route::post('/disputes/{id}/status', [AdminPaymentController::class, 'updateDisputeStatus']);
        Route::post('/disputes/{id}/notes', [AdminPaymentController::class, 'addDisputeNote']);

        // Réclamations Doubles Débits
        Route::get('/double-charge-claims', [AdminPaymentController::class, 'doubleChargeClaims']);
        Route::post('/double-charge-claims', [AdminPaymentController::class, 'createDoubleChargeClaim']);
    });
});
