<?php

use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSoundCatalogController;
use App\Http\Controllers\Admin\Auth\AdminAccessRequestController;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Auth\AdminDeviceController;
use App\Http\Controllers\Admin\Auth\AdminInvitationController;
use App\Http\Controllers\Admin\Auth\AdminPasswordResetController;
use App\Http\Controllers\Admin\Finance\AdminCampaignManagementController;
use App\Http\Controllers\Admin\Finance\AdminPaymentExportController;
use App\Http\Controllers\Admin\Finance\AdminPaymentOperationsController;
use App\Http\Controllers\Admin\Finance\AdminPayoutController;
use App\Http\Controllers\Admin\Finance\AdminRefundController;
use App\Http\Controllers\Admin\Kyc\AdminKycBadgeController;
use App\Http\Controllers\Admin\Kyc\AdminKycDocumentController;
use App\Http\Controllers\Admin\Kyc\AdminKycQueueController;
use App\Http\Controllers\Admin\Listing\AdminListingControlController;
use App\Http\Controllers\Admin\Listing\AdminListingDuplicateController;
use App\Http\Controllers\Admin\Listing\AdminListingExportController;
use App\Http\Controllers\Admin\Listing\AdminListingVisitController;
use App\Http\Controllers\Admin\Moderation\AdminCopyrightController;
use App\Http\Controllers\Admin\Moderation\AdminModerationAppealController;
use App\Http\Controllers\Admin\Moderation\AdminModerationEvidenceController;
use App\Http\Controllers\Admin\Moderation\AdminModerationFilterController;
use App\Http\Controllers\Admin\Moderation\AdminModerationQueueController;
use App\Http\Controllers\Admin\Moderation\AdminModerationRuleController;
use App\Http\Controllers\Admin\Moderation\AdminModerationSoundController;
use App\Http\Controllers\Admin\Moderation\AdminModerationTrendController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\Shell\AdminNotificationController;
use App\Http\Controllers\Admin\Shell\AdminSearchController;
use App\Http\Controllers\Admin\Shell\AdminShellController;
use App\Http\Controllers\Admin\Shell\AdminWorkspaceController;
use App\Http\Controllers\Admin\SubscriptionCatalogController;
use App\Http\Controllers\Admin\SubscriptionManagementController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Agent\AgentDirectoryController;
use App\Http\Controllers\Agent\AgentInvitationController;
use App\Http\Controllers\Agent\AgentPortfolioController;
use App\Http\Controllers\Auth\AccountStateController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SecondFactorController;
use App\Http\Controllers\Auth\SigninController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Boost\BoostableContentController;
use App\Http\Controllers\Boost\BoostEstimateController;
use App\Http\Controllers\Boost\CampaignController;
use App\Http\Controllers\Boost\CreditWalletController;
use App\Http\Controllers\Content\LinkResolverController;
use App\Http\Controllers\Credits\CreditPurchaseController;
use App\Http\Controllers\Credits\CreditTransactionController;
use App\Http\Controllers\Geo\CityController;
use App\Http\Controllers\Geo\CountryController;
use App\Http\Controllers\Geo\CurrencyController;
use App\Http\Controllers\Geo\NeighbourhoodController;
use App\Http\Controllers\Geo\OperatorController;
use App\Http\Controllers\Housing\HousingSearchController;
use App\Http\Controllers\Landlord\LandlordDelegationController;
use App\Http\Controllers\Landlord\LandlordKycController;
use App\Http\Controllers\Landlord\LandlordPropertyAnalyticsController;
use App\Http\Controllers\Landlord\LandlordPropertyController;
use App\Http\Controllers\Legal\DataExportController;
use App\Http\Controllers\Legal\LegalDocumentController;
use App\Http\Controllers\Legal\TermsAcceptanceController;
use App\Http\Controllers\Listing\ListingController;
use App\Http\Controllers\Listing\ListingDraftController;
use App\Http\Controllers\Listing\ListingMapController;
use App\Http\Controllers\Listing\ListingMediaController;
use App\Http\Controllers\Listing\ListingSaveController;
use App\Http\Controllers\Media\MediaUploadController;
use App\Http\Controllers\Message\MessageController;
use App\Http\Controllers\Message\MessageGroupController;
use App\Http\Controllers\Message\MessageRequestController;
use App\Http\Controllers\Message\MessageThreadController;
use App\Http\Controllers\Message\VoiceMessageController;
use App\Http\Controllers\Notification\DeviceTokenController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Otp\OtpController;
use App\Http\Controllers\Page\Admin\PageCertificationAdminController;
use App\Http\Controllers\Page\PageCertificationController;
use App\Http\Controllers\Page\PageController;
use App\Http\Controllers\Page\PageMemberController;
use App\Http\Controllers\Page\PageSearchController;
use App\Http\Controllers\Payment\DoubleChargeClaimController;
use App\Http\Controllers\Payment\MonetbilWebhookController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Payment\PaymentReceiptController;
use App\Http\Controllers\Payment\SavedPaymentMethodController;
use App\Http\Controllers\Profile\AccountController;
use App\Http\Controllers\Profile\AvatarController;
use App\Http\Controllers\Profile\BlockController;
use App\Http\Controllers\Profile\ContactMatchingController;
use App\Http\Controllers\Profile\EmailController;
use App\Http\Controllers\Profile\FollowController;
use App\Http\Controllers\Profile\FollowRequestController;
use App\Http\Controllers\Profile\HandleCheckController;
use App\Http\Controllers\Profile\HandleSuggestionController;
use App\Http\Controllers\Profile\PasswordController;
use App\Http\Controllers\Profile\PrivacyController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\QrController;
use App\Http\Controllers\Profile\SecurityController;
use App\Http\Controllers\Reel\CloudflareStreamWebhookController;
use App\Http\Controllers\Reel\ReelCommentController;
use App\Http\Controllers\Reel\ReelFeedController;
use App\Http\Controllers\Reel\ReelInteractionController;
use App\Http\Controllers\Reel\SoundController;
use App\Http\Controllers\Reel\StudioReelController;
use App\Http\Controllers\Search\HashtagSearchController;
use App\Http\Controllers\Search\PeopleSearchController;
use App\Http\Controllers\Search\PlaceSearchController;
use App\Http\Controllers\Search\PostSearchController;
use App\Http\Controllers\Search\TrendsController;
use App\Http\Controllers\Settings\BackupCodeController;
use App\Http\Controllers\Settings\TwoFactorTotpController;
use App\Http\Controllers\Square\PostArchiveController;
use App\Http\Controllers\Square\PostAudienceController;
use App\Http\Controllers\Square\PostCaptionController;
use App\Http\Controllers\Square\PostCommentController;
use App\Http\Controllers\Square\PostCommentReactionController;
use App\Http\Controllers\Square\PostCommentsStatusController;
use App\Http\Controllers\Square\PostController;
use App\Http\Controllers\Square\PostFeedController;
use App\Http\Controllers\Square\PostLikeController;
use App\Http\Controllers\Square\PostShareController;
use App\Http\Controllers\Story\CloseListController;
use App\Http\Controllers\Story\HighlightController;
use App\Http\Controllers\Story\StoryController;
use App\Http\Controllers\Subscription\CatalogController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Subscription\SubscriptionRenewalController;
use App\Http\Controllers\Support\AdminSupportTicketController;
use App\Http\Controllers\Support\HelpArticleController;
use App\Http\Controllers\Support\HelpArticleFeedbackController;
use App\Http\Controllers\Support\ModerationAppealController;
use App\Http\Controllers\Support\ReportController;
use App\Http\Controllers\Support\SupportTicketController;
use App\Http\Controllers\Sync\OfflineSyncController;
use App\Http\Controllers\System\AppVersionController;
use App\Http\Controllers\Upload\TusUploadController;
use App\Http\Controllers\Visit\LandlordVisitController;
use App\Http\Controllers\Visit\ProspectVisitController;
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
    // Authentification sociale (Google OAuth & Apple Sign In)
    Route::post('/provider', SocialAuthController::class);

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

// Suggestions automatiques de pseudonymes disponibles (AU-15 / AU-16)
Route::post('/profile/handle-suggestions', HandleSuggestionController::class)->name('api.profile.handle-suggestions');

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
// AGENTS — ANNUAIRE PUBLIC & RECHERCHE (AG-06)
// --------------------------------------------------------------------------
Route::get('/agents/search', [AgentDirectoryController::class, 'search'])->name('api.agents.search');

// --------------------------------------------------------------------------
// WEBHOOKS (Publics avec contrôle d'intégrité et signature cryptographique)
// --------------------------------------------------------------------------
Route::prefix('webhooks/payments')->group(function () {
    // Webhook IPN Monetbil (vérification de signature MD5 timing-safe)
    Route::post('/monetbil', [MonetbilWebhookController::class, 'handle'])->name('api.webhooks.payments.monetbil');
});

// Webhook Cloudflare Stream pour le suivi asynchrone du transcodage vidéo (RL / CR2)
Route::post('/webhooks/cloudflare-stream', [CloudflareStreamWebhookController::class, 'handle'])->name('api.webhooks.cloudflare_stream');

// --------------------------------------------------------------------------
// IMMO & LISTINGS (Consultation Publique & Recherche — Module IM)
// --------------------------------------------------------------------------
Route::prefix('listings')->group(function () {
    // Feed immobilier vertical paginé avec filtres (IM-00a)
    // Ressource : Listing | Accès : Public (avec enrichissement pour utilisateur connecté)
    Route::get('/', [ListingController::class, 'index'])->name('api.listings.index');

    // Compteur dynamique d'annonces correspondant aux filtres actifs (IM-03 / SY-08)
    // Ressource : Listing | Accès : Public | Garantit la stricte concordance avec le feed
    Route::get('/count', [ListingController::class, 'count'])->name('api.listings.count');

    // Recherche géographique sur carte avec clustering adaptatif selon le zoom (IM-02)
    // Ressource : Listing & Clusters | Accès : Public | Exclut les annonces non publiées
    Route::get('/map', [ListingMapController::class, 'index'])->name('api.listings.map');

    // Fiche détaillée d'une annonce immobilière et ventilation des frais d'entrée (IM-05)
    // Ressource : Listing | Accès : Public (les données privées d'adresse restent masquées aux tiers)
    Route::get('/{id}', [ListingController::class, 'show'])->whereUuid('id')->name('api.listings.show');

    // Créneaux disponibles pour une annonce immobilière (IM-05 / slots.tsx)
    // Ressource : VisitSlot (grille 5 jours, capacité, verrous de 15min déduits) | Accès : Public
    Route::get('/{id}/slots', [ProspectVisitController::class, 'slots'])->whereUuid('id')->name('api.listings.slots');
});

// --------------------------------------------------------------------------
// MODULE 12 : PG — PAGES PROFESSIONNELLES (Consultation & Recherche Publiques)
// --------------------------------------------------------------------------
Route::get('/search/pages', [PageSearchController::class, 'index'])->name('api.search.pages');
Route::get('/pages/{page}/listings', [PageController::class, 'listings'])->name('api.pages.listings');
Route::get('/pages/{identifier}', [PageController::class, 'show'])->where('identifier', '^(?!my-pages$)[a-zA-Z0-9_\-]+$')->name('api.pages.show');

// --------------------------------------------------------------------------
// MODULE 3 : LG — CMS DOCUMENTS LÉGAUX VERSIONNÉS
// --------------------------------------------------------------------------
Route::get('/legal/documents/{slug}', [LegalDocumentController::class, 'show'])->name('api.legal.documents.show');

// --------------------------------------------------------------------------
// MODULE EDGE : RÉSOLUTION DES LIENS UNIVERSELS & COURTS
// --------------------------------------------------------------------------
Route::get('/content/resolve-link', [LinkResolverController::class, 'resolve'])->name('api.content.resolve-link');

// --------------------------------------------------------------------------
// MODULE 18 : SUP — CENTRE D’AIDE FAQ & RETOUR D'UTILITÉ
// --------------------------------------------------------------------------
Route::prefix('help/articles')->group(function () {
    Route::get('/', [HelpArticleController::class, 'index'])->name('api.help.articles.index');
    Route::get('/{slug}', [HelpArticleController::class, 'show'])->name('api.help.articles.show');
    Route::post('/{id}/feedback', [HelpArticleFeedbackController::class, 'feedback'])->name('api.help.articles.feedback');
});

// --------------------------------------------------------------------------
// MODULE 19 : SY — PROTOCOLE TUS v1.0.0 (DÉCOUVERTE PUBLIQUE)
// --------------------------------------------------------------------------
Route::options('/uploads/tus', [TusUploadController::class, 'options'])->name('api.uploads.tus.options');
Route::options('/uploads/tus/{id}', [TusUploadController::class, 'options'])->name('api.uploads.tus.options.id');

// --------------------------------------------------------------------------
// MODULE SP / SY-21 : CONTRÔLE DE VERSION & MISE À JOUR REQUISE
// --------------------------------------------------------------------------
Route::get('/system/version', AppVersionController::class)->name('api.system.version');

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
        // Consulter les paramètres de confidentialité du profil
        Route::get('/privacy', [PrivacyController::class, 'show']);
        // Mettre à jour les paramètres de confidentialité du profil
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
    // FRIENDS & CARNET D'ADRESSES (AU-19)
    // --------------------------------------------------------------------------
    Route::post('/friends/match-fingerprints', [ContactMatchingController::class, 'match'])->name('api.friends.match-fingerprints');

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
    // CŒUR IMMOBILIER — CR4 (Création, Brouillons, Médias, Favoris & Gestion)
    // --------------------------------------------------------------------------
    Route::prefix('listings')->group(function () {
        // Enregistrer ou mettre à jour un brouillon d'annonce (CR-40 à CR-48)
        // Ressource : Listing (draft) | Accès : Authentifié (propriétaire uniquement)
        Route::post('/form/draft', [ListingDraftController::class, 'store'])->name('api.listings.draft.store');

        // Consulter ou reprendre le brouillon actif de l'utilisateur connecté
        // Ressource : Listing (draft) | Accès : Authentifié | Retourne null si aucun brouillon
        Route::get('/form/draft', [ListingDraftController::class, 'show'])->name('api.listings.draft.show');

        // Abandonner et purger le brouillon actif (fermeture de l'assistant)
        // Ressource : Listing (draft) | Accès : Authentifié (propriétaire)
        Route::delete('/form/draft', [ListingDraftController::class, 'destroy'])->name('api.listings.draft.destroy');

        // Liste paginée des annonces sauvegardées en favoris par l'utilisateur (IM-16)
        // Ressource : ListingSave | Accès : Authentifié | Isole strictement les favoris par utilisateur
        Route::get('/saved', [ListingSaveController::class, 'index'])->name('api.listings.saved.index');

        // Téléversement d'un média temporaire autonome pour l'assistant de publication (CR-42)
        // Ressource : Fichier temporaire | Accès : Authentifié | Validation stricte MIME/taille/ratio
        Route::post('/media', [ListingMediaController::class, 'uploadTemp'])->name('api.listings.media.uploadTemp');

        // Créer et soumettre une annonce en modération (CR-39 à CR-48)
        // Ressource : Listing | Accès : Authentifié + Badge vérifié (bailleur, agent, double) ou Admin
        Route::post('/', [ListingController::class, 'store'])->name('api.listings.store');

        // Sauvegarder ou basculer l'état favori d'une annonce (idempotent, IM-16)
        // Ressource : ListingSave | Accès : Authentifié | Empêche les doublons concurrents
        Route::post('/{id}/save', [ListingSaveController::class, 'store'])->whereUuid('id')->name('api.listings.save.store');

        // Retirer explicitement une annonce des favoris
        // Ressource : ListingSave | Accès : Authentifié | Idempotent
        Route::delete('/{id}/save', [ListingSaveController::class, 'destroy'])->whereUuid('id')->name('api.listings.save.destroy');

        // Mettre à jour les informations (titre, prix, charges) sans relancer l'examen vidéo (CR-48)
        // Ressource : Listing | Accès : Authentifié (propriétaire, agent délégué ou admin)
        Route::patch('/{id}', [ListingController::class, 'update'])->whereUuid('id')->name('api.listings.update');

        // Retirer / supprimer une annonce de la plateforme (EDGE-03)
        // Ressource : Listing | Accès : Authentifié (propriétaire ou admin)
        Route::delete('/{id}', [ListingController::class, 'destroy'])->whereUuid('id')->name('api.listings.destroy');

        // Modifier le statut d'une annonce (BL-18 : published, reserved, taken)
        // Ressource : Listing | Accès : Authentifié (propriétaire, agent délégué ou admin)
        Route::patch('/{id}/status', [ListingController::class, 'updateStatus'])->whereUuid('id')->name('api.listings.status');

        // Téléverser et rattacher une photo ou visite vidéo à une annonce
        // Ressource : ListingMedia | Accès : Authentifié (propriétaire ou agent délégué)
        Route::post('/{id}/media', [ListingMediaController::class, 'store'])->whereUuid('id')->name('api.listings.media.store');

        // Réordonner la liste des médias d'une annonce (CR-43)
        // Ressource : ListingMedia | Accès : Authentifié (propriétaire ou agent délégué)
        Route::patch('/{id}/media/order', [ListingMediaController::class, 'reorder'])->whereUuid('id')->name('api.listings.media.order');

        // Supprimer un média associé à une annonce et purger le fichier physique
        // Ressource : ListingMedia | Accès : Authentifié (propriétaire ou agent délégué)
        Route::delete('/{id}/media/{mediaId}', [ListingMediaController::class, 'destroy'])->whereUuid('id')->whereUuid('mediaId')->name('api.listings.media.destroy');

        // Poser une réservation avec verrou temporaire de 15 minutes et transaction PAY (IM-05 / booking.tsx)
        // Ressource : VisitReservation | Accès : Authentifié (prospect) | Transactionnel et atomique
        Route::post('/{id}/visits', [ProspectVisitController::class, 'book'])->whereUuid('id')->name('api.listings.visits.book');
    });

    // --------------------------------------------------------------------------
    // IM — GESTION DES VISITES DU PROSPECT (IM-06 / visit.tsx, visits.tsx)
    // --------------------------------------------------------------------------
    Route::prefix('visits')->group(function () {
        // Historique paginé des visites de l'utilisateur connecté
        Route::get('/mine', [ProspectVisitController::class, 'mine'])->name('api.visits.mine');

        // Consultation détaillée d'une réservation de visite
        Route::get('/{id}', [ProspectVisitController::class, 'show'])->whereUuid('id')->name('api.visits.show');

        // Confirmation post-paiement côté client (vérification de preuve serveur)
        Route::post('/{id}/confirm', [ProspectVisitController::class, 'confirm'])->whereUuid('id')->name('api.visits.confirm');

        // Annuler une visite (avec application de la politique de remboursement 24h IM-14)
        Route::post('/{id}/cancel', [ProspectVisitController::class, 'cancel'])->whereUuid('id')->name('api.visits.cancel');

        // Répondre à une proposition de créneau alternatif par le bailleur
        Route::post('/{id}/proposal/respond', [ProspectVisitController::class, 'respondToProposal'])->whereUuid('id')->name('api.visits.proposal.respond');

        // Télécharger ou consulter le reçu officiel de visite
        Route::get('/{id}/receipt', [ProspectVisitController::class, 'receipt'])->whereUuid('id')->name('api.visits.receipt');
    });

    // --------------------------------------------------------------------------
    // BL — ESPACE BAILLEUR (BL-01, BL-02, BL-04, BL-07, BL-18, AG-02, AG-03)
    // --------------------------------------------------------------------------
    Route::prefix('landlord')->group(function () {
        // KYC Bailleur (accessible à tout utilisateur authentifié pour soumission de dossier)
        Route::post('/kyc/submit', [LandlordKycController::class, 'submit'])->name('api.landlord.kyc.submit');
        Route::get('/kyc', [LandlordKycController::class, 'show'])->name('api.landlord.kyc.show');
        Route::get('/kyc/documents/{id}', [LandlordKycController::class, 'downloadDocument'])->whereUuid('id')->name('api.landlord.kyc.download');

        // Actions réservées au bailleur certifié avec badge actif
        Route::middleware('subscribed:landlord')->group(function () {
            // Tableau de bord des biens possédés par le bailleur (BL-01)
            Route::get('/properties', [LandlordPropertyController::class, 'index'])->name('api.landlord.properties.index');

            // Inviter un agent pour un bien déterminé (AG-02)
            Route::post('/delegations', [LandlordDelegationController::class, 'store'])->name('api.landlord.delegations.store');
        });

        // Changement de statut d'un bien (BL-18 : published, reserved, taken)
        Route::patch('/properties/{id}/status', [LandlordPropertyController::class, 'updateStatus'])->whereUuid('id')->name('api.landlord.properties.status');

        // Gestion globale ou par lot des créneaux de visite (BL-04 / BL-08)
        Route::put('/properties/{id}/slots', [LandlordPropertyController::class, 'updateSlots'])->whereUuid('id')->name('api.landlord.properties.slots.update');

        // Consulter la liste des créneaux configurés pour un bien (BL-04)
        Route::get('/properties/{id}/slots', [LandlordVisitController::class, 'slots'])->whereUuid('id')->name('api.landlord.properties.slots');

        // Créer un créneau de disponibilité personnalisé pour un bien (BL-04)
        Route::post('/properties/{id}/slots', [LandlordVisitController::class, 'createSlot'])->whereUuid('id')->name('api.landlord.properties.slots.create');

        // Fermer un créneau de disponibilité (BL-04)
        Route::delete('/properties/{id}/slots/{slotId}', [LandlordVisitController::class, 'closeSlot'])->whereUuid('id')->whereUuid('slotId')->name('api.landlord.properties.slots.close');

        // Consulter toutes les demandes de visite entrantes du bailleur (BL-07)
        Route::get('/visit-requests', [LandlordPropertyController::class, 'visitRequests'])->name('api.landlord.visit_requests');

        // Consulter les demandes et réservations de visite d'un bien spécifique (BL-07)
        Route::get('/properties/{id}/visit-requests', [LandlordVisitController::class, 'visitRequests'])->whereUuid('id')->name('api.landlord.properties.visit_requests');

        // Accepter, proposer une alternative ou décliner une visite (BL-07 / AG-03)
        Route::post('/visits/{id}/answer', [LandlordVisitController::class, 'answer'])->whereUuid('id')->name('api.landlord.visits.answer');

        // Délégations émises par le bailleur
        Route::get('/delegations', [LandlordDelegationController::class, 'index'])->name('api.landlord.delegations.index');
        Route::delete('/delegations/{id}', [LandlordDelegationController::class, 'destroy'])->whereUuid('id')->name('api.landlord.delegations.destroy');

        // Analytique immobilière chronologique sur 30 jours (BL-19 / Étape 10)
        Route::get('/properties/{id}/analytics', [LandlordPropertyAnalyticsController::class, 'show'])->whereUuid('id')->name('api.landlord.properties.analytics');
    });

    // --------------------------------------------------------------------------
    // AG — ESPACE AGENT IMMOBILIER (AG-01, AG-04, AG-05)
    // --------------------------------------------------------------------------
    Route::prefix('agent')->group(function () {
        // Invitations reçues par l'agent
        Route::get('/invitations', [AgentInvitationController::class, 'index'])->name('api.agent.invitations.index');
        Route::post('/invitations/{id}/accept', [AgentInvitationController::class, 'accept'])->whereUuid('id')->name('api.agent.invitations.accept');
        Route::post('/invitations/{id}/reject', [AgentInvitationController::class, 'reject'])->whereUuid('id')->name('api.agent.invitations.reject');

        // Actions réservées à l'agent avec badge actif
        Route::middleware('subscribed:agent')->group(function () {
            // Portefeuille des biens délégués à l'agent (AG-01)
            Route::get('/properties', [AgentPortfolioController::class, 'properties'])->name('api.agent.properties');

            // Contacts et prospects liés aux missions actives (AG-05)
            Route::get('/prospects', [AgentPortfolioController::class, 'prospects'])->name('api.agent.prospects');
        });
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
        // Consulter les options de confidentialité du compte
        Route::get('/privacy', [PrivacyController::class, 'show']);
        // Mettre à jour les options de confidentialité du compte
        Route::put('/privacy', [PrivacyController::class, 'update']);

        // Bouton d'urgence : Sécuriser le compte (déconnecter autres appareils)
        Route::post('/secure', [SecurityController::class, 'secureAccount']);
        // Afficher la liste de tous les appareils connectés au compte
        Route::get('/sessions', [SecurityController::class, 'getSessions']);
        // Déconnecter un appareil / session spécifique
        Route::delete('/sessions/{id}', [SecurityController::class, 'revokeSession']);
        // Valider/Reconnaître manuellement un appareil ou une session
        Route::post('/sessions/{id}/acknowledge', [SecurityController::class, 'acknowledgeSession']);

        // Double Authentification TOTP (ST-09 / ST-10)
        Route::prefix('2fa')->group(function () {
            Route::post('/totp/setup', [TwoFactorTotpController::class, 'setup'])->name('api.settings.2fa.totp.setup');
            Route::post('/totp/verify', [TwoFactorTotpController::class, 'verify'])->name('api.settings.2fa.totp.verify');
            Route::post('/disable', [TwoFactorTotpController::class, 'disable'])->name('api.settings.2fa.disable');

            // Codes de secours à usage unique (ST-10)
            Route::get('/backup-codes', [BackupCodeController::class, 'index'])->name('api.settings.2fa.backup-codes.index');
            Route::post('/backup-codes', [BackupCodeController::class, 'regenerate'])->name('api.settings.2fa.backup-codes.generate');
            Route::post('/backup-codes/regenerate', [BackupCodeController::class, 'regenerate'])->name('api.settings.2fa.backup-codes.regenerate');
        });

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
        // Obtenir les informations d'éligibilité au renouvellement d'un abonnement
        Route::get('/{id}/renewal', [SubscriptionRenewalController::class, 'renewalInfo']);
        // Émettre un devis certifié serveur pour le renouvellement d'un abonnement
        Route::post('/{id}/renewal/quote', [SubscriptionRenewalController::class, 'quote']);
        // Confirmer le renouvellement manuel assisté d'un abonnement
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

        // ----------------------------------------------------------------------
        // Moyens de paiement enregistrés (Étape 10)
        // ----------------------------------------------------------------------
        Route::get('/methods', [SavedPaymentMethodController::class, 'index'])->name('api.payments.methods.index');
        Route::post('/methods', [SavedPaymentMethodController::class, 'store'])->name('api.payments.methods.store');
        Route::patch('/methods/{id}/default', [SavedPaymentMethodController::class, 'setDefault'])->name('api.payments.methods.default');
        Route::delete('/methods/{id}', [SavedPaymentMethodController::class, 'destroy'])->name('api.payments.methods.destroy');

        // ----------------------------------------------------------------------
        // Déclaration d'incidents de double débit (Étape 10)
        // ----------------------------------------------------------------------
        Route::post('/double-charge-claims', [DoubleChargeClaimController::class, 'store'])->name('api.payments.double-charge-claims.store');
        Route::get('/double-charge-claims/mine', [DoubleChargeClaimController::class, 'mine'])->name('api.payments.double-charge-claims.mine');

        // Obtenir le statut en temps réel d'un paiement (polling 120s / reprise réseau)
        Route::get('/{id}/status', [PaymentController::class, 'status']);
        // Télécharger le reçu PDF officiel d'une transaction (Étape 10)
        Route::get('/{id}/receipt', [PaymentReceiptController::class, 'downloadReceipt'])->name('api.payments.receipt.download');
        // Annuler un paiement en attente
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel']);
        // Consulter l'historique paginé des transactions du client
        Route::get('/history', [PaymentController::class, 'history']);
    });

    // --------------------------------------------------------------------------
    // SQUARE — CR1 (Création, Médias, Carrousel, Recherche, Feed & Actions SQ-15)
    // --------------------------------------------------------------------------
    Route::get('/square/feed', [PostFeedController::class, 'index'])->name('api.square.feed.alias');

    Route::prefix('square-cr1')->group(function () {
        // Fil Square paginé (CR-11 et flux découverte, abonnés, voisinage)
        Route::get('/square/feed', [PostFeedController::class, 'index'])->name('api.square.feed');
        Route::get('/feed', [PostFeedController::class, 'index']);

        // Créer une nouvelle publication avec texte, médias, tags, lieu et options (CR-01 à CR-12)
        Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');

        // Afficher les détails d'une publication spécifique selon les règles d'audience
        Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show');

        // Supprimer une publication (SQ-15, réservé à l'auteur)
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('api.posts.destroy');

        // Modifier la légende d'une publication (SQ-15, max 2 200 caractères, réservé à l'auteur)
        Route::patch('/posts/{post}/caption', [PostCaptionController::class, 'update'])->name('api.posts.caption');

        // Modifier la visibilité et l'audience d'une publication (SQ-15 : public, followers, neighbourhood)
        Route::patch('/posts/{post}/audience', [PostAudienceController::class, 'update'])->name('api.posts.audience');

        // Modifier les options de commentaires (SQ-15 : fermer/ouvrir, filtrer par audience)
        Route::patch('/posts/{post}/comments-status', [PostCommentsStatusController::class, 'update'])->name('api.posts.comments_status');

        // Archiver une publication pour la masquer des flux publics (SQ-15, réservé à l'auteur)
        Route::post('/posts/{post}/archive', [PostArchiveController::class, 'archive'])->name('api.posts.archive');

        // Désarchiver une publication pour rétablir sa visibilité publique (SQ-15, réservé à l'auteur)
        Route::post('/posts/{post}/unarchive', [PostArchiveController::class, 'unarchive'])->name('api.posts.unarchive');

        // Likes des publications (SQ - Interactions)
        // Ajouter un like sur une publication
        Route::post('/posts/{post}/like', [PostLikeController::class, 'store'])->name('api.posts.like');
        // Retirer un like sur une publication
        Route::delete('/posts/{post}/like', [PostLikeController::class, 'destroy'])->name('api.posts.unlike');
        // Lister les personnes ayant aimé une publication (SQ-14)
        Route::get('/posts/{post}/likers', [PostLikeController::class, 'likers'])->name('api.posts.likers');

        // Partages des publications (SQ - Interactions)
        // Enregistrer un partage de publication (incrément atomique et idempotence)
        Route::post('/posts/{post}/share', [PostShareController::class, 'store'])->name('api.posts.share');

        // Commentaires des publications (SQ - Interactions)
        // Lister les commentaires paginés d'une publication
        Route::get('/posts/{post}/comments', [PostCommentController::class, 'index'])->name('api.posts.comments.index');
        // Ajouter un commentaire sur une publication
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store'])->name('api.posts.comments.store');

        // Réponses et gestion des commentaires (SQ - Interactions)
        // Lister les réponses à un commentaire
        Route::get('/comments/{comment}/replies', [PostCommentController::class, 'replies'])->name('api.comments.replies');
        // Modifier le texte d'un commentaire (réservé à son auteur)
        Route::patch('/comments/{comment}', [PostCommentController::class, 'update'])->name('api.comments.update');
        // Supprimer un commentaire (réservé à l'auteur du commentaire ou du post)
        Route::delete('/comments/{comment}', [PostCommentController::class, 'destroy'])->name('api.comments.destroy');

        // Réactions sur les commentaires (SQ - Likes / Dislikes)
        // Définir ou modifier une réaction (like/dislike) sur un commentaire
        Route::put('/comments/{comment}/reaction', [PostCommentReactionController::class, 'react'])->name('api.comments.reaction.set');
        // Supprimer sa réaction sur un commentaire
        Route::delete('/comments/{comment}/reaction', [PostCommentReactionController::class, 'destroy'])->name('api.comments.reaction.destroy');

        // Téléverser un média photo ou vidéo sécurisé pour le carrousel Square (CR-02, CR-04, CR-12)
        Route::post('/media/upload', [MediaUploadController::class, 'upload'])->name('api.media.upload');

        // Rechercher des publications Square par recherche plein texte (RC-06)
        Route::get('/search/posts', [PostSearchController::class, 'index']);

        // Rechercher des personnes pour les identifier sur une photo ou les mentionner (CR-08)
        Route::get('/search/people', [PeopleSearchController::class, 'index'])->name('api.search.people');

        // Rechercher des hashtags par correspondance textuelle et volume (CR-07)
        Route::get('/search/hashtags', [HashtagSearchController::class, 'trending']);
        Route::get('/search/hashtags/trending', [HashtagSearchController::class, 'trending'])->name('api.search.hashtags.trending');

        // Rechercher des lieux contextuels réels issus de la base géographique BANGO (CR-09)
        Route::get('/search/places', [PlaceSearchController::class, 'index'])->name('api.search.places');
    });

    // --------------------------------------------------------------------------
    // MODULE RC — RECHERCHE GLOBALE ET DÉCOUVERTE (Posts, Hashtags, Tendances)
    // --------------------------------------------------------------------------
    Route::prefix('search')->group(function () {
        Route::get('/posts', [PostSearchController::class, 'index'])->name('api.search.posts');
        Route::get('/hashtags', [HashtagSearchController::class, 'index'])->name('api.search.hashtags');
    });

    // Tendances unifiées BANGO (RC-11 : Top hashtags, musiques du moment, créateurs émergents)
    Route::get('/trends', [TrendsController::class, 'index'])->name('api.trends');

    // --------------------------------------------------------------------------
    // CR2 & RL — STUDIO REEL, REELS ET SONS (Actions et consultation authentifiées)
    // --------------------------------------------------------------------------
    Route::prefix('reels')->group(function () {
        // Fil vertical des Reels (RL-01, RL-08 Découvrir)
        Route::get('/feed', [ReelFeedController::class, 'feed'])->name('api.reels.feed');

        // Studio Reel : Brouillons & Reprise (CR-14, CR-17 à CR-29)
        // Créer ou mettre à jour un brouillon de Reel avec sa composition
        Route::post('/drafts', [StudioReelController::class, 'saveDraft'])->name('api.reels.drafts.save');
        // Lister tous les brouillons de l'utilisateur connecté
        Route::get('/drafts', [StudioReelController::class, 'listDrafts'])->name('api.reels.drafts.index');
        // Obtenir les détails d'un brouillon spécifique pour reprise de montage
        Route::get('/drafts/{id}', [StudioReelController::class, 'getDraft'])->name('api.reels.drafts.show');
        // Supprimer un brouillon et nettoyer ses fichiers physiques associés
        Route::delete('/drafts/{id}', [StudioReelController::class, 'deleteDraft'])->name('api.reels.drafts.destroy');

        // Studio Reel : Téléversement médias (vidéos / couverture)
        // Téléverser un clip vidéo (caméra/galerie), un audio ou une couverture
        Route::post('/media/upload', [StudioReelController::class, 'uploadMedia'])->name('api.reels.media.upload');

        // Studio Reel : Lancement de la publication asynchrone (Queue Job)
        // Lancer la publication asynchrone d'un Reel (job de transcodage et publication)
        Route::post('/publish', [StudioReelController::class, 'publish'])->name('api.reels.publish');

        // Sons musicaux : Catalogue, favoris et détails
        // Lister les sons musicaux disponibles au catalogue
        Route::get('/sounds', [SoundController::class, 'index'])->name('api.reels.sounds.index');
        // Lister les sons musicaux sauvegardés dans les favoris de l'utilisateur
        Route::get('/sounds/user/favourites', [SoundController::class, 'favourites'])->name('api.reels.sounds.favourites');
        // Consulter les détails d'un son spécifique (catalogue ou son original)
        Route::get('/sounds/{id}', [SoundController::class, 'show'])->name('api.reels.sounds.show');
        // Lister tous les Reels utilisant un son audio spécifique
        Route::get('/sounds/{id}/reels', [SoundController::class, 'reels'])->name('api.reels.sounds.reels');
        // Ajouter ou retirer un son musical de ses favoris
        Route::post('/sounds/{id}/favourite', [SoundController::class, 'toggleFavourite'])->name('api.reels.sounds.favourite');
        // Verrouiller ou déverrouiller la réutilisation d'un son original (réservé au créateur)
        Route::put('/{id}/original-sound/lock', [SoundController::class, 'lockOriginal'])->name('api.reels.sounds.lock');

        // Vues et consultation individuelle
        // Enregistrement d'une vue (avec déduplication anti-abus 30 minutes et anonymisation IP)
        Route::post('/{reel}/view', [ReelInteractionController::class, 'view'])->whereUuid('reel')->name('api.reels.view');
        // Consulter un Reel individuel par son UUID
        Route::get('/{id}', [ReelFeedController::class, 'show'])->whereUuid('id')->name('api.reels.show');
        // Supprimer définitivement un Reel publié (réservé à l'auteur)
        Route::delete('/{reel}', [ReelFeedController::class, 'destroy'])->name('api.reels.destroy');

        // Interactions sociales : Likes & Dislikes (Exclusivité stricte)
        // Appliquer ou basculer une réaction (like ou dislike) sur un Reel
        Route::post('/{reel}/reaction', [ReelInteractionController::class, 'react'])->name('api.reels.reaction.set');
        // Retirer toute réaction active sur le Reel
        Route::delete('/{reel}/reaction', [ReelInteractionController::class, 'removeReaction'])->name('api.reels.reaction.destroy');
        // Ajouter ou basculer vers un Like sur le Reel
        Route::post('/{reel}/like', [ReelInteractionController::class, 'like'])->name('api.reels.like');
        // Retirer un Like sur le Reel
        Route::delete('/{reel}/like', [ReelInteractionController::class, 'unlike'])->name('api.reels.unlike');
        // Ajouter ou basculer vers un Dislike sur le Reel
        Route::post('/{reel}/dislike', [ReelInteractionController::class, 'dislike'])->name('api.reels.dislike');
        // Retirer un Dislike sur le Reel
        Route::delete('/{reel}/dislike', [ReelInteractionController::class, 'undislike'])->name('api.reels.undislike');

        // Partage (incrément atomique et idempotence)
        // Enregistrer un partage de Reel de manière atomique et idempotente
        Route::post('/{reel}/share', [ReelInteractionController::class, 'share'])->name('api.reels.share');

        // Favoris / Sauvegardes
        // Ajouter ou retirer un Reel de ses favoris (sauvegardes)
        Route::post('/{reel}/save', [ReelInteractionController::class, 'toggleSave'])->name('api.reels.save');
        // Lister tous les Reels sauvegardés dans les favoris de l'utilisateur
        Route::get('/user/saved', [ReelInteractionController::class, 'listSaved'])->name('api.reels.saved.list');

        // Pas intéressé (RL-06)
        // Masquer un Reel et le marquer comme « Ça ne m'intéresse pas »
        Route::post('/{reel}/not-interested', [ReelInteractionController::class, 'markNotInterested'])->name('api.reels.not_interested');

        // Commentaires & Réponses (RL-03)
        // Lister les commentaires de premier niveau d'un Reel
        Route::get('/{reel}/comments', [ReelCommentController::class, 'index'])->whereUuid('reel')->name('api.reels.comments.index');
        // Lister les réponses associées à un commentaire spécifique
        Route::get('/comments/{comment}/replies', [ReelCommentController::class, 'replies'])->name('api.reels.comments.replies');
        // Ajouter un commentaire ou une réponse sur un Reel
        Route::post('/{reel}/comments', [ReelCommentController::class, 'store'])->name('api.reels.comments.store');
        // Modifier le texte d'un commentaire de Reel (réservé à son auteur)
        Route::patch('/comments/{comment}', [ReelCommentController::class, 'update'])->name('api.reels.comments.update');
        // Supprimer un commentaire de Reel et ses réponses en cascade
        Route::delete('/comments/{comment}', [ReelCommentController::class, 'destroy'])->name('api.reels.comments.destroy');
        // Appliquer une réaction (like ou dislike) sur un commentaire de Reel
        Route::put('/comments/{comment}/reaction', [ReelCommentController::class, 'react'])->name('api.reels.comments.reaction.set');
        // Retirer sa réaction sur un commentaire de Reel
        Route::delete('/comments/{comment}/reaction', [ReelCommentController::class, 'removeReaction'])->name('api.reels.comments.reaction.destroy');
    });

    // --------------------------------------------------------------------------
    // CR3 — STORY (Mobile / Authentifié)
    // --------------------------------------------------------------------------
    Route::prefix('stories')->group(function () {
        // Rail horizontal des Stories actives accessibles (RL / CR3)
        Route::get('/rail', [StoryController::class, 'rail'])->name('api.stories.rail');

        // Récupérer les Stories actives de l'utilisateur connecté
        Route::get('/mine', [StoryController::class, 'mine'])->name('api.stories.mine');

        // Récupérer les Stories archivées de l'utilisateur connecté (historique)
        Route::get('/archived', [StoryController::class, 'archived'])->name('api.stories.archived');

        // Consulter la liste choisie (close friends) de l'utilisateur
        Route::get('/close-list', [CloseListController::class, 'index'])->name('api.stories.close_list.index');
        // Remplacer de manière atomique la liste choisie de l'utilisateur
        Route::put('/close-list', [CloseListController::class, 'update'])->name('api.stories.close_list.update');

        // Créer et publier une nouvelle Story avec médias, stickers et audio
        Route::post('/', [StoryController::class, 'store'])->name('api.stories.store');

        // Consulter le détail d'une Story personnelle avec statistiques (vues, votes, réponses)
        Route::get('/mine/{id}', [StoryController::class, 'myStory'])->whereUuid('id')->name('api.stories.mine.show');

        // Consulter la liste nominative des spectateurs d'une Story (réservé au propriétaire)
        Route::get('/{id}/viewers', [StoryController::class, 'viewers'])->whereUuid('id')->name('api.stories.viewers');

        // Récupérer les diapositives créatives associées à une Story
        Route::get('/{id}/slides', [StoryController::class, 'slides'])->whereUuid('id')->name('api.stories.slides');

        // Consulter une Story individuelle accessible par son UUID
        Route::get('/{id}', [StoryController::class, 'show'])->whereUuid('id')->name('api.stories.show');

        // Supprimer définitivement une Story de l'utilisateur connecté
        Route::delete('/{id}', [StoryController::class, 'destroy'])->whereUuid('id')->name('api.stories.destroy');

        // Enregistrer la consultation d'une Story (déduplication 1 vue par utilisateur)
        Route::post('/{id}/view', [StoryController::class, 'view'])->whereUuid('id')->name('api.stories.view');

        // Enregistrer un vote unique sur un sondage d'une diapositive
        Route::post('/{slideId}/poll/{stickerId}/vote', [StoryController::class, 'pollVote'])
            ->whereUuid('slideId')
            ->whereUuid('stickerId')
            ->name('api.stories.poll.vote');

        // Enregistrer une intention de réponse privée (préparée pour la future messagerie)
        Route::post('/{id}/reply', [StoryController::class, 'reply'])->whereUuid('id')->name('api.stories.reply');

        // Signaler une Story pour modération
        Route::post('/{id}/report', [StoryController::class, 'report'])->whereUuid('id')->name('api.stories.report');
    });

    // --------------------------------------------------------------------------
    // CR3 — COLLECTIONS « À LA UNE » (Highlights)
    // --------------------------------------------------------------------------
    Route::prefix('highlights')->group(function () {
        // Lister les collections À la une visibles d'un utilisateur
        Route::get('/', [HighlightController::class, 'index'])->name('api.highlights.index');

        // Créer une nouvelle collection À la une (titre limité à 20 caractères)
        Route::post('/', [HighlightController::class, 'store'])->name('api.highlights.store');

        // Consulter les détails d'une collection À la une avec ses Stories accessibles
        Route::get('/{id}', [HighlightController::class, 'show'])->whereUuid('id')->name('api.highlights.show');

        // Supprimer une collection À la une sans supprimer les Stories sous-jacentes
        Route::delete('/{id}', [HighlightController::class, 'destroy'])->whereUuid('id')->name('api.highlights.destroy');
    });

    // --------------------------------------------------------------------------
    // MS - MESSAGERIE PRIVÉE & GROUPES (Direct, Immo, Vocaux, Stories, Groupes)
    // --------------------------------------------------------------------------
    Route::prefix('messages')->group(function () {
        // Conversations & Fils de discussion
        Route::get('/threads', [MessageThreadController::class, 'index'])->name('api.messages.threads.index');
        Route::post('/threads', [MessageThreadController::class, 'store'])->name('api.messages.threads.store');
        Route::get('/threads/{id}', [MessageThreadController::class, 'show'])->name('api.messages.threads.show');
        Route::match(['post', 'put'], '/threads/{id}/read', [MessageThreadController::class, 'markAsRead'])->name('api.messages.threads.read');

        // Boîte des demandes de messages provenant d'inconnus (MS-05)
        Route::get('/requests', [MessageRequestController::class, 'index'])->name('api.messages.requests.index');
        Route::post('/requests/{id}/accept', [MessageRequestController::class, 'accept'])->name('api.messages.requests.accept');
        Route::post('/requests/{id}/reject', [MessageRequestController::class, 'reject'])->name('api.messages.requests.reject');

        // Messages d'un fil
        Route::get('/threads/{id}/messages', [MessageController::class, 'index'])->name('api.messages.messages.index');
        Route::post('/threads/{id}/messages', [MessageController::class, 'store'])->name('api.messages.messages.store');
        Route::post('/threads/{id}/messages/{messageId}/retract', [MessageController::class, 'retract'])->name('api.messages.messages.retract');
        Route::delete('/threads/{id}/messages/{messageId}', [MessageController::class, 'retract'])->name('api.messages.messages.destroy');

        // Notes vocales
        Route::post('/threads/{id}/voice', [VoiceMessageController::class, 'store'])->name('api.messages.voice.store');

        // Conversations de groupe
        Route::post('/groups', [MessageGroupController::class, 'store'])->name('api.messages.groups.store');
        Route::post('/groups/{id}/members', [MessageGroupController::class, 'addMembers'])->name('api.messages.groups.members.add');
        Route::delete('/groups/{id}/members/{userId}', [MessageGroupController::class, 'removeMember'])->name('api.messages.groups.members.remove');
    });

    // --------------------------------------------------------------------------
    // BO - BOOST & PUBLICITÉ (Campagnes, Estimation de portée, Crédit BANGO)
    // --------------------------------------------------------------------------
    Route::prefix('boost')->group(function () {
        // Contenus éligibles au boost et score de surperformance relative (BO)
        Route::get('/boostable', [BoostableContentController::class, 'index'])->name('api.boost.boostable');

        // Estimation de portée
        Route::post('/estimate', [BoostEstimateController::class, 'estimate'])->name('api.boost.estimate');

        // Portefeuille de Crédits BANGO
        Route::get('/wallet', [CreditWalletController::class, 'show'])->name('api.boost.wallet.show');
        Route::get('/wallet/transactions', [CreditWalletController::class, 'transactions'])->name('api.boost.wallet.transactions');
        Route::post('/wallet/deposit', [CreditWalletController::class, 'deposit'])->name('api.boost.wallet.deposit');

        // Campagnes
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('api.boost.campaigns.index');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('api.boost.campaigns.store');
        Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereUuid('id')->name('api.boost.campaigns.show');
        Route::post('/campaigns/{id}/fund', [CampaignController::class, 'fund'])->whereUuid('id')->name('api.boost.campaigns.fund');
        Route::post('/campaigns/{id}/pause', [CampaignController::class, 'pause'])->whereUuid('id')->name('api.boost.campaigns.pause');
        Route::post('/campaigns/{id}/resume', [CampaignController::class, 'resume'])->whereUuid('id')->name('api.boost.campaigns.resume');
        Route::post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel'])->whereUuid('id')->name('api.boost.campaigns.cancel');
        Route::post('/campaigns/{id}/impression', [CampaignController::class, 'trackImpression'])->whereUuid('id')->name('api.boost.campaigns.impression');
        Route::post('/campaigns/{id}/click', [CampaignController::class, 'trackClick'])->whereUuid('id')->name('api.boost.campaigns.click');
    });

    // --------------------------------------------------------------------------
    // CB - CRÉDIT BANGO (Portefeuille, Achat via PAY, Historique & Traçabilité)
    // --------------------------------------------------------------------------
    Route::prefix('credits')->group(function () {
        Route::get('/wallet', [App\Http\Controllers\Credits\CreditWalletController::class, 'show'])->name('api.credits.wallet.show');
        Route::post('/purchases', [CreditPurchaseController::class, 'store'])->name('api.credits.purchases.store');
        Route::get('/transactions', [CreditTransactionController::class, 'index'])->name('api.credits.transactions.index');
    });

    // --------------------------------------------------------------------------
    // MODULE 12 : PG — PAGES PROFESSIONNELLES (Espace Gestion, Membres, Certification)
    // --------------------------------------------------------------------------
    Route::prefix('pages')->group(function () {
        // Liste « Mes Pages » de l'utilisateur connecté
        Route::get('/my-pages', [PageController::class, 'myPages'])->name('api.pages.my_pages');
        // Création d'une nouvelle Page Professionnelle
        Route::post('/', [PageController::class, 'store'])->name('api.pages.store');
        // Mise à jour des informations, logo et couverture de la Page
        Route::put('/{page}', [PageController::class, 'update'])->name('api.pages.update');
        // Suppression (soft delete) d'une Page
        Route::delete('/{page}', [PageController::class, 'destroy'])->name('api.pages.destroy');
        // Transfert de propriété (EDGE-01)
        Route::post('/{page}/transfer', [PageController::class, 'transferOwnership'])->name('api.pages.transfer');
        // Bascule de contexte de session (PG-04)
        Route::post('/{page}/switch-context', [PageController::class, 'switchContext'])->name('api.pages.switch_context');
        // Boîte de réception dédiée de la Page (MS-19)
        Route::get('/{page}/inbox', [PageController::class, 'inbox'])->name('api.pages.inbox');

        // Membres & RBAC (PG-03)
        Route::get('/{page}/members', [PageMemberController::class, 'index'])->name('api.pages.members.index');
        Route::post('/{page}/members/invite', [PageMemberController::class, 'invite'])->name('api.pages.members.invite');
        Route::put('/{page}/members/{member}/role', [PageMemberController::class, 'updateRole'])->name('api.pages.members.update_role');
        Route::delete('/{page}/members/{member}', [PageMemberController::class, 'revoke'])->name('api.pages.members.revoke');
        Route::post('/{page}/leave', [PageMemberController::class, 'leave'])->name('api.pages.leave');

        // Dossiers de certification entreprise (PG-05 & PG-07)
        Route::get('/{page}/certification', [PageCertificationController::class, 'show'])->name('api.pages.certification.show');
        Route::get('/{page}/certification/history', [PageCertificationController::class, 'history'])->name('api.pages.certification.history');
        Route::post('/{page}/certification', [PageCertificationController::class, 'store'])->name('api.pages.certification.store');
        Route::post('/{page}/certification/payment', [PageCertificationController::class, 'initiatePayment'])->name('api.pages.certification.payment');
    });

    // Alias profil utilisateur pour retrouver ses pages
    Route::get('/me/pages', [PageController::class, 'myPages'])->name('api.me.pages');

    // Réponse aux invitations de membres
    Route::post('/page-members/{member}/accept', [PageMemberController::class, 'accept'])->name('api.page_members.accept');
    Route::post('/page-members/{member}/decline', [PageMemberController::class, 'decline'])->name('api.page_members.decline');

    // Corrections et téléchargement de documents de certification
    Route::post('/pages/certifications/{certification}/resubmit', [PageCertificationController::class, 'resubmit'])->name('api.pages.certifications.resubmit');
    Route::get('/pages/certifications/{certification}/documents/{key}', [PageCertificationController::class, 'downloadDocument'])->name('api.pages.certifications.documents.download');

    // --------------------------------------------------------------------------
    // MODULE 21 : NOTIF — NOTIFICATIONS IN-APP & DEVICE TOKENS PUSH
    // --------------------------------------------------------------------------
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('/read', [NotificationController::class, 'markAllAsRead'])->name('api.notifications.mark_all_read');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('api.notifications.mark_all_read_alias');
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('api.notifications.mark_read');
        Route::post('/device-token', [DeviceTokenController::class, 'store'])->name('api.notifications.device_token.store');
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy'])->name('api.notifications.device_token.destroy');
    });

    // --------------------------------------------------------------------------
    // MODULE 18 : SUP — SIGNALEMENTS UNIVERSELS & RECOURS DE MODÉRATION
    // --------------------------------------------------------------------------
    Route::prefix('reports')->group(function () {
        Route::post('/', [ReportController::class, 'store'])->name('api.reports.store');
        Route::get('/mine', [ReportController::class, 'mine'])->name('api.reports.mine');
    });

    Route::prefix('moderation/appeals')->group(function () {
        Route::post('/', [ModerationAppealController::class, 'store'])->name('api.moderation.appeals.store');
        Route::get('/', [ModerationAppealController::class, 'mine'])->name('api.moderation.appeals.mine');
    });

    // --------------------------------------------------------------------------
    // MODULE 18 : SUP — BILLETTERIE DE SUPPORT CLIENT
    // --------------------------------------------------------------------------
    Route::prefix('support/tickets')->group(function () {
        Route::post('/', [SupportTicketController::class, 'store'])->name('api.support.tickets.store');
        Route::get('/mine', [SupportTicketController::class, 'mine'])->name('api.support.tickets.mine');
        Route::get('/{id}', [SupportTicketController::class, 'show'])->name('api.support.tickets.show');
        Route::post('/{id}/messages', [SupportTicketController::class, 'reply'])->name('api.support.tickets.reply');
        Route::get('/{ticketId}/attachments/{attachmentId}', [SupportTicketController::class, 'downloadAttachment'])
            ->name('api.support.tickets.attachments.download');
    });

    // --------------------------------------------------------------------------
    // MODULE 19 : SY — SYNCHRONISATION HORS LIGNE ET RÉSILIENCE
    // --------------------------------------------------------------------------
    Route::post('/sync/flush', [OfflineSyncController::class, 'flush'])->name('api.sync.flush');

    // --------------------------------------------------------------------------
    // MODULE 19 : SY — TÉLÉVERSEMENT REPRENABLE PAR MORCEAUX (PROTOCOLE TUS v1.0.0)
    // --------------------------------------------------------------------------
    Route::prefix('uploads/tus')->group(function () {
        Route::post('/', [TusUploadController::class, 'create'])->name('api.uploads.tus.create');
        Route::match(['head', 'get'], '/{id}', [TusUploadController::class, 'head'])->name('api.uploads.tus.head');
        Route::patch('/{id}', [TusUploadController::class, 'patch'])->name('api.uploads.tus.patch');
        Route::delete('/{id}', [TusUploadController::class, 'delete'])->name('api.uploads.tus.delete');
        Route::post('/{id}/finalize', [TusUploadController::class, 'finalize'])->name('api.uploads.tus.finalize');
    });

    // --------------------------------------------------------------------------
    // MODULE 3 : LG — LÉGAL, RGPD & EXPORT DES DONNÉES PERSONNELLES
    // --------------------------------------------------------------------------
    Route::prefix('user/data-export')->group(function () {
        Route::post('/', [DataExportController::class, 'requestExport'])->name('api.user.data_export.request');
        Route::get('/status', [DataExportController::class, 'status'])->name('api.user.data_export.status');
        Route::get('/download/{id}', [DataExportController::class, 'download'])->name('api.user.data_export.download');
    });

    // Acceptation des conditions générales d'utilisation (LG-07)
    Route::post('/legal/accept-terms', [TermsAcceptanceController::class, 'store'])->name('api.legal.accept-terms');

});

/*
|==========================================================================
| 3. 🖥️ BANGO-ADMIN & BACK-OFFICE — ESPACE D'ADMINISTRATION CENTRALISÉ
|==========================================================================
|
| Cet espace regroupe l'intégralité des routes d'administration et de supervision
| de la plateforme BANGO, structurées et documentées par modules métiers :
|
| ├── MODULE 0 : SOCLE DE SÉCURITÉ, AUTHENTIFICATION 2FA & COQUE (Étape 1)
| ├── MODULE 1 : RÔLES & PERMISSIONS
| ├── MODULE 2 : GESTION DES UTILISATEURS
| ├── MODULE 3 : GESTION GÉOGRAPHIQUE (PAYS, DEVISES, VILLES, OPÉRATEURS)
| ├── MODULE 4 : CATALOGUE D'ABONNEMENTS & TARIFS
| ├── MODULE 5 : GESTION DES ABONNEMENTS & BADGES
| ├── MODULE 6 : PAIEMENTS, AUDITS & GESTION DES LITIGES
| ├── MODULE 7 : CATALOGUE AUDIO & SONS REELS
| ├── MODULE 8 : PAGES PROFESSIONNELLES & CERTIFICATIONS (PG)
| └── MODULE 9 : BILLETTERIE SUPPORT CLIENT (SUP)
|
|==========================================================================
*/

// ==========================================================================
// MODULE 0 : SOCLE DE SÉCURITÉ, 2FA & COQUE GLOBALE (BANGO-ADMIN ÉTAPE 1)
// ==========================================================================
Route::prefix('admin')->name('api.admin.')->group(function () {

    // ----------------------------------------------------------------------
    // 0.1 Authentification & Pré-session (Sans session préalable)
    // ----------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        // Obtenir le contexte de connexion (branding, statut 2FA requis, politique de sécurité)
        Route::get('/context', [AdminAuthController::class, 'context'])
            ->name('auth.context');

        // Initier la connexion opérateur (credentials) et générer un challenge 2FA temporaire
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->name('auth.login');

        // Valider le code TOTP du challenge 2FA et délivrer le cookie HTTP-only bango_admin_session
        Route::post('/2fa/verify', [AdminAuthController::class, 'verify2fa'])
            ->name('auth.2fa.verify');

        // Obtenir les informations et options d'assistance pour la récupération de compte 2FA
        Route::get('/2fa/recovery', [AdminAuthController::class, 'recoveryInfo'])
            ->name('auth.2fa.recovery.info');

        // Valider un code de secours (recovery code) d'urgence pour accéder à la session
        Route::post('/2fa/recovery', [AdminAuthController::class, 'verifyRecovery'])
            ->name('auth.2fa.recovery.verify');

        // Renvoyer le code OTP de vérification d'appareil sur le canal sécurisé
        Route::post('/device/resend', [AdminDeviceController::class, 'resend'])
            ->name('auth.device.resend');

        // Signaler une tentative de connexion ou un appareil suspect non reconnu par l'opérateur
        Route::post('/device/report', [AdminDeviceController::class, 'report'])
            ->name('auth.device.report');

        // Consulter les règles strictes de complexité des mots de passe (12 caractères min.)
        Route::get('/password/policy', [AdminAuthController::class, 'passwordPolicy'])
            ->name('auth.password.policy');

        // Soumettre une demande d'accès corporate opérateur (réservé aux adresses @bango.app)
        Route::post('/access-requests', [AdminAccessRequestController::class, 'store'])
            ->name('auth.access-requests.store');

        // Consulter et vérifier la validité d'une invitation collaborateur reçue par lien
        Route::get('/invitations/{token}', [AdminInvitationController::class, 'show'])
            ->name('auth.invitations.show');

        // Accepter une invitation interne et définir son mot de passe opérateur initial
        Route::post('/invitations/{token}/accept', [AdminInvitationController::class, 'accept'])
            ->name('auth.invitations.accept');

        // Demander l'envoi d'un lien sécurisé de réinitialisation de mot de passe par email
        Route::post('/password/forgot', [AdminPasswordResetController::class, 'forgot'])
            ->name('auth.password.forgot');
    });

    // ----------------------------------------------------------------------
    // 0.2 Réinitialisation de mot de passe opérateur
    // ----------------------------------------------------------------------
    // Afficher et vérifier les informations du formulaire de réinitialisation via son token
    Route::get('/reset/{token}', [AdminPasswordResetController::class, 'showReset'])
        ->name('password.reset.show');
    // Valider et enregistrer le nouveau mot de passe avec le token dans l'URL
    Route::post('/reset/{token}', [AdminPasswordResetController::class, 'reset'])
        ->name('password.reset.submit');
    // Valider et enregistrer le nouveau mot de passe avec le token dans le corps de requête
    Route::post('/reset', [AdminPasswordResetController::class, 'reset'])
        ->name('password.reset.post');

    // ----------------------------------------------------------------------
    // 0.3 Session administrative & Coque globale (Middleware: admin.session)
    // ----------------------------------------------------------------------
    Route::middleware(['admin.session'])->group(function () {
        // Consulter la session active, l'opérateur connecté et ses permissions effectives
        Route::get('/auth/session', [AdminAuthController::class, 'session'])
            ->name('auth.session');

        // Prolonger la session active (fenêtre glissante d'inactivité de 30 minutes)
        Route::post('/auth/session/touch', [AdminAuthController::class, 'touch'])
            ->name('auth.session.touch');

        // Déconnecter l'opérateur, révoquer la session en base et purger les cookies sécurisés
        Route::post('/auth/logout', [AdminAuthController::class, 'logout'])
            ->name('auth.logout');

        // Obtenir les compteurs KPI globaux en temps réel (utilisateurs, abonnements, tickets)
        Route::get('/shell/counters', [AdminShellController::class, 'counters'])
            ->name('shell.counters');

        // Lister les pays couverts et opérationnels avec devises et opérateurs télécoms
        Route::get('/shell/countries', [AdminShellController::class, 'countries'])
            ->name('shell.countries');

        // Consulter le flux des notifications internes destinées aux opérateurs
        Route::get('/notifications', [AdminNotificationController::class, 'index'])
            ->name('notifications.index');

        // Marquer l'ensemble des notifications internes comme lues
        Route::post('/notifications/read-all', [AdminNotificationController::class, 'readAll'])
            ->name('notifications.read_all');

        // Effectuer une recherche transverse rapide (⌘K) sur les entités de la plateforme
        Route::get('/search', [AdminSearchController::class, 'search'])
            ->name('search');

        // Consulter l'espace de travail personnel de l'opérateur (tâches, assignations, raccourcis)
        Route::get('/me/workspace', [AdminWorkspaceController::class, 'workspace'])
            ->name('me.workspace');

        // Consulter la file d'attente des demandes soumises à la validation au principe des 4 yeux
        Route::get('/me/approvals', [AdminWorkspaceController::class, 'approvals'])
            ->name('me.approvals');

        // ==================================================================
        // MODULE 0.4 : ESPACE MODÉRATION, SANCTIONS, RECOURS & DROITS D'AUTEUR (ÉTAPE 2)
        // ==================================================================
        Route::prefix('moderation')->name('moderation.')->group(function () {

            // 4.1 File paginée des signalements avec filtres et compteurs
            Route::get('/queue', [AdminModerationQueueController::class, 'queue'])
                ->middleware('admin.permission:moderation:view')
                ->name('queue');

            // 4.2 Fiche détaillée d'un dossier de modération
            Route::get('/cases/{ref}', [AdminModerationQueueController::class, 'show'])
                ->middleware('admin.permission:moderation:view')
                ->name('cases.show');

            // 4.3 Prise de décision validée sur un dossier
            Route::post('/cases/{ref}/decision', [AdminModerationQueueController::class, 'decision'])
                ->middleware('admin.permission:moderation:action')
                ->name('cases.decision');

            // 4.5 Traitement groupé de dossiers de modération
            Route::post('/cases/bulk-decision', [AdminModerationQueueController::class, 'bulkDecision'])
                ->middleware('admin.permission:moderation:action')
                ->name('cases.bulk-decision');

            // 4.6 Liste des opérateurs assignables à la modération
            Route::get('/assignees', [AdminModerationQueueController::class, 'assignees'])
                ->middleware('admin.permission:moderation:view')
                ->name('assignees');

            // 4.6 Assignation de dossiers à un opérateur
            Route::post('/cases/assign', [AdminModerationQueueController::class, 'assign'])
                ->middleware('admin.permission:moderation:assign')
                ->name('cases.assign');

            // 4.6 Escalade d'un dossier vers un responsable/superviseur
            Route::post('/cases/{ref}/escalate', [AdminModerationQueueController::class, 'escalate'])
                ->middleware('admin.permission:moderation:assign')
                ->name('cases.escalate');

            // 4.7 Ouverture et traçabilité d'accès à une preuve
            Route::post('/cases/{ref}/evidence/{id}/open', [AdminModerationEvidenceController::class, 'open'])
                ->middleware('admin.permission:moderation:view')
                ->name('cases.evidence.open');

            // 4.7 Téléversement sécurisé de pièces probatoires
            Route::post('/uploads', [AdminModerationEvidenceController::class, 'upload'])
                ->middleware('admin.permission:moderation:view')
                ->name('uploads');

            // 4.8 Historique des décisions prises par l'opérateur connecté
            Route::get('/decisions/mine', [AdminModerationQueueController::class, 'myDecisions'])
                ->middleware('admin.permission:moderation:view')
                ->name('decisions.mine');

            // 4.8 Export contrôlé de l'activité de l'opérateur
            Route::post('/mine/export', [AdminModerationQueueController::class, 'export'])
                ->middleware('admin.permission:moderation:export')
                ->name('mine.export');

            // 5.1 File paginée des contestations et recours (SLA 72h)
            Route::get('/appeals', [AdminModerationAppealController::class, 'index'])
                ->middleware('admin.permission:moderation:appeals')
                ->name('appeals.index');

            // 5.2 Réexamen d'une sanction avec décision explicite (séparation stricte des rôles)
            Route::post('/appeals/{ref}/resolution', [AdminModerationAppealController::class, 'resolve'])
                ->middleware('admin.permission:moderation:appeals')
                ->name('appeals.resolve');

            // 6. Consultation des règles de modération
            Route::get('/rules', [AdminModerationRuleController::class, 'index'])
                ->middleware('admin.permission:moderation:view')
                ->name('rules.index');

            // 6. Création d'une nouvelle règle de modération
            Route::post('/rules', [AdminModerationRuleController::class, 'store'])
                ->middleware('admin.permission:moderation:rules')
                ->name('rules.store');

            // 6. Mise à jour d'une règle de modération existante
            Route::patch('/rules/{reason}', [AdminModerationRuleController::class, 'update'])
                ->middleware('admin.permission:moderation:rules')
                ->name('rules.update');

            // 6. Correspondance officielle entre les 6 motifs de l'application et les motifs internes
            Route::get('/app-reasons', [AdminModerationRuleController::class, 'appReasons'])
                ->middleware('admin.permission:moderation:view')
                ->name('app-reasons');

            // 7. Déclenchements et alertes du filtre textuel automatique
            Route::get('/filter', [AdminModerationFilterController::class, 'index'])
                ->middleware('admin.permission:moderation:view')
                ->name('filter');

            // 8. File des revendications de propriété intellectuelle et droits d'auteur
            Route::get('/rights', [AdminCopyrightController::class, 'index'])
                ->middleware('admin.permission:copyright:manage')
                ->name('rights.index');

            // 8. Décision validée sur une revendication de droits d'auteur
            Route::post('/rights/{ref}/decision', [AdminCopyrightController::class, 'decision'])
                ->middleware('admin.permission:copyright:manage')
                ->name('rights.decision');

            // 9. Consultation des sons du catalogue et de leur statut de modération
            Route::get('/sounds', [AdminModerationSoundController::class, 'index'])
                ->middleware('admin.permission:moderation:view')
                ->name('sounds.index');

            // 9. Actions de modération sur un son (validation, désactivation, restauration)
            Route::post('/sounds/{id}/action', [AdminModerationSoundController::class, 'action'])
                ->middleware('admin.permission:moderation:action')
                ->name('sounds.action');

            // 10. File des tendances globales avec annotations d'exclusions
            Route::get('/trends', [AdminModerationTrendController::class, 'index'])
                ->middleware('admin.permission:trends:manage')
                ->name('trends.index');

            // 10. Liste des exclusions de hashtags
            Route::get('/trends/exclusions', [AdminModerationTrendController::class, 'listExclusions'])
                ->middleware('admin.permission:trends:manage')
                ->name('trends.exclusions.list');

            // 10. Exclusion d'un hashtag des tendances sans destruction du contenu source
            Route::post('/trends/exclusions', [AdminModerationTrendController::class, 'exclude'])
                ->middleware('admin.permission:trends:manage')
                ->name('trends.exclusions.create');

            // 10. Restauration d'un hashtag exclu vers les tendances
            Route::post('/exclusions/restore', [AdminModerationTrendController::class, 'restore'])
                ->middleware('admin.permission:trends:manage')
                ->name('exclusions.restore');

            // 10. Alias de restauration sous le préfixe trends
            Route::post('/trends/exclusions/restore', [AdminModerationTrendController::class, 'restore'])
                ->middleware('admin.permission:trends:manage')
                ->name('trends.exclusions.restore');
        });

        // Téléversement sécurisé de preuves (alias direct POST /admin/uploads tel que requis en section 4.7)
        Route::post('/uploads', [AdminModerationEvidenceController::class, 'upload'])
            ->middleware('admin.permission:moderation:view')
            ->name('uploads');

        // ------------------------------------------------------------------
        // MODULE 1 : RÔLES & PERMISSIONS (Strictement réservé Super-Admin / Direction)
        // ------------------------------------------------------------------
        Route::prefix('roles')->name('roles.')->middleware('admin.permission:roles:manage')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::get('/{role}', [RoleController::class, 'show'])->name('show');
            Route::put('/{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
            Route::get('/{role}/users', [RoleController::class, 'users'])->name('users');
        });

        // ------------------------------------------------------------------
        // MODULE 2 : GESTION DES UTILISATEURS (Strictement réservé Super-Admin / Direction)
        // ------------------------------------------------------------------
        Route::prefix('users')->name('users.')->middleware('admin.permission:operators:manage')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/{user}', [UserController::class, 'show'])->name('show');
            Route::patch('/{user}', [UserController::class, 'update'])->name('update');
            Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');

            Route::get('/{user}/role', [RoleController::class, 'getUserRole'])->name('role.get');
            Route::patch('/{user}/role', [RoleController::class, 'updateUserRole'])->name('role.update');

            Route::post('/{user}/suspend', [UserController::class, 'suspend'])->name('suspend');
            Route::post('/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('unsuspend');
            Route::post('/{user}/block', [UserController::class, 'block'])->name('block');
            Route::post('/{user}/unblock', [UserController::class, 'unblock'])->name('unblock');

            Route::get('/{user}/sessions', [UserController::class, 'sessions'])->name('sessions.index');
            Route::delete('/{user}/sessions', [UserController::class, 'revokeAllSessions'])->name('sessions.revoke_all');
            Route::delete('/{user}/sessions/{session}', [UserController::class, 'revokeSession'])->name('sessions.revoke');
        });

        // ------------------------------------------------------------------
        // MODULE 3 : GESTION GÉOGRAPHIQUE (PAYS, DEVISES, VILLES, OPÉRATEURS)
        // ------------------------------------------------------------------
        Route::prefix('geo')->name('geo.')->middleware('admin.permission:operators:manage')->group(function () {
            Route::apiResource('countries', CountryController::class)->except(['index', 'show']);
            Route::apiResource('currencies', CurrencyController::class)->except(['index', 'show']);
            Route::apiResource('cities', CityController::class)->except(['index', 'show']);
            Route::apiResource('neighbourhoods', NeighbourhoodController::class)->except(['index', 'show']);
            Route::apiResource('operators', OperatorController::class)->except(['index', 'show']);
        });

        // ------------------------------------------------------------------
        // MODULE 4 : CATALOGUE D'ABONNEMENTS & TARIFS
        // ------------------------------------------------------------------
        Route::prefix('catalog')->name('catalog.')->middleware('admin.permission:operators:manage')->group(function () {
            Route::get('/products', [SubscriptionCatalogController::class, 'index'])->name('products.index');
            Route::post('/products', [SubscriptionCatalogController::class, 'store'])->name('products.store');
            Route::get('/products/{id}', [SubscriptionCatalogController::class, 'show'])->name('products.show');
            Route::put('/products/{id}', [SubscriptionCatalogController::class, 'update'])->name('products.update');
            Route::post('/products/{id}/prices', [SubscriptionCatalogController::class, 'addPrice'])->name('prices.store');
            Route::put('/prices/{priceId}', [SubscriptionCatalogController::class, 'updatePrice'])->name('prices.update');
        });

        // ------------------------------------------------------------------
        // MODULE 5 : ABONNEMENTS & BADGES (Supervision & Support)
        // ------------------------------------------------------------------
        Route::prefix('subscriptions')->name('subscriptions.')->middleware('admin.permission:verification:view')->group(function () {
            Route::get('/', [SubscriptionManagementController::class, 'index'])->name('index');
            Route::get('/{id}', [SubscriptionManagementController::class, 'show'])->name('show');
            Route::post('/{id}/suspend', [SubscriptionManagementController::class, 'suspend'])->middleware('admin.permission:verification:action')->name('suspend');
            Route::post('/{id}/reactivate', [SubscriptionManagementController::class, 'reactivate'])->middleware('admin.permission:verification:action')->name('reactivate');
            Route::post('/{id}/extend', [SubscriptionManagementController::class, 'extend'])->middleware('admin.permission:verification:action')->name('extend');
            Route::post('/{id}/revoke', [SubscriptionManagementController::class, 'revoke'])->middleware('admin.permission:verification:action')->name('revoke');
        });
        Route::get('/badges/history', [SubscriptionManagementController::class, 'badgeHistories'])
            ->middleware('admin.permission:verification:view')
            ->name('badges.history');

        // ------------------------------------------------------------------
        // MODULE 6 : PAIEMENTS, RAPPROCHEMENT & LITIGES (Finance - Étape 4)
        // ------------------------------------------------------------------
        Route::prefix('payments')->name('payments.')->middleware('admin.permission:finance:view')->group(function () {
            // Endpoints existants préservés pour compatibilité
            Route::get('/transactions', [AdminPaymentController::class, 'index'])->name('transactions.index');
            Route::get('/transactions/{id}', [AdminPaymentController::class, 'show'])->name('transactions.show');
            Route::get('/transactions/{id}/events', [AdminPaymentController::class, 'events'])->name('transactions.events');
            Route::post('/transactions/{id}/refund', [AdminPaymentController::class, 'refund'])->middleware('admin.permission:finance:action')->name('transactions.refund');

            Route::get('/disputes', [AdminPaymentController::class, 'disputes'])->name('disputes.index');
            Route::post('/disputes', [AdminPaymentController::class, 'createDispute'])->middleware('admin.permission:finance:action')->name('disputes.store');
            Route::get('/disputes/{id}', [AdminPaymentController::class, 'showDispute'])->name('disputes.show');
            Route::post('/disputes/{id}/status', [AdminPaymentController::class, 'updateDisputeStatus'])->middleware('admin.permission:finance:action')->name('disputes.status');
            Route::post('/disputes/{id}/notes', [AdminPaymentController::class, 'addDisputeNote'])->middleware('admin.permission:finance:action')->name('disputes.notes');

            Route::get('/double-charge-claims', [AdminPaymentController::class, 'doubleChargeClaims'])->name('double_charge_claims.index');
            Route::post('/double-charge-claims', [AdminPaymentController::class, 'createDoubleChargeClaim'])->middleware('admin.permission:finance:action')->name('double_charge_claims.store');

            // 4.1 Liste paginée des transactions
            Route::get('/', [AdminPaymentOperationsController::class, 'index'])->name('index');

            // 4.3 Grand livre des Crédits BANGO et soldes actifs
            Route::get('/credits', [AdminPaymentOperationsController::class, 'credits'])->name('credits');

            // 4.4 Monitoring des passerelles et opérateurs
            Route::get('/operators', [AdminPaymentOperationsController::class, 'operators'])->name('operators');

            // 4.5 Liste des reversements aux bailleurs
            Route::get('/payouts', [AdminPayoutController::class, 'index'])
                ->middleware('admin.permission:finance:payout')
                ->name('payouts.index');

            // 4.6 Relance d'un reversement échoué ou bloqué
            Route::post('/{id}/payout/retry', [AdminPayoutController::class, 'retry'])
                ->middleware('admin.permission:finance:payout')
                ->name('payouts.retry');

            // 4.8 Supervision des abonnements de badges & période de grâce
            Route::get('/subscriptions', [AdminPaymentOperationsController::class, 'subscriptions'])->name('subscriptions.index');

            // 4.11 Opérateurs habilités à l'approbation des remboursements
            Route::get('/refund-approvers', [AdminRefundController::class, 'approvers'])->name('refund.approvers');

            // 4.12 Création d'une demande de remboursement (double validation)
            Route::post('/{id}/refund', [AdminRefundController::class, 'createRequest'])
                ->middleware('admin.permission:finance:refund')
                ->name('refund.create');

            // 4.13 Décision du 2e opérateur : validation ou rejet
            Route::post('/{id}/refund/decision', [AdminRefundController::class, 'decision'])
                ->middleware('admin.permission:finance:approve')
                ->name('refund.decision');

            // 4.14 Retrait d'une demande de remboursement par le demandeur
            Route::post('/{id}/refund/withdraw', [AdminRefundController::class, 'withdraw'])
                ->middleware('admin.permission:finance:refund')
                ->name('refund.withdraw');

            // 4.9 Clôture administrative d'un incident de paiement sans mouvement financier
            Route::post('/{id}/close', [AdminPaymentOperationsController::class, 'close'])
                ->middleware('admin.permission:finance:action')
                ->name('close');

            // 4.10 Émission compensatoire de Crédits BANGO
            Route::post('/{id}/credit', [AdminPaymentOperationsController::class, 'credit'])
                ->middleware('admin.permission:finance:credits')
                ->name('credit');

            // 4.15 Rapprochement ciblé auprès du provider
            Route::post('/{id}/check', [AdminPaymentOperationsController::class, 'check'])
                ->middleware('admin.permission:finance:reconcile')
                ->name('check');

            // 4.16 Rapprochement groupé et contrôlé
            Route::post('/resync', [AdminPaymentOperationsController::class, 'resync'])
                ->middleware('admin.permission:finance:reconcile')
                ->name('resync');

            // 4.17 Reçu officiel de transaction avec signature cryptographique
            Route::get('/{id}/receipt', [AdminPaymentOperationsController::class, 'receipt'])->name('receipt');

            // 4.18 Aperçu d'export comptable sans mutation
            Route::post('/exports/preview', [AdminPaymentExportController::class, 'preview'])
                ->middleware('admin.permission:finance:export')
                ->name('exports.preview');

            // 4.19 Export comptable CSV signé et sécurisé
            Route::post('/exports', [AdminPaymentExportController::class, 'export'])
                ->middleware('admin.permission:finance:export')
                ->name('exports.download');

            // 4.2 Fiche détaillée d'une transaction
            Route::get('/{id}', [AdminPaymentOperationsController::class, 'show'])->name('show');
        });

        // ------------------------------------------------------------------
        // MODULE 6.1 : GESTION DES CAMPAGNES DE BOOST (Étape 4)
        // ------------------------------------------------------------------
        Route::prefix('campaigns')->name('campaigns.')->middleware('admin.permission:boost:view')->group(function () {
            // 5.1 Liste paginée des campagnes de Boost
            Route::get('/', [AdminCampaignManagementController::class, 'index'])->name('index');

            // 5.3 Liste des restitutions de budget
            Route::get('/refunds', [AdminCampaignManagementController::class, 'refunds'])->name('refunds');

            // 5.4 Aperçu du montant restituable avant suspension
            Route::post('/suspend/preview', [AdminCampaignManagementController::class, 'previewSuspend'])->name('suspend.preview');

            // 5.5 Suspension de campagne et restitution de crédits à l'annonceur
            Route::post('/suspend', [AdminCampaignManagementController::class, 'suspend'])
                ->middleware('admin.permission:boost:manage')
                ->name('suspend');

            // 5.7 Export CSV des campagnes sécurisé contre l'injection de formules
            Route::post('/exports', [AdminCampaignManagementController::class, 'export'])
                ->middleware('admin.permission:boost:export')
                ->name('exports');

            // 5.2 Détail d'une campagne de Boost
            Route::get('/{id}', [AdminCampaignManagementController::class, 'show'])->name('show');

            // 5.6 Envoi d'un message explicatif à l'annonceur dans sa langue
            Route::post('/{id}/notify', [AdminCampaignManagementController::class, 'notify'])
                ->middleware('admin.permission:boost:manage')
                ->name('notify');
        });

        // ------------------------------------------------------------------
        // MODULE 7 : CATALOGUE AUDIO & SONS REELS (Administration)
        // ------------------------------------------------------------------
        $soundCatalogRoutes = function () {
            Route::get('/', [AdminSoundCatalogController::class, 'index'])->name('index');
            Route::post('/', [AdminSoundCatalogController::class, 'store'])->middleware('admin.permission:moderation:action')->name('store');
            Route::get('/{id}', [AdminSoundCatalogController::class, 'show'])->name('show');
            Route::post('/{id}', [AdminSoundCatalogController::class, 'update'])->middleware('admin.permission:moderation:action')->name('update');
            Route::patch('/{id}/publication', [AdminSoundCatalogController::class, 'togglePublication'])->middleware('admin.permission:moderation:action')->name('publication');
            Route::patch('/{id}/rights', [AdminSoundCatalogController::class, 'updateRights'])->middleware('admin.permission:moderation:action')->name('rights');
            Route::delete('/{id}', [AdminSoundCatalogController::class, 'removeFromCatalog'])->middleware('admin.permission:moderation:action')->name('remove');
            Route::post('/{id}/restore', [AdminSoundCatalogController::class, 'restore'])->middleware('admin.permission:moderation:action')->name('restore');
            Route::delete('/{id}/force', [AdminSoundCatalogController::class, 'destroy'])->middleware('admin.permission:operators:manage')->name('destroy');
        };

        Route::prefix('sounds')->name('sounds.')->middleware('admin.permission:moderation:view')->group($soundCatalogRoutes);
        Route::prefix('sounds-catalog')->name('sounds-catalog.')->middleware('admin.permission:moderation:view')->group($soundCatalogRoutes);

        // ------------------------------------------------------------------
        // MODULE 8 : PAGES PROFESSIONNELLES & CERTIFICATIONS (Module 12 PG)
        // ------------------------------------------------------------------
        Route::prefix('pages')->name('pages.')->middleware('admin.permission:verification:view')->group(function () {
            Route::get('/certifications', [PageCertificationAdminController::class, 'index'])->name('certifications.index');
            Route::post('/certifications/{certification}/decide', [PageCertificationAdminController::class, 'decide'])->middleware('admin.permission:verification:action')->name('certifications.decide');
            Route::post('/{page}/suspend-certification', [PageCertificationAdminController::class, 'suspendCertification'])->middleware('admin.permission:verification:action')->name('suspend_certification');
            Route::post('/{page}/suspend', [PageCertificationAdminController::class, 'suspendPage'])->middleware('admin.permission:verification:action')->name('suspend');
            Route::post('/{page}/unsuspend', [PageCertificationAdminController::class, 'unsuspendPage'])->middleware('admin.permission:verification:action')->name('unsuspend');
        });

        // ------------------------------------------------------------------
        // MODULE 9 : BILLETTERIE SUPPORT CLIENT (Module 18 SUP)
        // ------------------------------------------------------------------
        Route::prefix('support/tickets')->name('support.tickets.')->middleware('admin.permission:support:view')->group(function () {
            Route::get('/', [AdminSupportTicketController::class, 'index'])->name('index');
            Route::patch('/{id}/status', [AdminSupportTicketController::class, 'updateStatus'])->middleware('admin.permission:support:action')->name('status');
            Route::patch('/{id}/assign', [AdminSupportTicketController::class, 'assign'])->middleware('admin.permission:support:action')->name('assign');
            Route::post('/{id}/messages', [AdminSupportTicketController::class, 'reply'])->middleware('admin.permission:support:action')->name('reply');
        });

        // ------------------------------------------------------------------
        // MODULE 10 : VÉRIFICATION D'IDENTITÉ & BADGES PROFESSIONNELS (KYC - ÉTAPE 3 MODULE A)
        // ------------------------------------------------------------------
        Route::prefix('kyc')->name('kyc.')->group(function () {
            // Règles de vérification (A.8)
            Route::get('/rules', [AdminKycQueueController::class, 'rules'])
                ->middleware('admin.permission:kyc:view')
                ->name('rules');

            // Cibles d'escalade hiérarchique (A.6 bis)
            Route::get('/escalation-targets', [AdminKycQueueController::class, 'escalationTargets'])
                ->middleware('admin.permission:kyc:view')
                ->name('escalation_targets');

            // Surveillance des badges professionnels (A.2)
            Route::get('/badges', [AdminKycBadgeController::class, 'index'])
                ->middleware('admin.permission:kyc:view')
                ->name('badges.index');

            // Dossiers refusés et historique des recours (A.3)
            Route::get('/refusals', [AdminKycBadgeController::class, 'refusals'])
                ->middleware('admin.permission:kyc:view')
                ->name('refusals.index');

            // File des demandes KYC à instruire (A.1)
            Route::get('/requests', [AdminKycQueueController::class, 'index'])
                ->middleware('admin.permission:kyc:view')
                ->name('requests.index');

            // Fiche détaillée d'un dossier KYC (A.4)
            Route::get('/requests/{id}', [AdminKycQueueController::class, 'show'])
                ->middleware('admin.permission:kyc:view')
                ->name('requests.show');

            // Décision d'instruction KYC (A.5)
            Route::post('/requests/{id}/decision', [AdminKycQueueController::class, 'decision'])
                ->middleware('admin.permission:kyc:action')
                ->name('requests.decision');

            // Escalade hiérarchique (A.6)
            Route::post('/requests/{id}/escalate', [AdminKycQueueController::class, 'escalate'])
                ->middleware('admin.permission:kyc:escalate')
                ->name('requests.escalate');

            // Consultation et traçabilité des pièces d'identité (A.7)
            Route::post('/requests/{id}/documents/{docId}/open', [AdminKycDocumentController::class, 'open'])
                ->middleware('admin.permission:kyc:documents')
                ->name('requests.documents.open');
        });

        // ------------------------------------------------------------------
        // MODULE 11 : CONTRÔLE IMMOBILIER, ANNONCES, DOUBLONS, VISITES & EXPORTS (ÉTAPE 3 MODULE B)
        // ------------------------------------------------------------------
        Route::prefix('listings')->name('listings.')->group(function () {
            // Vue cartographique administrative (B.8)
            Route::get('/map', [AdminListingControlController::class, 'map'])
                ->middleware('admin.permission:listings:view')
                ->name('map');

            // Suivi global des visites réservées et SLA 18h (B.9)
            Route::get('/visits', [AdminListingVisitController::class, 'index'])
                ->middleware('admin.permission:listings:view')
                ->name('visits.index');

            // Détection des doublons potentiels (B.2)
            Route::get('/duplicates', [AdminListingDuplicateController::class, 'index'])
                ->middleware('admin.permission:listings:duplicates')
                ->name('duplicates.index');

            // Fusion de doublons (B.3)
            Route::post('/duplicates/merge', [AdminListingDuplicateController::class, 'merge'])
                ->middleware('admin.permission:listings:duplicates')
                ->name('duplicates.merge');

            // Traitement groupé d'annonces (B.4 bis)
            Route::post('/review/bulk', [AdminListingControlController::class, 'bulkReview'])
                ->middleware('admin.permission:listings:review')
                ->name('review.bulk');

            // Aperçu de l'export CSV (B.10)
            Route::post('/exports/preview', [AdminListingExportController::class, 'preview'])
                ->middleware('admin.permission:listings:export')
                ->name('exports.preview');

            // Génération et téléchargement CSV sécurisé (B.10 bis)
            Route::post('/exports', [AdminListingExportController::class, 'export'])
                ->middleware('admin.permission:listings:export')
                ->name('exports.download');

            // Tableau paginé des annonces (B.1)
            Route::get('/', [AdminListingControlController::class, 'index'])
                ->middleware('admin.permission:listings:view')
                ->name('index');

            // Détail complet d'une annonce (B.1 bis)
            Route::get('/{ref}', [AdminListingControlController::class, 'show'])
                ->middleware('admin.permission:listings:view')
                ->name('show');

            // Instruction d'une annonce : approbation, rejet, correction (B.4)
            Route::post('/{ref}/review', [AdminListingControlController::class, 'review'])
                ->middleware('admin.permission:listings:review')
                ->name('review');

            // Rejeter un faux positif de doublon (B.3 bis)
            Route::post('/{ref}/not-duplicate', [AdminListingDuplicateController::class, 'dismiss'])
                ->middleware('admin.permission:listings:duplicates')
                ->name('not_duplicate');

            // Certification de visite sur place par agent habilité (B.5)
            Route::post('/{ref}/agent-visit', [AdminListingControlController::class, 'certifyAgentVisit'])
                ->middleware('admin.permission:listings:certify')
                ->name('agent_visit');

            // Dépublication d'une annonce (B.6)
            Route::post('/{ref}/unpublish', [AdminListingControlController::class, 'unpublish'])
                ->middleware('admin.permission:listings:status')
                ->name('unpublish');

            // Republication d'une annonce (B.6 bis)
            Route::post('/{ref}/republish', [AdminListingControlController::class, 'republish'])
                ->middleware('admin.permission:listings:status')
                ->name('republish');

            // Marquage d'un bien comme loué ou occupé (B.7)
            Route::post('/{ref}/rented', [AdminListingControlController::class, 'markRented'])
                ->middleware('admin.permission:listings:status')
                ->name('rented');
        });

        // Republication via convention racine /republish avec paramètre ref en body (B.6 bis alias)
        Route::post('/republish', [AdminListingControlController::class, 'republish'])
            ->middleware('admin.permission:listings:status')
            ->name('republish');
    });
});
