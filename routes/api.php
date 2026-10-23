<?php

use App\Http\Controllers\Admin\Auth\AdminDeviceController;
use App\Http\Controllers\Admin\Auth\AdminInvitationController;
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
use App\Http\Controllers\Geo\GenderController;
use App\Http\Controllers\Geo\NeighbourhoodController;
use App\Http\Controllers\Geo\OperatorController;
use App\Http\Controllers\Housing\HousingSearchController;
use App\Http\Controllers\Landlord\LandlordDelegationController;
use App\Http\Controllers\Landlord\LandlordKycController;
use App\Http\Controllers\Landlord\LandlordPropertyAnalyticsController;
use App\Http\Controllers\Landlord\LandlordPropertyController;
use App\Http\Controllers\Legal\CopyrightClaimController;
use App\Http\Controllers\Legal\DataExportController;
use App\Http\Controllers\Legal\LegalDocumentController;
use App\Http\Controllers\Legal\TermsAcceptanceController;
use App\Http\Controllers\Listing\ListingAlertController;
use App\Http\Controllers\Listing\ListingCommentController;
use App\Http\Controllers\Listing\ListingController;
use App\Http\Controllers\Listing\ListingDraftController;
use App\Http\Controllers\Listing\ListingLikeController;
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
use App\Http\Controllers\Profile\MuteController;
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
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\TwoFactorSmsController;
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
use App\Http\Controllers\Square\PostViewController;
use App\Http\Controllers\Story\CloseListController;
use App\Http\Controllers\Story\HighlightController;
use App\Http\Controllers\Story\StoryController;
use App\Http\Controllers\Subscription\CatalogController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Subscription\SubscriptionRenewalController;
use App\Http\Controllers\Support\HelpArticleController;
use App\Http\Controllers\Support\HelpArticleFeedbackController;
use App\Http\Controllers\Support\ModerationAppealController;
use App\Http\Controllers\Support\ReportController;
use App\Http\Controllers\Support\SupportTicketController;
use App\Http\Controllers\Sync\OfflineSyncController;
use App\Http\Controllers\System\AppVersionController;
use App\Http\Controllers\Upload\TusUploadController;
use App\Http\Controllers\Visit\LandlordVisitController;
use App\Http\Controllers\Visit\ListingVisitRequestController;
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
    // Annulation immédiate de l'inscription et effacement du compte (LG-09)
    Route::delete('/signup', [SignupController::class, 'destroy'])->middleware('auth:api');
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
    // Lister les genres acceptés (AU-07)
    Route::get('/genders', [GenderController::class, 'index'])->name('api.geo.genders');
});

// Alias pour GET /genders (AU-07)
Route::get('/genders', [GenderController::class, 'index'])->name('api.genders');

// --------------------------------------------------------------------------
// AGENTS — ANNUAIRE PUBLIC & RECHERCHE (AG-06)
// --------------------------------------------------------------------------
// Rechercher des agents certifiés dans l'annuaire public BANGO (filtres par ville, langue, note)
Route::get('/agents/search', [AgentDirectoryController::class, 'search'])->name('api.agents.search');
// Alias de recherche d'agents dans l'annuaire public BANGO
Route::get('/search/agents', [AgentDirectoryController::class, 'search'])->name('api.search.agents');

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
// Rechercher des pages professionnelles publiques par nom, catégorie ou ville
Route::get('/search/pages', [PageSearchController::class, 'index'])->name('api.search.pages');
// Consulter les annonces immobilières publiées par une page professionnelle
Route::get('/pages/{page}/listings', [PageController::class, 'listings'])->name('api.pages.listings');
// Consulter la vitrine publique d'une page professionnelle par son identifiant ou slug
Route::get('/pages/{identifier}', [PageController::class, 'show'])->where('identifier', '^(?!my-pages$)[a-zA-Z0-9_\-]+$')->name('api.pages.show');

// --------------------------------------------------------------------------
// MODULE 3 : LG — CMS DOCUMENTS LÉGAUX VERSIONNÉS & SIGNALEMENT DROITS (LG-17)
// --------------------------------------------------------------------------
// Consulter la dernière version publiée d'un document légal (CGU, confidentialité, mentions)
Route::get('/legal/documents/{slug}', [LegalDocumentController::class, 'show'])->name('api.legal.documents.show');
// Déposer une réclamation d'atteinte aux droits d'auteur ou à la propriété intellectuelle
Route::post('/legal/copyright-claims', [CopyrightClaimController::class, 'store'])->name('api.legal.copyright-claims.store');
// Alias pour déposer une réclamation de droits d'auteur
Route::post('/copyright-claims', [CopyrightClaimController::class, 'store'])->name('api.copyright-claims.store');

// Aliases pour le signalement et renvoi de code d'appareil (Point 22)
// Renvoyer le code de validation pour un nouvel appareil non reconnu
Route::post('/auth/device/resend', [AdminDeviceController::class, 'resend'])->name('api.auth.device.resend');
// Signaler une tentative de connexion suspecte depuis un appareil non reconnu
Route::post('/auth/device/report', [AdminDeviceController::class, 'report'])->name('api.auth.device.report');
// Alias pour signaler un appareil suspect
Route::post('/device/report', [AdminDeviceController::class, 'report'])->name('api.device.report');
// Alias pour renvoyer le code de validation d'appareil
Route::post('/device/resend', [AdminDeviceController::class, 'resend'])->name('api.device.resend');

// --------------------------------------------------------------------------
// MODULE EDGE : RÉSOLUTION DES LIENS UNIVERSELS & COURTS
// --------------------------------------------------------------------------
// Résoudre un lien universel, court ou profond vers la ressource BANGO correspondante
Route::get('/content/resolve-link', [LinkResolverController::class, 'resolve'])->name('api.content.resolve-link');

// --------------------------------------------------------------------------
// MODULE 18 : SUP — CENTRE D’AIDE FAQ & RETOUR D'UTILITÉ
// --------------------------------------------------------------------------
Route::prefix('help/articles')->group(function () {
    // Lister et rechercher les articles d'aide FAQ par catégorie ou mot-clé
    Route::get('/', [HelpArticleController::class, 'index'])->name('api.help.articles.index');
    // Consulter le contenu détaillé d'un article d'aide par son slug
    Route::get('/{slug}', [HelpArticleController::class, 'show'])->name('api.help.articles.show');
    // Enregistrer l'avis d'utilité d'un utilisateur sur un article d'aide (utile / pas utile)
    Route::post('/{id}/feedback', [HelpArticleFeedbackController::class, 'feedback'])->name('api.help.articles.feedback');
});

// --------------------------------------------------------------------------
// MODULE 19 : SY — PROTOCOLE TUS v1.0.0 (DÉCOUVERTE PUBLIQUE)
// --------------------------------------------------------------------------
// Découverte des capacités du serveur de téléversement TUS (extensions, taille max)
Route::options('/uploads/tus', [TusUploadController::class, 'options'])->name('api.uploads.tus.options');
// Découverte des options pour une ressource de téléversement TUS spécifique
Route::options('/uploads/tus/{id}', [TusUploadController::class, 'options'])->name('api.uploads.tus.options.id');

// --------------------------------------------------------------------------
// MODULE SP / SY-21 : CONTRÔLE DE VERSION & MISE À JOUR REQUISE
// --------------------------------------------------------------------------
// Vérifier la compatibilité de version de l'application mobile et l'exigence de mise à jour
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

        // Comptes masqués (RL-06 « Masquer ce compte 30 jours », menu L'Immo)
        // Liste des comptes masqués par l'utilisateur connecté
        Route::get('/muted', [MuteController::class, 'index'])->name('api.profile.muted');
        // Masquer un compte utilisateur
        Route::post('/{id}/mute', [MuteController::class, 'mute'])->name('api.profile.mute');
        // Retirer le masquage d'un compte
        Route::delete('/{id}/mute', [MuteController::class, 'unmute'])->name('api.profile.unmute');
    });

    // Alias /users/{id}/mute pour compatibilité directe avec les spécifications frontend
    Route::post('/users/{id}/mute', [MuteController::class, 'mute'])->name('api.users.mute');
    Route::delete('/users/{id}/mute', [MuteController::class, 'unmute'])->name('api.users.unmute');

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
    // Trouver des contacts et amis par correspondance d'empreintes de numéros (AU-19)
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
        // Mes annonces immobilières (bailleur / propriétaire)
        Route::get('/mine', [ListingController::class, 'mine'])->name('api.listings.mine');

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

        // Alertes de recherche immobilière (IM-25, IM-26, IM-04, IM-06)
        Route::get('/alerts', [ListingAlertController::class, 'index'])->name('api.listings.alerts.index');
        Route::post('/alerts', [ListingAlertController::class, 'store'])->name('api.listings.alerts.store');
        Route::patch('/alerts/{id}', [ListingAlertController::class, 'update'])->whereUuid('id')->name('api.listings.alerts.update');
        Route::delete('/alerts/{id}', [ListingAlertController::class, 'destroy'])->whereUuid('id')->name('api.listings.alerts.destroy');

        // Liste paginée des annonces aimées par l'utilisateur (IM-00a / profil onglet Immo « biens aimés »)
        Route::get('/liked', [ListingLikeController::class, 'index'])->name('api.listings.liked.index');

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

        // Aimer une annonce (idempotent, IM-00a)
        Route::post('/{id}/like', [ListingLikeController::class, 'store'])->whereUuid('id')->name('api.listings.like.store');

        // Retirer son « j'aime » d'une annonce (idempotent, IM-00a)
        Route::delete('/{id}/like', [ListingLikeController::class, 'destroy'])->whereUuid('id')->name('api.listings.like.destroy');

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

        // Demander une visite avec propositions de créneaux (IM-22 / IM-23 : bailleur sans agenda)
        // Ressource : ListingVisitRequest | Accès : Authentifié (prospect)
        Route::post('/{id}/visit-requests', [ListingVisitRequestController::class, 'store'])->whereUuid('id')->name('api.listings.visit_requests.store');

        // Commentaires sur une annonce immobilière
        // Lister les commentaires publiés sur une annonce immobilière
        Route::get('/{id}/comments', [ListingCommentController::class, 'index'])->whereUuid('id')->name('api.listings.comments.index');
        // Publier un nouveau commentaire ou une question sur une annonce immobilière
        Route::post('/{id}/comments', [ListingCommentController::class, 'store'])->whereUuid('id')->name('api.listings.comments.store');
        // Supprimer un commentaire sur une annonce immobilière (réservé à l'auteur ou au bailleur)
        Route::delete('/{id}/comments/{commentId}', [ListingCommentController::class, 'destroy'])->whereUuid('id')->name('api.listings.comments.destroy');
    });

    // --------------------------------------------------------------------------
    // IM — GESTION DES VISITES DU PROSPECT (IM-06 / visit.tsx, visits.tsx)
    // --------------------------------------------------------------------------
    Route::prefix('visits')->group(function () {
        // Historique paginé des visites de l'utilisateur connecté
        Route::get('/mine', [ProspectVisitController::class, 'mine'])->name('api.visits.mine');

        // Demandes de visites sans agenda préalable (IM-22 / IM-23)
        Route::get('/requests/{id}', [ListingVisitRequestController::class, 'show'])->whereUuid('id')->name('api.visits.requests.show');
        Route::post('/requests/{id}/cancel', [ListingVisitRequestController::class, 'cancel'])->whereUuid('id')->name('api.visits.requests.cancel');

        // Consultation détaillée d'une réservation de visite
        Route::get('/{id}', [ProspectVisitController::class, 'show'])->whereUuid('id')->name('api.visits.show');

        // Confirmation post-paiement côté client (vérification de preuve serveur)
        Route::post('/{id}/confirm', [ProspectVisitController::class, 'confirm'])->whereUuid('id')->name('api.visits.confirm');

        // Annuler une visite (avec application de la politique de remboursement 24h IM-14)
        Route::post('/{id}/cancel', [ProspectVisitController::class, 'cancel'])->whereUuid('id')->name('api.visits.cancel');

        // Répondre à une proposition de créneau alternatif par le bailleur
        Route::post('/{id}/proposal/respond', [ProspectVisitController::class, 'respondToProposal'])->whereUuid('id')->name('api.visits.proposal.respond');

        // Télécharger ou consulter le reçu officiel de visite (JSON et PDF signé)
        Route::get('/{id}/receipt', [ProspectVisitController::class, 'receipt'])->whereUuid('id')->name('api.visits.receipt');
        Route::get('/{id}/receipt.pdf', [ProspectVisitController::class, 'receiptPdf'])->whereUuid('id')->name('api.visits.receipt.pdf');
    });

    // --------------------------------------------------------------------------
    // BL — ESPACE BAILLEUR (BL-01, BL-02, BL-04, BL-07, BL-18, AG-02, AG-03)
    // --------------------------------------------------------------------------
    Route::prefix('landlord')->group(function () {
        // KYC Bailleur (accessible à tout utilisateur authentifié pour soumission de dossier)
        // Soumettre un dossier de vérification d'identité (KYC) pour le badge bailleur
        Route::post('/kyc/submit', [LandlordKycController::class, 'submit'])->name('api.landlord.kyc.submit');
        // Consulter l'état d'avancement et le statut du dossier KYC bailleur
        Route::get('/kyc', [LandlordKycController::class, 'show'])->name('api.landlord.kyc.show');
        // Télécharger ou visualiser une pièce justificative du dossier KYC bailleur
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

        // Choisir un créneau parmi les propositions d'une demande de visite (IM-22 / IM-23)
        Route::post('/visit-requests/{id}/choose', [ListingVisitRequestController::class, 'choose'])->whereUuid('id')->name('api.landlord.visit_requests.choose');

        // Consulter les demandes et réservations de visite d'un bien spécifique (BL-07)
        Route::get('/properties/{id}/visit-requests', [LandlordVisitController::class, 'visitRequests'])->whereUuid('id')->name('api.landlord.properties.visit_requests');

        // Accepter, proposer une alternative ou décliner une visite (BL-07 / AG-03)
        Route::post('/visits/{id}/answer', [LandlordVisitController::class, 'answer'])->whereUuid('id')->name('api.landlord.visits.answer');

        // Délégations émises par le bailleur
        // Lister les mandats de délégation de gestion immobilière émis par le bailleur
        Route::get('/delegations', [LandlordDelegationController::class, 'index'])->name('api.landlord.delegations.index');
        // Révoquer ou annuler une délégation de gestion confiée à un agent
        Route::delete('/delegations/{id}', [LandlordDelegationController::class, 'destroy'])->whereUuid('id')->name('api.landlord.delegations.destroy');
        // Relancer un agent immobilier invité pour une délégation en attente
        Route::post('/delegations/{id}/remind', [LandlordDelegationController::class, 'remind'])->whereUuid('id')->name('api.landlord.delegations.remind');
        // Modifier les permissions ou les modalités d'une délégation active
        Route::put('/delegations/{id}', [LandlordDelegationController::class, 'update'])->whereUuid('id')->name('api.landlord.delegations.update');
        // Réactiver une délégation précédemment suspendue ou expirée
        Route::post('/delegations/{id}/reinstate', [LandlordDelegationController::class, 'reinstate'])->whereUuid('id')->name('api.landlord.delegations.reinstate');

        // Analytique immobilière chronologique sur 30 jours (BL-19 / Étape 10)
        Route::get('/properties/{id}/analytics', [LandlordPropertyAnalyticsController::class, 'show'])->whereUuid('id')->name('api.landlord.properties.analytics');
    });

    // --------------------------------------------------------------------------
    // AG — ESPACE AGENT IMMOBILIER (AG-01, AG-04, AG-05)
    // --------------------------------------------------------------------------
    Route::prefix('agent')->group(function () {
        // Invitations reçues par l'agent
        // Lister les invitations de délégation reçues par l'agent immobilier
        Route::get('/invitations', [AgentInvitationController::class, 'index'])->name('api.agent.invitations.index');
        // Accepter une invitation de mandat de délégation de gestion immobilière
        Route::post('/invitations/{id}/accept', [AgentInvitationController::class, 'accept'])->whereUuid('id')->name('api.agent.invitations.accept');
        // Refuser une proposition de mandat de délégation immobilière
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

        // Réglages des notifications (ST-17)
        // Consulter les préférences de notification push, email et SMS de l'utilisateur
        Route::get('/notifications', [NotificationSettingsController::class, 'show'])->name('api.settings.notifications.show');
        // Mettre à jour les préférences de notification
        Route::put('/notifications', [NotificationSettingsController::class, 'update'])->name('api.settings.notifications.update');

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
            // Générer une clé secrète et un QR Code pour la configuration de l'application TOTP
            Route::post('/totp/setup', [TwoFactorTotpController::class, 'setup'])->name('api.settings.2fa.totp.setup');
            // Valider le premier code TOTP pour activer l'authentification à deux facteurs
            Route::post('/totp/verify', [TwoFactorTotpController::class, 'verify'])->name('api.settings.2fa.totp.verify');
            // Désactiver l'authentification à deux facteurs (TOTP/SMS) après confirmation du mot de passe
            Route::post('/disable', [TwoFactorTotpController::class, 'disable'])->name('api.settings.2fa.disable');

            // Double Authentification SMS
            // Envoyer un code de validation SMS pour configurer la 2FA par SMS
            Route::post('/sms/send', [TwoFactorSmsController::class, 'send'])->name('api.settings.2fa.sms.send');
            // Valider le code SMS et activer la 2FA par SMS
            Route::post('/sms/verify', [TwoFactorSmsController::class, 'verify'])->name('api.settings.2fa.sms.verify');

            // Codes de secours à usage unique (ST-10)
            // Consulter la liste des codes de secours 2FA actifs (ou leur statut d'utilisation)
            Route::get('/backup-codes', [BackupCodeController::class, 'index'])->name('api.settings.2fa.backup-codes.index');
            // Générer un nouveau jeu de codes de secours 2FA
            Route::post('/backup-codes', [BackupCodeController::class, 'regenerate'])->name('api.settings.2fa.backup-codes.generate');
            // Régénérer et invalider les anciens codes de secours 2FA
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
        // Initier un paiement Mobile Money (Push USSD) ou Crédit BANGO
        Route::post('/initiate', [PaymentController::class, 'initiate']);
        // Vérifier un achat intégré App Store ou Google Play Store
        Route::post('/store/verify', [PaymentController::class, 'verifyStore'])->name('api.payments.store.verify');

        // ----------------------------------------------------------------------
        // Moyens de paiement enregistrés (Étape 10)
        // ----------------------------------------------------------------------
        // Lister les moyens de paiement enregistrés (Mobile Money, cartes)
        Route::get('/methods', [SavedPaymentMethodController::class, 'index'])->name('api.payments.methods.index');
        // Enregistrer un nouveau moyen de paiement par défaut ou secondaire
        Route::post('/methods', [SavedPaymentMethodController::class, 'store'])->name('api.payments.methods.store');
        // Définir un moyen de paiement comme mode de règlement par défaut
        Route::patch('/methods/{id}/default', [SavedPaymentMethodController::class, 'setDefault'])->name('api.payments.methods.default');
        // Supprimer un moyen de paiement enregistré
        Route::delete('/methods/{id}', [SavedPaymentMethodController::class, 'destroy'])->name('api.payments.methods.destroy');

        // ----------------------------------------------------------------------
        // Déclaration d'incidents de double débit (Étape 10)
        // ----------------------------------------------------------------------
        // Déclarer une réclamation pour incident de double débit sur une transaction
        Route::post('/double-charge-claims', [DoubleChargeClaimController::class, 'store'])->name('api.payments.double-charge-claims.store');
        // Lister mes réclamations d'incidents de double débit et leur état de traitement
        Route::get('/double-charge-claims/mine', [DoubleChargeClaimController::class, 'mine'])->name('api.payments.double-charge-claims.mine');

        // Obtenir le statut en temps réel d'un paiement (polling 120s / reprise réseau)
        Route::get('/{id}/status', [PaymentController::class, 'status']);
        // Télécharger le relevé de paiements PDF officiel du compte (PAY-21)
        Route::get('/statement.pdf', [PaymentReceiptController::class, 'downloadStatement'])->name('api.payments.statement.pdf');
        // Télécharger le reçu PDF officiel d'une transaction (Étape 10)
        Route::get('/{id}/receipt', [PaymentReceiptController::class, 'downloadReceipt'])->name('api.payments.receipt.download');
        // Envoyer le reçu PDF officiel par courrier électronique (PAY-23)
        Route::post('/{id}/receipt/email', [PaymentReceiptController::class, 'sendReceiptEmail'])->name('api.payments.receipt.email');
        // Annuler un paiement en attente
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel']);
        // Consulter l'historique paginé des transactions du client
        Route::get('/history', [PaymentController::class, 'history']);
    });

    // --------------------------------------------------------------------------
    // SQUARE — CR1 (Création, Médias, Carrousel, Recherche, Feed & Actions SQ-15)
    // --------------------------------------------------------------------------
    // Alias racine pour consulter le fil d'actualité Square
    Route::get('/square/feed', [PostFeedController::class, 'index'])->name('api.square.feed.alias');
    // Alias racine pour afficher une publication Square spécifique
    Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show.alias');
    // Enregistrer une vue de publication (au plus une par compte et par 24 h)
    Route::post('/posts/{post}/view', [PostViewController::class, 'store'])->name('api.posts.view');
    // Alias racine pour lister les mentions « J'aime » d'une publication
    Route::get('/posts/{post}/likes', [PostLikeController::class, 'likers'])->name('api.posts.likes.alias');
    // Alias racine pour lister les commentaires d'une publication
    Route::get('/posts/{post}/comments', [PostCommentController::class, 'index'])->name('api.posts.comments.index.alias');
    // Alias racine pour ajouter un commentaire sur une publication
    Route::post('/posts/{post}/comments', [PostCommentController::class, 'store'])->name('api.posts.comments.store.alias');
    // Alias racine pour épingler un commentaire en tête d'une publication
    Route::post('/posts/{post}/pin-comment', [PostCommentController::class, 'pin'])->name('api.posts.pin_comment.alias');
    // Alias racine pour lister les réponses d'un commentaire
    Route::get('/comments/{comment}/replies', [PostCommentController::class, 'replies'])->name('api.comments.replies.alias');
    // Suggestions d'habitants et voisins (SQ-01, AU-19)
    Route::get('/people/suggestions', [PeopleSearchController::class, 'suggestions'])->name('api.people.suggestions');

    Route::prefix('square-cr1')->group(function () {
        // Fil Square paginé (CR-11 et flux découverte, abonnés, voisinage)
        Route::get('/square/feed', [PostFeedController::class, 'index'])->name('api.square.feed');
        // Alias pour le fil Square
        Route::get('/feed', [PostFeedController::class, 'index']);

        // Créer une nouvelle publication avec texte, médias, tags, lieu et options (CR-01 à CR-12)
        Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');

        // Afficher les détails d'une publication spécifique selon les règles d'audience
        Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show');

        // Enregistrer une vue de publication (au plus une par compte et par 24 h)
        Route::post('/posts/{post}/view', [PostViewController::class, 'store'])->name('api.square.posts.view');

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
        // Alias pour lister les mentions j'aime d'une publication
        Route::get('/posts/{post}/likes', [PostLikeController::class, 'likers'])->name('api.posts.likes');

        // Partages des publications (SQ - Interactions)
        // Enregistrer un partage de publication (incrément atomique et idempotence)
        Route::post('/posts/{post}/share', [PostShareController::class, 'store'])->name('api.posts.share');

        // Commentaires des publications (SQ - Interactions)
        // Lister les commentaires paginés d'une publication
        Route::get('/posts/{post}/comments', [PostCommentController::class, 'index'])->name('api.posts.comments.index');
        // Ajouter un commentaire sur une publication
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store'])->name('api.posts.comments.store');
        // Épingler un commentaire sur une publication
        Route::post('/posts/{post}/pin-comment', [PostCommentController::class, 'pin'])->name('api.posts.pin_comment');

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
        // Suggestions de voisins et habitants (SQ-01, AU-19)
        Route::get('/people/suggestions', [PeopleSearchController::class, 'suggestions'])->name('api.square.people.suggestions');

        // Rechercher des hashtags par correspondance textuelle et volume (CR-07)
        Route::get('/search/hashtags', [HashtagSearchController::class, 'trending']);
        // Obtenir les hashtags du moment et en tendance (CR-07)
        Route::get('/search/hashtags/trending', [HashtagSearchController::class, 'trending'])->name('api.search.hashtags.trending');

        // Rechercher des lieux contextuels réels issus de la base géographique BANGO (CR-09)
        Route::get('/search/places', [PlaceSearchController::class, 'index'])->name('api.search.places');
    });

    // --------------------------------------------------------------------------
    // MODULE RC — RECHERCHE GLOBALE ET DÉCOUVERTE (Posts, Hashtags, Tendances)
    // --------------------------------------------------------------------------
    Route::prefix('search')->group(function () {
        // Rechercher des personnes et profils (CR-08, RC-02) avec filtres q, city, neighbourhood
        Route::get('/people', [PeopleSearchController::class, 'index'])->name('api.search.people.global');
        // Rechercher des publications Square par mot-clé ou texte intégral
        Route::get('/posts', [PostSearchController::class, 'index'])->name('api.search.posts');
        // Rechercher et explorer des hashtags par préfixe ou volume
        Route::get('/hashtags', [HashtagSearchController::class, 'index'])->name('api.search.hashtags');
        // Consulter les détails et publications associées à un hashtag
        Route::get('/hashtags/{name}', [HashtagSearchController::class, 'show'])->name('api.search.hashtags.show');
        // S'abonner aux publications d'un hashtag
        Route::post('/hashtags/{name}/follow', [HashtagSearchController::class, 'follow'])->name('api.search.hashtags.follow');
        // Se désabonner d'un hashtag
        Route::delete('/hashtags/{name}/follow', [HashtagSearchController::class, 'unfollow'])->name('api.search.hashtags.unfollow');
    });

    // Page d'un hashtag et abonnements (Point 48)
    Route::prefix('hashtags')->group(function () {
        // Consulter le fil et les détails d'un hashtag spécifique
        Route::get('/{name}', [HashtagSearchController::class, 'show'])->name('api.hashtags.show');
        // S'abonner aux publications portant ce hashtag
        Route::post('/{name}/follow', [HashtagSearchController::class, 'follow'])->name('api.hashtags.follow');
        // Se désabonner du hashtag
        Route::delete('/{name}/follow', [HashtagSearchController::class, 'unfollow'])->name('api.hashtags.unfollow');
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

        // Réglages des Stories
        // Consulter les paramètres de confidentialité et d'archivage des Stories
        Route::get('/settings', [StoryController::class, 'settings'])->name('api.stories.settings.show');
        // Mettre à jour les réglages des Stories (réponses autorisées, sauvegarde auto)
        Route::put('/settings', [StoryController::class, 'updateSettings'])->name('api.stories.settings.update');

        // Créer et publier une nouvelle Story avec médias, stickers et audio
        Route::post('/', [StoryController::class, 'store'])->name('api.stories.store');

        // Consulter le détail d'une Story personnelle avec statistiques (vues, votes, réponses)
        Route::get('/mine/{id}', [StoryController::class, 'myStory'])->whereUuid('id')->name('api.stories.mine.show');

        // Consulter la liste nominative des spectateurs d'une Story (réservé au propriétaire)
        Route::get('/{id}/viewers', [StoryController::class, 'viewers'])->whereUuid('id')->name('api.stories.viewers');

        // Récupérer les diapositives créatives associées à une Story
        Route::get('/{id}/slides', [StoryController::class, 'slides'])->whereUuid('id')->name('api.stories.slides');

        // Liker / Déliker une Story
        // Ajouter une mention « J'aime » sur une Story
        Route::post('/{id}/like', [StoryController::class, 'like'])->whereUuid('id')->name('api.stories.like');
        // Retirer son « J'aime » d'une Story
        Route::delete('/{id}/like', [StoryController::class, 'unlike'])->whereUuid('id')->name('api.stories.unlike');

        // Interactions stickers
        // Répondre à un sticker interactif (question, quiz) dans une Story
        Route::post('/stickers/{stickerId}/answer', [StoryController::class, 'answerSticker'])->whereUuid('stickerId')->name('api.stories.stickers.answer');
        // Activer un rappel pour un événement promu via un sticker compte à rebours
        Route::post('/stickers/{stickerId}/remind', [StoryController::class, 'remindSticker'])->whereUuid('stickerId')->name('api.stories.stickers.remind');
        // Répondre à un sticker positionné sur une slide spécifique
        Route::post('/{slideId}/stickers/{stickerId}/answer', [StoryController::class, 'answerSticker'])->whereUuid('slideId')->whereUuid('stickerId');
        // Activer un rappel pour un sticker compte à rebours sur une slide
        Route::post('/{slideId}/stickers/{stickerId}/remind', [StoryController::class, 'remindSticker'])->whereUuid('slideId')->whereUuid('stickerId');

        // Export vidéo d'une Story
        Route::match(['get', 'post'], '/{id}/export', [StoryController::class, 'export'])->whereUuid('id')->name('api.stories.export');

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

    // Alias pour consulter les réglages de Story
    Route::get('/story-settings', [StoryController::class, 'settings']);
    // Alias pour mettre à jour les réglages de Story
    Route::put('/story-settings', [StoryController::class, 'updateSettings']);

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
        // Lister les conversations et fils de discussion actifs de l'utilisateur
        Route::get('/threads', [MessageThreadController::class, 'index'])->name('api.messages.threads.index');
        // Démarrer une nouvelle conversation privée ou directe
        Route::post('/threads', [MessageThreadController::class, 'store'])->name('api.messages.threads.store');
        // Consulter les informations et métadonnées d'une conversation
        Route::get('/threads/{id}', [MessageThreadController::class, 'show'])->name('api.messages.threads.show');
        // Marquer tous les messages d'un fil de discussion comme lus
        Route::match(['post', 'put'], '/threads/{id}/read', [MessageThreadController::class, 'markAsRead'])->name('api.messages.threads.read');

        // Boîte des demandes de messages provenant d'inconnus (MS-05)
        // Lister les demandes de messages en attente (contacts non suivis)
        Route::get('/requests', [MessageRequestController::class, 'index'])->name('api.messages.requests.index');
        // Accepter une demande de message et transférer dans la boîte de réception principale
        Route::post('/requests/{id}/accept', [MessageRequestController::class, 'accept'])->name('api.messages.requests.accept');
        // Refuser et supprimer une demande de message
        Route::post('/requests/{id}/reject', [MessageRequestController::class, 'reject'])->name('api.messages.requests.reject');

        // Messages d'un fil
        // Récupérer l'historique paginé des messages d'une conversation
        Route::get('/threads/{id}/messages', [MessageController::class, 'index'])->name('api.messages.messages.index');
        // Envoyer un nouveau message texte, média ou lien dans une conversation
        Route::post('/threads/{id}/messages', [MessageController::class, 'store'])->name('api.messages.messages.store');
        // Supprimer / rétracter un message envoyé (délai imparti)
        Route::post('/threads/{id}/messages/{messageId}/retract', [MessageController::class, 'retract'])->name('api.messages.messages.retract');
        // Supprimer définitivement un message d'une conversation (verbe DELETE)
        Route::delete('/threads/{id}/messages/{messageId}', [MessageController::class, 'retract'])->name('api.messages.messages.destroy');

        // Notes vocales
        // Téléverser et envoyer un message vocal audio dans une conversation
        Route::post('/threads/{id}/voice', [VoiceMessageController::class, 'store'])->name('api.messages.voice.store');

        // Réactions aux messages
        // Ajouter ou modifier une réaction emoji sur un message
        Route::post('/messages/{id}/reactions', [MessageController::class, 'react'])->name('api.messages.reactions.store');
        // Retirer sa réaction emoji sur un message
        Route::delete('/messages/{id}/reactions', [MessageController::class, 'removeReaction'])->name('api.messages.reactions.destroy');

        // Indicateur « en train d'écrire » (typing)
        // Émettre un signal « en train d'écrire » vers les participants de la conversation
        Route::post('/threads/{id}/typing', [MessageThreadController::class, 'setTyping'])->name('api.messages.threads.typing.set');
        // Consulter l'état de saisie en temps réel dans une conversation
        Route::get('/threads/{id}/typing', [MessageThreadController::class, 'getTyping'])->name('api.messages.threads.typing.get');

        // Sourdine (mute) & Archive
        // Mettre une conversation en sourdine (désactiver les notifications)
        Route::post('/threads/{id}/mute', [MessageThreadController::class, 'mute'])->name('api.messages.threads.mute');
        // Réactiver les notifications d'une conversation
        Route::post('/threads/{id}/unmute', [MessageThreadController::class, 'unmute'])->name('api.messages.threads.unmute');
        // Archiver une conversation pour la masquer de la boîte de réception
        Route::post('/threads/{id}/archive', [MessageThreadController::class, 'archive'])->name('api.messages.threads.archive');
        // Désarchiver une conversation
        Route::post('/threads/{id}/unarchive', [MessageThreadController::class, 'unarchive'])->name('api.messages.threads.unarchive');

        // Conversations de groupe
        // Créer un groupe de discussion avec nom, avatar et membres initiaux
        Route::post('/groups', [MessageGroupController::class, 'store'])->name('api.messages.groups.store');
        // Mettre à jour les paramètres ou le nom d'un groupe de discussion
        Route::put('/groups/{id}', [MessageGroupController::class, 'update'])->name('api.messages.groups.update');
        // Ajouter de nouveaux membres dans un groupe de discussion
        Route::post('/groups/{id}/members', [MessageGroupController::class, 'addMembers'])->name('api.messages.groups.members.add');
        // Retirer un membre d'un groupe de discussion (ou quitter le groupe)
        Route::delete('/groups/{id}/members/{userId}', [MessageGroupController::class, 'removeMember'])->name('api.messages.groups.members.remove');
    });

    // --------------------------------------------------------------------------
    // BO - BOOST & PUBLICITÉ (Campagnes, Estimation de portée, Crédit BANGO)
    // --------------------------------------------------------------------------
    Route::prefix('boost')->group(function () {
        // Contenus éligibles au boost et score de surperformance relative (BO)
        Route::get('/boostable', [BoostableContentController::class, 'index'])->name('api.boost.boostable');

        // Estimation de portée
        // Calculer l'estimation d'audience et de portée selon le budget et le ciblage
        Route::post('/estimate', [BoostEstimateController::class, 'estimate'])->name('api.boost.estimate');

        // Portefeuille de Crédits BANGO
        // Consulter le solde de crédits BANGO et le statut du portefeuille publicitaire
        Route::get('/wallet', [CreditWalletController::class, 'show'])->name('api.boost.wallet.show');
        // Historique des rechargements et débits de crédits publicitaires
        Route::get('/wallet/transactions', [CreditWalletController::class, 'transactions'])->name('api.boost.wallet.transactions');
        // Recharger le portefeuille de crédits BANGO
        Route::post('/wallet/deposit', [CreditWalletController::class, 'deposit'])->name('api.boost.wallet.deposit');

        // Campagnes
        // Lister les campagnes de boost et sponsorisation de l'utilisateur
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('api.boost.campaigns.index');
        // Créer une nouvelle campagne de boost (ciblage, budget, durée)
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('api.boost.campaigns.store');
        // Consulter les performances et métriques détaillées d'une campagne de boost
        Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereUuid('id')->name('api.boost.campaigns.show');
        // Allouer des crédits ou financer une campagne de boost
        Route::post('/campaigns/{id}/fund', [CampaignController::class, 'fund'])->whereUuid('id')->name('api.boost.campaigns.fund');
        // Mettre en pause une campagne active
        Route::post('/campaigns/{id}/pause', [CampaignController::class, 'pause'])->whereUuid('id')->name('api.boost.campaigns.pause');
        // Reprendre la diffusion d'une campagne en pause
        Route::post('/campaigns/{id}/resume', [CampaignController::class, 'resume'])->whereUuid('id')->name('api.boost.campaigns.resume');
        // Annuler définitivement une campagne de boost et libérer les crédits résiduels
        Route::post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel'])->whereUuid('id')->name('api.boost.campaigns.cancel');
        // Enregistrer une impression publicitaire pour le décompte d'audience
        Route::post('/campaigns/{id}/impression', [CampaignController::class, 'trackImpression'])->whereUuid('id')->name('api.boost.campaigns.impression');
        // Enregistrer un clic publicitaire sur le contenu boosté
        Route::post('/campaigns/{id}/click', [CampaignController::class, 'trackClick'])->whereUuid('id')->name('api.boost.campaigns.click');
    });

    // --------------------------------------------------------------------------
    // CB - CRÉDIT BANGO (Portefeuille, Achat via PAY, Historique & Traçabilité)
    // --------------------------------------------------------------------------
    Route::prefix('credits')->group(function () {
        // Consulter le solde du portefeuille de Crédits BANGO
        Route::get('/wallet', [App\Http\Controllers\Credits\CreditWalletController::class, 'show'])->name('api.credits.wallet.show');
        // Acheter un pack de Crédits BANGO via paiement mobile money ou carte
        Route::post('/purchases', [CreditPurchaseController::class, 'store'])->name('api.credits.purchases.store');
        // Historique des transactions et mouvements de Crédits BANGO
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
        // Assigner une conversation de la page à un collaborateur spécifique
        Route::post('/{page}/inbox-assignment', [PageController::class, 'assignInbox'])->name('api.pages.inbox_assignment');
        // Basculer la visibilité publique d'une page professionnelle
        Route::post('/{page}/visibility', [PageController::class, 'toggleVisibility'])->name('api.pages.visibility');
        // Suivre une page professionnelle pour recevoir ses actualités
        Route::post('/{page}/follow', [PageController::class, 'follow'])->name('api.pages.follow');
        // Ne plus suivre une page professionnelle
        Route::delete('/{page}/follow', [PageController::class, 'unfollow'])->name('api.pages.unfollow');

        // Membres & RBAC (PG-03)
        // Lister les membres et gestionnaires associés à une page professionnelle
        Route::get('/{page}/members', [PageMemberController::class, 'index'])->name('api.pages.members.index');
        // Inviter un nouveau collaborateur avec un rôle défini (admin, éditeur, modérateur)
        Route::post('/{page}/members/invite', [PageMemberController::class, 'invite'])->name('api.pages.members.invite');
        // Modifier le rôle et les permissions d'un membre de la page
        Route::put('/{page}/members/{member}/role', [PageMemberController::class, 'updateRole'])->name('api.pages.members.update_role');
        // Révoquer un collaborateur de la page professionnelle
        Route::delete('/{page}/members/{member}', [PageMemberController::class, 'revoke'])->name('api.pages.members.revoke');
        // Quitter l'équipe d'une page professionnelle
        Route::post('/{page}/leave', [PageMemberController::class, 'leave'])->name('api.pages.leave');

        // Dossiers de certification entreprise (PG-05 & PG-07)
        // Consulter l'état de la demande de certification de la page entreprise
        Route::get('/{page}/certification', [PageCertificationController::class, 'show'])->name('api.pages.certification.show');
        // Historique des audits et demandes de certification de la page
        Route::get('/{page}/certification/history', [PageCertificationController::class, 'history'])->name('api.pages.certification.history');
        // Déposer un dossier de certification avec documents d'entreprise
        Route::post('/{page}/certification', [PageCertificationController::class, 'store'])->name('api.pages.certification.store');
        // Régler les frais de traitement du dossier de certification entreprise
        Route::post('/{page}/certification/payment', [PageCertificationController::class, 'initiatePayment'])->name('api.pages.certification.payment');
    });

    // Alias profil utilisateur pour retrouver ses pages
    Route::get('/me/pages', [PageController::class, 'myPages'])->name('api.me.pages');

    // Réponse aux invitations de membres
    // Accepter l'invitation à rejoindre l'équipe d'une page professionnelle
    Route::post('/page-members/{member}/accept', [PageMemberController::class, 'accept'])->name('api.page_members.accept');
    // Décliner l'invitation à rejoindre une page professionnelle
    Route::post('/page-members/{member}/decline', [PageMemberController::class, 'decline'])->name('api.page_members.decline');

    // Corrections et téléchargement de documents de certification
    // Soumettre à nouveau des pièces justificatives corrigées pour la certification
    Route::post('/pages/certifications/{certification}/resubmit', [PageCertificationController::class, 'resubmit'])->name('api.pages.certifications.resubmit');
    // Télécharger un document justificatif du dossier de certification
    Route::get('/pages/certifications/{certification}/documents/{key}', [PageCertificationController::class, 'downloadDocument'])->name('api.pages.certifications.documents.download');

    // --------------------------------------------------------------------------
    // MODULE 21 : NOTIF — NOTIFICATIONS IN-APP & DEVICE TOKENS PUSH
    // --------------------------------------------------------------------------
    Route::prefix('notifications')->group(function () {
        // Récupérer la liste paginée des notifications de l'utilisateur
        Route::get('/', [NotificationController::class, 'index'])->name('api.notifications.index');
        // Marquer l'ensemble des notifications comme lues
        Route::post('/read', [NotificationController::class, 'markAllAsRead'])->name('api.notifications.mark_all_read');
        // Alias pour marquer toutes les notifications comme lues
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('api.notifications.mark_all_read_alias');
        // Marquer une notification spécifique comme lue
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('api.notifications.mark_read');
        // Enregistrer ou mettre à jour un jeton push FCM/APNS pour cet appareil
        Route::post('/device-token', [DeviceTokenController::class, 'store'])->name('api.notifications.device_token.store');
        // Supprimer le jeton push lors de la déconnexion de l'appareil
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy'])->name('api.notifications.device_token.destroy');
    });

    // --------------------------------------------------------------------------
    // MODULE 18 : SUP — SIGNALEMENTS UNIVERSELS & RECOURS DE MODÉRATION
    // --------------------------------------------------------------------------
    Route::prefix('reports')->group(function () {
        // Signaler un contenu (post, reel, story, commentaire, profil, annonce) pour modération
        Route::post('/', [ReportController::class, 'store'])->name('api.reports.store');
        // Consulter l'historique et le suivi de mes signalements
        Route::get('/mine', [ReportController::class, 'mine'])->name('api.reports.mine');
    });

    Route::prefix('moderation/appeals')->group(function () {
        // Déposer un recours contre une décision ou sanction de modération
        Route::post('/', [ModerationAppealController::class, 'store'])->name('api.moderation.appeals.store');
        // Consulter la liste de mes recours de modération et leur statut
        Route::get('/', [ModerationAppealController::class, 'mine'])->name('api.moderation.appeals.mine');
    });

    // --------------------------------------------------------------------------
    // MODULE 18 : SUP — BILLETTERIE DE SUPPORT CLIENT
    // --------------------------------------------------------------------------
    Route::prefix('support/tickets')->group(function () {
        // Créer un nouveau ticket de support d'assistance client
        Route::post('/', [SupportTicketController::class, 'store'])->name('api.support.tickets.store');
        // Lister l'historique de mes tickets de support client
        Route::get('/mine', [SupportTicketController::class, 'mine'])->name('api.support.tickets.mine');
        // Consulter le fil d'un ticket de support et les réponses de l'assistance
        Route::get('/{id}', [SupportTicketController::class, 'show'])->name('api.support.tickets.show');
        // Répondre ou ajouter un message/pièce jointe dans un ticket de support
        Route::post('/{id}/messages', [SupportTicketController::class, 'reply'])->name('api.support.tickets.reply');
        // Évaluer la qualité de la prise en charge d'un ticket résolu
        Route::post('/{id}/rate', [SupportTicketController::class, 'rate'])->name('api.support.tickets.rate');
        // Rouvrir un ticket de support clôturé si le problème persiste
        Route::post('/{id}/reopen', [SupportTicketController::class, 'reopen'])->name('api.support.tickets.reopen');
        // Télécharger une pièce jointe rattachée à un ticket de support
        Route::get('/{ticketId}/attachments/{attachmentId}', [SupportTicketController::class, 'downloadAttachment'])
            ->name('api.support.tickets.attachments.download');
    });

    // Consulter les sanctions disciplinaires ou avertissements actifs sur mon compte
    Route::get('/me/sanctions', [SupportTicketController::class, 'mySanctions'])->name('api.me.sanctions');
    // Alias pour consulter ses sanctions de modération
    Route::get('/support/sanctions/mine', [SupportTicketController::class, 'mySanctions'])->name('api.support.sanctions.mine');
    // Soumettre une réclamation formelle d'atteinte aux droits d'auteur (DMCA)
    Route::post('/support/copyright-claims', [CopyrightClaimController::class, 'store'])->name('api.support.copyright-claims.store');
    // Signaler une atteinte aux droits de marque ou droits voisins
    Route::post('/support/rights-infringements', [CopyrightClaimController::class, 'store'])->name('api.support.rights-infringements.store');

    // --------------------------------------------------------------------------
    // MODULE 19 : SY — SYNCHRONISATION HORS LIGNE ET RÉSILIENCE
    // --------------------------------------------------------------------------
    // Synchroniser en masse les actions effectuées hors ligne (queue locale mobile)
    Route::post('/sync/flush', [OfflineSyncController::class, 'flush'])->name('api.sync.flush');

    // --------------------------------------------------------------------------
    // MODULE 19 : SY — TÉLÉVERSEMENT REPRENABLE PAR MORCEAUX (PROTOCOLE TUS v1.0.0)
    // --------------------------------------------------------------------------
    Route::prefix('uploads/tus')->group(function () {
        // Créer une nouvelle session de téléversement reprenable TUS
        Route::post('/', [TusUploadController::class, 'create'])->name('api.uploads.tus.create');
        // Vérifier l'offset actuel et l'avancement d'un téléversement TUS
        Route::match(['head', 'get'], '/{id}', [TusUploadController::class, 'head'])->name('api.uploads.tus.head');
        // Envoyer un morceau (chunk binaire) vers le téléversement TUS actif
        Route::patch('/{id}', [TusUploadController::class, 'patch'])->name('api.uploads.tus.patch');
        // Interrompre et purger une session de téléversement TUS
        Route::delete('/{id}', [TusUploadController::class, 'delete'])->name('api.uploads.tus.delete');
        // Finaliser et valider l'intégrité du fichier téléversé via TUS
        Route::post('/{id}/finalize', [TusUploadController::class, 'finalize'])->name('api.uploads.tus.finalize');
    });

    // --------------------------------------------------------------------------
    // MODULE 3 : LG — LÉGAL, RGPD & EXPORT DES DONNÉES PERSONNELLES
    // --------------------------------------------------------------------------
    Route::prefix('user/data-export')->group(function () {
        // Demander un export complet de ses données personnelles (conformité RGPD / LG-15)
        Route::post('/', [DataExportController::class, 'requestExport'])->name('api.user.data_export.request');
        // Consulter le statut de préparation de l'archive de données personnelles
        Route::get('/status', [DataExportController::class, 'status'])->name('api.user.data_export.status');
        // Télécharger l'archive ZIP chiffrée contenant les données personnelles
        Route::get('/download/{id}', [DataExportController::class, 'download'])->name('api.user.data_export.download');
        // Annuler une demande d'export de données en cours
        Route::post('/{id}/cancel', [DataExportController::class, 'cancel'])->name('api.user.data_export.cancel');
    });

    // Alias pour annuler une demande d'export de données personnelles
    Route::post('/settings/export/{id}/cancel', [DataExportController::class, 'cancel'])->name('api.settings.export.cancel');

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
// Point d'entrée des routes du back-office d'administration (BANGO-ADMIN)
Route::prefix('admin')->name('api.admin.')->group(base_path('routes/admin.php'));
// Point d'entrée versionné (v1) du back-office d'administration
Route::prefix('admin/v1')->name('api.admin.v1.')->group(base_path('routes/admin.php'));

// Alias routes pour l'onboarding collaborateur (sans le préfixe /admin)
Route::prefix('auth/invitations')->group(function () {
    // Valider le jeton d'invitation d'un administrateur ou collaborateur
    Route::get('/{token}', [AdminInvitationController::class, 'show']);
    // Accepter l'invitation et initialiser l'onboarding collaborateur
    Route::post('/{token}/accept', [AdminInvitationController::class, 'accept']);
    // Définir le mot de passe du compte administrateur invité
    Route::post('/{token}/password', [AdminInvitationController::class, 'password']);
    // Configurer la double authentification (2FA TOTP obligatoire) pour l'administrateur
    Route::post('/{token}/totp', [AdminInvitationController::class, 'totp']);
    // Finaliser l'onboarding et activer le compte administrateur
    Route::post('/{token}/complete', [AdminInvitationController::class, 'complete']);
});
