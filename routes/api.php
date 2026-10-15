<?php

use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSoundCatalogController;
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
use App\Http\Controllers\Media\MediaUploadController;
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
use App\Http\Controllers\Reel\CloudflareStreamWebhookController;
use App\Http\Controllers\Reel\ReelCommentController;
use App\Http\Controllers\Reel\ReelFeedController;
use App\Http\Controllers\Reel\ReelInteractionController;
use App\Http\Controllers\Reel\SoundController;
use App\Http\Controllers\Reel\StudioReelController;
use App\Http\Controllers\Search\HashtagSearchController;
use App\Http\Controllers\Search\PeopleSearchController;
use App\Http\Controllers\Search\PlaceSearchController;
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

// Webhook Cloudflare Stream pour le suivi asynchrone du transcodage vidéo (RL / CR2)
Route::post('/webhooks/cloudflare-stream', [CloudflareStreamWebhookController::class, 'handle'])->name('api.webhooks.cloudflare_stream');

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
        // Obtenir le statut en temps réel d'un paiement (polling 120s / reprise réseau)
        Route::get('/{id}/status', [PaymentController::class, 'status']);
        // Annuler un paiement en attente
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel']);
        // Consulter l'historique paginé des transactions du client
        Route::get('/history', [PaymentController::class, 'history']);
    });

    // --------------------------------------------------------------------------
    // SQUARE — CR1 (Création, Médias, Carrousel, Recherche, Feed & Actions SQ-15)
    // --------------------------------------------------------------------------
    Route::prefix('square-cr1')->group(function () {
        // Fil Square paginé (CR-11 et flux découverte, abonnés, voisinage)
        Route::get('/square/feed', [PostFeedController::class, 'index'])->name('api.square.feed');

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

        // Rechercher des personnes pour les identifier sur une photo ou les mentionner (CR-08)
        Route::get('/search/people', [PeopleSearchController::class, 'index'])->name('api.search.people');

        // Rechercher et lister les hashtags tendances ordonnés par volume de publications (CR-07)
        Route::get('/search/hashtags/trending', [HashtagSearchController::class, 'trending'])->name('api.search.hashtags.trending');

        // Rechercher des lieux contextuels réels issus de la base géographique BANGO (CR-09)
        Route::get('/search/places', [PlaceSearchController::class, 'index'])->name('api.search.places');
    });

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
        // Lister et filtrer toutes les transactions de paiement de la plateforme
        Route::get('/transactions', [AdminPaymentController::class, 'index']);
        // Afficher les détails complets d'une transaction de paiement
        Route::get('/transactions/{id}', [AdminPaymentController::class, 'show']);
        // Consulter le journal d'événements et d'audits d'une transaction
        Route::get('/transactions/{id}/events', [AdminPaymentController::class, 'events']);
        // Rembourser manuellement une transaction payée
        Route::post('/transactions/{id}/refund', [AdminPaymentController::class, 'refund']);

        // Litiges & Contestations
        // Lister l'ensemble des litiges et contestations de paiement
        Route::get('/disputes', [AdminPaymentController::class, 'disputes']);
        // Déclarer un nouveau litige de paiement pour investigation
        Route::post('/disputes', [AdminPaymentController::class, 'createDispute']);
        // Consulter les détails d'un litige spécifique
        Route::get('/disputes/{id}', [AdminPaymentController::class, 'showDispute']);
        // Mettre à jour le statut d'un litige (en cours, résolu, rejeté)
        Route::post('/disputes/{id}/status', [AdminPaymentController::class, 'updateDisputeStatus']);
        // Ajouter une note interne d'investigation sur un litige
        Route::post('/disputes/{id}/notes', [AdminPaymentController::class, 'addDisputeNote']);

        // Réclamations Doubles Débits
        // Lister toutes les réclamations de double débit soumises
        Route::get('/double-charge-claims', [AdminPaymentController::class, 'doubleChargeClaims']);
        // Enregistrer une nouvelle réclamation de double débit
        Route::post('/double-charge-claims', [AdminPaymentController::class, 'createDoubleChargeClaim']);
    });

    // --------------------------------------------------------------------------
    // REELS SOUND CATALOG (Administration & alimentation réelle du catalogue audio)
    // --------------------------------------------------------------------------
    Route::prefix('admin/sounds')->group(function () {
        // Lister tous les sons du catalogue audio (actifs, inactifs, supprimés)
        Route::get('/', [AdminSoundCatalogController::class, 'index'])->name('api.admin.sounds.index');
        // Importer et ajouter un nouveau son au catalogue avec son fichier audio
        Route::post('/', [AdminSoundCatalogController::class, 'store'])->name('api.admin.sounds.store');
        // Consulter les métadonnées et droits d'un son du catalogue
        Route::get('/{id}', [AdminSoundCatalogController::class, 'show'])->name('api.admin.sounds.show');
        // Mettre à jour les métadonnées ou remplacer le fichier audio d'un son
        Route::post('/{id}', [AdminSoundCatalogController::class, 'update'])->name('api.admin.sounds.update');
        // Activer ou désactiver la visibilité d'un son dans le catalogue
        Route::patch('/{id}/publication', [AdminSoundCatalogController::class, 'togglePublication'])->name('api.admin.sounds.publication');
        // Mettre à jour les licences, la provenance et les droits de diffusion d'un son
        Route::patch('/{id}/rights', [AdminSoundCatalogController::class, 'updateRights'])->name('api.admin.sounds.rights');
        // Désactiver et masquer un son du catalogue (soft delete)
        Route::delete('/{id}', [AdminSoundCatalogController::class, 'removeFromCatalog'])->name('api.admin.sounds.remove');
        // Restaurer un son précédemment retiré du catalogue
        Route::post('/{id}/restore', [AdminSoundCatalogController::class, 'restore'])->name('api.admin.sounds.restore');
        // Supprimer définitivement un son et purger son fichier audio
        Route::delete('/{id}/force', [AdminSoundCatalogController::class, 'destroy'])->name('api.admin.sounds.destroy');
    });
});
