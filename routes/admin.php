<?php

use App\Http\Controllers\Admin\Account\AdminAccountGdprController;
use App\Http\Controllers\Admin\Account\AdminAccountOperationsController;
use App\Http\Controllers\Admin\Account\AdminAccountSanctionController;
use App\Http\Controllers\Admin\Account\AdminAccountSecurityController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSoundCatalogController;
use App\Http\Controllers\Admin\Audit\AdminAuditController;
use App\Http\Controllers\Admin\Audit\AdminRoleGovernanceController;
use App\Http\Controllers\Admin\Auth\AdminAccessRequestController;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Auth\AdminDeviceController;
use App\Http\Controllers\Admin\Auth\AdminInvitationController;
use App\Http\Controllers\Admin\Auth\AdminPasswordResetController;
use App\Http\Controllers\Admin\Dashboard\AdminDirectionDashboardController;
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
use App\Http\Controllers\Admin\Settings\AdminSystemSettingsController;
use App\Http\Controllers\Admin\Shell\AdminNotificationController;
use App\Http\Controllers\Admin\Shell\AdminSearchController;
use App\Http\Controllers\Admin\Shell\AdminShellController;
use App\Http\Controllers\Admin\Shell\AdminWorkspaceController;
use App\Http\Controllers\Admin\Stats\AdminStrategicStatsController;
use App\Http\Controllers\Admin\SubscriptionCatalogController;
use App\Http\Controllers\Admin\SubscriptionManagementController;
use App\Http\Controllers\Admin\Support\AdminSupportHelpCenterController;
use App\Http\Controllers\Admin\Support\AdminSupportTemplateController;
use App\Http\Controllers\Admin\Support\AdminSupportTicketOperationsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Boost\CreditWalletController;
use App\Http\Controllers\Geo\CityController;
use App\Http\Controllers\Geo\CountryController;
use App\Http\Controllers\Geo\CurrencyController;
use App\Http\Controllers\Geo\NeighbourhoodController;
use App\Http\Controllers\Geo\OperatorController;
use App\Http\Controllers\Page\Admin\PageCertificationAdminController;
use App\Http\Controllers\Support\AdminSupportTicketController;
use Illuminate\Support\Facades\Route;

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

    // Étape 1 : définition du mot de passe et génération du secret TOTP
    Route::post('/invitations/{token}/password', [AdminInvitationController::class, 'password'])
        ->name('auth.invitations.password');

    // Étape 2 : validation du premier code TOTP et génération des 10 codes de secours
    Route::post('/invitations/{token}/totp', [AdminInvitationController::class, 'totp'])
        ->name('auth.invitations.totp');

    // Étape 3 : finalisation de l'activation avec 2FA validé
    Route::post('/invitations/{token}/complete', [AdminInvitationController::class, 'complete'])
        ->name('auth.invitations.complete');

    // Demander l'envoi d'un lien sécurisé de réinitialisation de mot de passe par email
    Route::post('/password/forgot', [AdminPasswordResetController::class, 'forgot'])
        ->name('auth.password.forgot');

    // Réinitialisation de mot de passe opérateur selon contrat API: /auth/password/reset/{token}
    // Afficher le formulaire de réinitialisation de mot de passe opérateur
    Route::get('/password/reset/{token}', [AdminPasswordResetController::class, 'showReset'])
        ->name('auth.password.reset.show');
    // Soumettre le nouveau mot de passe opérateur avec le jeton de réinitialisation
    Route::post('/password/reset/{token}', [AdminPasswordResetController::class, 'reset'])
        ->name('auth.password.reset.submit');
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
        ->middleware('admin.permission:notifications:view')
        ->name('notifications.index');

    // Marquer l'ensemble des notifications internes comme lues
    Route::post('/notifications/read-all', [AdminNotificationController::class, 'readAll'])
        ->middleware('admin.permission:notifications:view')
        ->name('notifications.read_all');

    // Effectuer une recherche transverse rapide (⌘K) sur les entités de la plateforme
    Route::get('/search', [AdminSearchController::class, 'search'])
        ->middleware('admin.permission:search:transverse')
        ->name('search');

    // Consulter l'espace de travail personnel de l'opérateur (tâches, assignations, raccourcis)
    Route::get('/me/workspace', [AdminWorkspaceController::class, 'workspace'])
        ->name('me.workspace');

    // Consulter la file d'attente des demandes soumises à la validation au principe des 4 yeux
    Route::get('/me/approvals', [AdminWorkspaceController::class, 'approvals'])
        ->middleware('admin.permission:approvals:view')
        ->name('me.approvals');

    // Gestion des demandes d'accès collaborateurs (Direction / Admins)
    // Lister les demandes d'accès collaborateurs en attente d'approbation
    Route::get('/access-requests', [AdminAccessRequestController::class, 'index'])
        ->middleware('admin.permission:operators:manage')
        ->name('access_requests.index');

    // Approuver une demande d'accès collaborateur et déclencher l'invitation
    Route::post('/access-requests/{id}/approve', [AdminAccessRequestController::class, 'approve'])
        ->middleware('admin.permission:operators:manage')
        ->name('access_requests.approve');

    // Rejeter une demande d'accès collaborateur avec motif
    Route::post('/access-requests/{id}/reject', [AdminAccessRequestController::class, 'reject'])
        ->middleware('admin.permission:operators:manage')
        ->name('access_requests.reject');

    // Gestion des invitations collaborateurs (Direction / Admins)
    // Lister les invitations collaborateur envoyées et leur statut
    Route::get('/invitations', [AdminInvitationController::class, 'index'])
        ->middleware('admin.permission:operators:manage')
        ->name('invitations.index');

    // Créer et envoyer une nouvelle invitation collaborateur par email
    Route::post('/invitations', [AdminInvitationController::class, 'store'])
        ->middleware('admin.permission:operators:manage')
        ->name('invitations.store');

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

        // 4.7 Lecture / streaming direct d'une pièce de preuve
        Route::get('/evidence/{id}/stream', [AdminModerationEvidenceController::class, 'stream'])
            ->middleware('admin.permission:moderation:view')
            ->name('evidence.stream');

        // 4.7 Téléversement sécurisé de pièces probatoires
        Route::post('/uploads', [AdminModerationEvidenceController::class, 'upload'])
            ->middleware('admin.permission:moderation:view')
            ->name('uploads');

        // 4.8 Historique des décisions prises par l'opérateur connecté
        Route::get('/decisions/mine', [AdminModerationQueueController::class, 'myDecisions'])
            ->middleware('admin.permission:moderation:view')
            ->name('decisions.mine');

        // 4.8 Export contrôlé de l'activité de l'opérateur selon le contrat (/moderation/decisions/mine/export)
        Route::post('/decisions/mine/export', [AdminModerationQueueController::class, 'export'])
            ->middleware('admin.permission:moderation:export')
            ->name('decisions.mine.export');

        // Alias historique
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
        // Lister tous les rôles administratifs et leurs permissions
        Route::get('/', [RoleController::class, 'index'])->name('index');
        // Créer un nouveau rôle avec attribution des permissions
        Route::post('/', [RoleController::class, 'store'])->name('store');
        // Consulter les détails d'un rôle et sa matrice de permissions
        Route::get('/{role}', [RoleController::class, 'show'])->name('show');
        // Mettre à jour les libellés et permissions d'un rôle
        Route::put('/{role}', [RoleController::class, 'update'])->name('update');
        // Supprimer un rôle administratif non critique
        Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
        // Lister les opérateurs ou utilisateurs assignés à un rôle spécifique
        Route::get('/{role}/users', [RoleController::class, 'users'])->name('users');
    });

    // ------------------------------------------------------------------
    // MODULE 2 : GESTION DES UTILISATEURS (Strictement réservé Super-Admin / Direction)
    // ------------------------------------------------------------------
    Route::prefix('users')->name('users.')->middleware('admin.permission:operators:manage')->group(function () {
        // Lister et filtrer l'ensemble des comptes utilisateurs de la plateforme
        Route::get('/', [UserController::class, 'index'])->name('index');
        // Consulter la fiche complète d'un utilisateur par son ID
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        // Mettre à jour les informations administratives d'un utilisateur
        Route::patch('/{user}', [UserController::class, 'update'])->name('update');
        // Supprimer définitivement un compte utilisateur (soft/hard delete)
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');

        // Obtenir le rôle administratif actuellement attribué à un utilisateur
        Route::get('/{user}/role', [RoleController::class, 'getUserRole'])->name('role.get');
        // Modifier le rôle administratif d'un utilisateur
        Route::patch('/{user}/role', [RoleController::class, 'updateUserRole'])->name('role.update');

        // Suspendre temporairement l'accès d'un utilisateur à la plateforme
        Route::post('/{user}/suspend', [UserController::class, 'suspend'])->name('suspend');
        // Lever la suspension d'un compte utilisateur
        Route::post('/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('unsuspend');
        // Bloquer définitivement un utilisateur pour motif disciplinaire grave
        Route::post('/{user}/block', [UserController::class, 'block'])->name('block');
        // Débloquer un compte utilisateur précédemment banni
        Route::post('/{user}/unblock', [UserController::class, 'unblock'])->name('unblock');

        // Lister les sessions actives et appareils connectés d'un utilisateur
        Route::get('/{user}/sessions', [UserController::class, 'sessions'])->name('sessions.index');
        // Révoquer immédiatement toutes les sessions actives d'un utilisateur
        Route::delete('/{user}/sessions', [UserController::class, 'revokeAllSessions'])->name('sessions.revoke_all');
        // Révoquer une session ou un appareil spécifique d'un utilisateur
        Route::delete('/{user}/sessions/{session}', [UserController::class, 'revokeSession'])->name('sessions.revoke');
    });

    // ------------------------------------------------------------------
    // MODULE 3 : GESTION GÉOGRAPHIQUE (PAYS, DEVISES, VILLES, OPÉRATEURS)
    // ------------------------------------------------------------------
    Route::prefix('geo')->name('geo.')->middleware('admin.permission:operators:manage')->group(function () {
        // CRUD administratif des pays pris en charge (création, mise à jour, suppression)
        Route::apiResource('countries', CountryController::class)->except(['index', 'show']);
        // CRUD administratif des devises monétaires
        Route::apiResource('currencies', CurrencyController::class)->except(['index', 'show']);
        // CRUD administratif des villes
        Route::apiResource('cities', CityController::class)->except(['index', 'show']);
        // CRUD administratif des quartiers
        Route::apiResource('neighbourhoods', NeighbourhoodController::class)->except(['index', 'show']);
        // CRUD administratif des opérateurs télécoms et Mobile Money
        Route::apiResource('operators', OperatorController::class)->except(['index', 'show']);
    });

    // ------------------------------------------------------------------
    // MODULE 4 : CATALOGUE D'ABONNEMENTS & TARIFS
    // ------------------------------------------------------------------
    Route::prefix('catalog')->name('catalog.')->middleware('admin.permission:operators:manage')->group(function () {
        // Lister les produits et forfaits d'abonnement du catalogue
        Route::get('/products', [SubscriptionCatalogController::class, 'index'])->name('products.index');
        // Créer un nouveau produit d'abonnement ou badge au catalogue
        Route::post('/products', [SubscriptionCatalogController::class, 'store'])->name('products.store');
        // Consulter la fiche technique et les prix d'un produit du catalogue
        Route::get('/products/{id}', [SubscriptionCatalogController::class, 'show'])->name('products.show');
        // Mettre à jour les caractéristiques d'un produit d'abonnement
        Route::put('/products/{id}', [SubscriptionCatalogController::class, 'update'])->name('products.update');
        // Ajouter un tarif ou une tarification par pays/devise pour un produit
        Route::post('/products/{id}/prices', [SubscriptionCatalogController::class, 'addPrice'])->name('prices.store');
        // Modifier un tarif existant d'un produit
        Route::put('/prices/{priceId}', [SubscriptionCatalogController::class, 'updatePrice'])->name('prices.update');
    });

    // ------------------------------------------------------------------
    // MODULE 5 : ABONNEMENTS & BADGES (Supervision & Support)
    // ------------------------------------------------------------------
    Route::prefix('subscriptions')->name('subscriptions.')->middleware('admin.permission:verification:view')->group(function () {
        // Lister l'ensemble des souscriptions et abonnements actifs avec filtres
        Route::get('/', [SubscriptionManagementController::class, 'index'])->name('index');
        // Consulter les détails d'un abonnement et son historique de facturation
        Route::get('/{id}', [SubscriptionManagementController::class, 'show'])->name('show');
        // Suspendre manuellement un abonnement actif
        Route::post('/{id}/suspend', [SubscriptionManagementController::class, 'suspend'])->middleware('admin.permission:verification:action')->name('suspend');
        // Réactiver un abonnement suspendu
        Route::post('/{id}/reactivate', [SubscriptionManagementController::class, 'reactivate'])->middleware('admin.permission:verification:action')->name('reactivate');
        // Prolonger manuellement la période de validité d'un abonnement
        Route::post('/{id}/extend', [SubscriptionManagementController::class, 'extend'])->middleware('admin.permission:verification:action')->name('extend');
        // Révoquer définitivement un abonnement et le badge associé
        Route::post('/{id}/revoke', [SubscriptionManagementController::class, 'revoke'])->middleware('admin.permission:verification:action')->name('revoke');
    });
    // Consulter le journal d'attribution, suspension et révocation des badges
    Route::get('/badges/history', [SubscriptionManagementController::class, 'badgeHistories'])
        ->middleware('admin.permission:verification:view')
        ->name('badges.history');

    // ------------------------------------------------------------------
    // MODULE 6 : PAIEMENTS, RAPPROCHEMENT & LITIGES (Finance - Étape 4)
    // ------------------------------------------------------------------
    Route::prefix('payments')->name('payments.')->middleware('admin.permission:finance:view')->group(function () {
        // Endpoints existants préservés pour compatibilité
        // Lister les transactions financières enregistrées sur la plateforme
        Route::get('/transactions', [AdminPaymentController::class, 'index'])->name('transactions.index');
        // Consulter les détails complets d'une transaction financière
        Route::get('/transactions/{id}', [AdminPaymentController::class, 'show'])->name('transactions.show');
        // Journal des événements IPN et webhooks liés à une transaction
        Route::get('/transactions/{id}/events', [AdminPaymentController::class, 'events'])->name('transactions.events');
        // Initier une demande de remboursement sur une transaction
        Route::post('/transactions/{id}/refund', [AdminPaymentController::class, 'refund'])->middleware('admin.permission:finance:action')->name('transactions.refund');

        // Lister les litiges et contestations financières ouvertes
        Route::get('/disputes', [AdminPaymentController::class, 'disputes'])->name('disputes.index');
        // Ouvrir un dossier de litige financier
        Route::post('/disputes', [AdminPaymentController::class, 'createDispute'])->middleware('admin.permission:finance:action')->name('disputes.store');
        // Consulter la fiche d'instruction d'un litige financier
        Route::get('/disputes/{id}', [AdminPaymentController::class, 'showDispute'])->name('disputes.show');
        // Mettre à jour le statut d'un litige financier
        Route::post('/disputes/{id}/status', [AdminPaymentController::class, 'updateDisputeStatus'])->middleware('admin.permission:finance:action')->name('disputes.status');
        // Ajouter une note d'instruction interne sur un litige
        Route::post('/disputes/{id}/notes', [AdminPaymentController::class, 'addDisputeNote'])->middleware('admin.permission:finance:action')->name('disputes.notes');

        // Lister les déclarations d'incidents de double débit
        Route::get('/double-charge-claims', [AdminPaymentController::class, 'doubleChargeClaims'])->name('double_charge_claims.index');
        // Enregistrer une réclamation administrative de double débit
        Route::post('/double-charge-claims', [AdminPaymentController::class, 'createDoubleChargeClaim'])->middleware('admin.permission:finance:action')->name('double_charge_claims.store');

        // 4.1 Liste paginée des transactions
        Route::get('/', [AdminPaymentOperationsController::class, 'index'])->name('index');

        // 4.3 Grand livre des Crédits BANGO et soldes actifs
        Route::get('/credits', [AdminPaymentOperationsController::class, 'credits'])->name('credits');
        // Créditer manuellement le portefeuille de Crédits BANGO d'un utilisateur (geste commercial/ajustement)
        Route::post('/credits/wallets/{user}/deposit', [CreditWalletController::class, 'adminDeposit'])
            ->middleware('admin.permission:finance:manage')
            ->name('credits.deposit');

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
        // Lister les sons musicaux et pistes audio du catalogue public
        Route::get('/', [AdminSoundCatalogController::class, 'index'])->name('index');
        // Téléverser et ajouter un nouveau son officiel au catalogue
        Route::post('/', [AdminSoundCatalogController::class, 'store'])->middleware('admin.permission:moderation:action')->name('store');
        // Consulter la fiche détaillée d'un son et ses métadonnées
        Route::get('/{id}', [AdminSoundCatalogController::class, 'show'])->name('show');
        // Mettre à jour les informations d'un son (titre, artiste, genre)
        Route::post('/{id}', [AdminSoundCatalogController::class, 'update'])->middleware('admin.permission:moderation:action')->name('update');
        // Publier ou dépublier un son du catalogue public
        Route::patch('/{id}/publication', [AdminSoundCatalogController::class, 'togglePublication'])->middleware('admin.permission:moderation:action')->name('publication');
        // Mettre à jour les droits d'auteur et licences associées à un son
        Route::patch('/{id}/rights', [AdminSoundCatalogController::class, 'updateRights'])->middleware('admin.permission:moderation:action')->name('rights');
        // Retirer un son du catalogue sans purger les Reels existants
        Route::delete('/{id}', [AdminSoundCatalogController::class, 'removeFromCatalog'])->middleware('admin.permission:moderation:action')->name('remove');
        // Restaurer un son précédemment retiré du catalogue
        Route::post('/{id}/restore', [AdminSoundCatalogController::class, 'restore'])->middleware('admin.permission:moderation:action')->name('restore');
        // Supprimer définitivement un son et purger les fichiers audio physiques
        Route::delete('/{id}/force', [AdminSoundCatalogController::class, 'destroy'])->middleware('admin.permission:operators:manage')->name('destroy');
    };

    Route::prefix('sounds')->name('sounds.')->middleware('admin.permission:moderation:view')->group($soundCatalogRoutes);
    Route::prefix('sounds-catalog')->name('sounds-catalog.')->middleware('admin.permission:moderation:view')->group($soundCatalogRoutes);

    // ------------------------------------------------------------------
    // MODULE 8 : PAGES PROFESSIONNELLES & CERTIFICATIONS (Module 12 PG)
    // ------------------------------------------------------------------
    Route::prefix('pages')->name('pages.')->middleware('admin.permission:verification:view')->group(function () {
        // Lister les dossiers de certification d'entreprises en attente d'instruction
        Route::get('/certifications', [PageCertificationAdminController::class, 'index'])->name('certifications.index');
        // Valider ou rejeter la certification d'une page professionnelle
        Route::post('/certifications/{certification}/decide', [PageCertificationAdminController::class, 'decide'])->middleware('admin.permission:verification:action')->name('certifications.decide');
        // Suspendre la certification d'une page professionnelle
        Route::post('/{page}/suspend-certification', [PageCertificationAdminController::class, 'suspendCertification'])->middleware('admin.permission:verification:action')->name('suspend_certification');
        // Suspendre l'ensemble des activités d'une page professionnelle
        Route::post('/{page}/suspend', [PageCertificationAdminController::class, 'suspendPage'])->middleware('admin.permission:verification:action')->name('suspend');
        // Réactiver une page professionnelle suspendue
        Route::post('/{page}/unsuspend', [PageCertificationAdminController::class, 'unsuspendPage'])->middleware('admin.permission:verification:action')->name('unsuspend');
    });

    // ------------------------------------------------------------------
    // MODULE 9 : BILLETTERIE SUPPORT CLIENT & CENTRE D'AIDE (Étape 5)
    // ------------------------------------------------------------------
    Route::prefix('support')->name('support.')->group(function () {
        // Modèles de réponse types (4.10)
        Route::prefix('templates')->name('templates.')->middleware('admin.permission:support:templates')->group(function () {
            // Lister les modèles de réponses types prédéfinies
            Route::get('/', [AdminSupportTemplateController::class, 'index'])->name('index');
            // Créer un nouveau modèle de réponse type
            Route::post('/', [AdminSupportTemplateController::class, 'store'])->name('store');
            // Mettre à jour un modèle de réponse existant
            Route::put('/{id}', [AdminSupportTemplateController::class, 'update'])->name('update');
        });

        // Centre d'aide et FAQ (4.11)
        Route::prefix('help')->name('help.')->middleware('admin.permission:support:help')->group(function () {
            // Lister les articles d'aide et catégories de FAQ
            Route::get('/', [AdminSupportHelpCenterController::class, 'index'])->name('index');
            // Consulter un article du centre d'aide pour édition
            Route::get('/{id}', [AdminSupportHelpCenterController::class, 'show'])->name('show');
            // Mettre à jour le contenu ou le statut d'un article d'aide
            Route::put('/{id}', [AdminSupportHelpCenterController::class, 'update'])->name('update');
        });

        // Référentiels & Statistiques (4.8)
        // Liste des agents et opérateurs assignables aux tickets de support
        Route::get('/assignees', [AdminSupportTicketOperationsController::class, 'assignees'])
            ->middleware('admin.permission:support:view')
            ->name('assignees');

        // Règles et destinataires par défaut pour l'escalade d'un ticket
        Route::get('/escalation/default', [AdminSupportTicketOperationsController::class, 'defaultEscalation'])
            ->middleware('admin.permission:support:view')
            ->name('escalation.default');

        // Réponse ou arbitrage suite à l'escalade d'un ticket
        Route::post('/escalation/answer', [AdminSupportTicketOperationsController::class, 'answerEscalation'])
            ->middleware('admin.permission:support:escalate')
            ->name('escalation.answer');

        // Répartition statistique des modes et motifs de résolution des tickets
        Route::get('/resolutions/shares', [AdminSupportTicketOperationsController::class, 'resolutionsShares'])
            ->middleware('admin.permission:support:view')
            ->name('resolutions.shares');

        // Export CSV des tickets (4.12)
        Route::post('/exports', [AdminSupportTicketOperationsController::class, 'export'])
            ->middleware('admin.permission:support:export')
            ->name('exports');

        // Billetterie Support (4.1 - 4.7)
        Route::prefix('tickets')->name('tickets.')->group(function () {
            // Consultation et création
            // Lister les tickets de support avec pagination et filtres
            Route::get('/', [AdminSupportTicketOperationsController::class, 'index'])
                ->middleware('admin.permission:support:view')
                ->name('index');

            // Créer manuellement un ticket de support au nom d'un client
            Route::post('/', [AdminSupportTicketOperationsController::class, 'store'])
                ->middleware('admin.permission:support:action')
                ->name('store');

            // Assignation groupée ou unitaire (4.4)
            // Assigner un ou plusieurs tickets à un opérateur
            Route::post('/assign', [AdminSupportTicketOperationsController::class, 'assign'])
                ->middleware('admin.permission:support:assign')
                ->name('assign.bulk');

            // Actions ciblées par ID
            // Consulter le fil complet d'un ticket de support
            Route::get('/{id}', [AdminSupportTicketOperationsController::class, 'show'])
                ->middleware('admin.permission:support:view')
                ->name('show');

            // Répondre officiellement à un utilisateur sur son ticket
            Route::post('/{id}/replies', [AdminSupportTicketOperationsController::class, 'reply'])
                ->middleware('admin.permission:support:reply')
                ->name('replies');

            // Ajouter une note interne privée visible uniquement des opérateurs
            Route::post('/{id}/notes', [AdminSupportTicketOperationsController::class, 'note'])
                ->middleware('admin.permission:support:notes')
                ->name('notes');

            // Marquer un ticket de support comme résolu
            Route::post('/{id}/resolve', [AdminSupportTicketOperationsController::class, 'resolve'])
                ->middleware('admin.permission:support:resolve')
                ->name('resolve');

            // Rouvrir un ticket de support précédemment résolu
            Route::post('/{id}/reopen', [AdminSupportTicketOperationsController::class, 'reopen'])
                ->middleware('admin.permission:support:resolve')
                ->name('reopen');

            // Escalader un ticket vers un niveau d'assistance supérieur
            Route::post('/{id}/escalate', [AdminSupportTicketOperationsController::class, 'escalate'])
                ->middleware('admin.permission:support:escalate')
                ->name('escalate');

            // Routes historiques conservées pour rétrocompatibilité
            // Mettre à jour le statut d'un ticket (alias rétrocompatible)
            Route::patch('/{id}/status', [AdminSupportTicketController::class, 'updateStatus'])
                ->middleware('admin.permission:support:action')
                ->name('status');

            // Assigner un ticket à un opérateur (alias rétrocompatible)
            Route::patch('/{id}/assign', [AdminSupportTicketController::class, 'assign'])
                ->middleware('admin.permission:support:action')
                ->name('assign');

            // Ajouter un message dans un ticket (alias rétrocompatible)
            Route::post('/{id}/messages', [AdminSupportTicketController::class, 'reply'])
                ->middleware('admin.permission:support:action')
                ->name('reply');
        });
    });

    // ------------------------------------------------------------------
    // MODULE 9.1 : GESTION 360° DES COMPTES & SANCTIONS (Étape 5)
    // ------------------------------------------------------------------
    // Lister les membres de l'équipe opérationnelle et leurs disponibilités
    Route::get('/team', [AdminAccountOperationsController::class, 'team'])
        ->middleware('admin.permission:accounts:view')
        ->name('team');

    Route::prefix('accounts')->name('accounts.')->group(function () {
        // Annuaire des comptes (5.1)
        Route::get('/', [AdminAccountOperationsController::class, 'index'])
            ->middleware('admin.permission:accounts:view')
            ->name('index');

        // Export administratif de la liste des comptes (5.13 bis)
        Route::post('/exports', [AdminAccountGdprController::class, 'accountsExport'])
            ->middleware('admin.permission:accounts:export')
            ->name('exports');

        // Synthèse support 360° (4.9)
        Route::get('/{id}/support-summary', [AdminAccountOperationsController::class, 'supportSummary'])
            ->middleware('admin.permission:support:view')
            ->name('support_summary');

        // Sous-onglets transverses de la fiche compte (5.3)
        // Lister les annonces immobilières publiées par le compte
        Route::get('/{id}/listings', [AdminAccountOperationsController::class, 'listings'])
            ->middleware('admin.permission:accounts:view')
            ->name('listings');

        // Lister les contenus (posts, reels, stories) publiés par le compte
        Route::get('/{id}/contents', [AdminAccountOperationsController::class, 'contents'])
            ->middleware('admin.permission:accounts:view')
            ->name('contents');

        // Historique des paiements et transactions effectués par le compte
        Route::get('/{id}/payments', [AdminAccountOperationsController::class, 'payments'])
            ->middleware('admin.permission:accounts:view')
            ->name('payments');

        // Historique des dossiers de modération impliquant ce compte
        Route::get('/{id}/cases', [AdminAccountOperationsController::class, 'cases'])
            ->middleware('admin.permission:accounts:view')
            ->name('cases');

        // Historique des tickets de support client associés à ce compte
        Route::get('/{id}/tickets', [AdminAccountOperationsController::class, 'tickets'])
            ->middleware('admin.permission:accounts:view')
            ->name('tickets');

        // Chronologie du compte (5.4)
        Route::get('/{id}/timeline', [AdminAccountOperationsController::class, 'timeline'])
            ->middleware('admin.permission:accounts:view')
            ->name('timeline');

        // Simulation d'une action / sanction sans mutation (5.5)
        Route::get('/{id}/actions/{kind}/preview', [AdminAccountOperationsController::class, 'previewAction'])
            ->middleware('admin.permission:accounts:view')
            ->name('actions.preview');

        // Sanctions et avertissements (5.6 - 5.7)
        // Appliquer une suspension temporaire ou disciplinaire sur le compte
        Route::post('/{id}/suspend', [AdminAccountSanctionController::class, 'suspend'])
            ->middleware('admin.permission:accounts:sanctions')
            ->name('suspend');

        // Lever la suspension d'un compte
        Route::post('/{id}/suspend/lift', [AdminAccountSanctionController::class, 'liftSuspension'])
            ->middleware('admin.permission:accounts:sanctions')
            ->name('suspend.lift');

        // Émettre un avertissement formel à l'encontre de l'utilisateur
        Route::post('/{id}/warnings', [AdminAccountSanctionController::class, 'warning'])
            ->middleware('admin.permission:accounts:sanctions')
            ->name('warnings');

        // Badges et Certifications (5.8 - 5.9)
        // Prolonger la durée de validité du badge professionnel de l'utilisateur
        Route::post('/{id}/badge/extend', [AdminAccountSanctionController::class, 'extendBadge'])
            ->middleware('admin.permission:accounts:badges')
            ->name('badge.extend');

        // Retirer le badge professionnel d'un utilisateur
        Route::post('/{id}/badge/remove', [AdminAccountSanctionController::class, 'removeBadge'])
            ->middleware('admin.permission:accounts:badges')
            ->name('badge.remove');

        // Octroyer la certification officielle à un utilisateur
        Route::post('/{id}/certification', [AdminAccountSanctionController::class, 'certify'])
            ->middleware('admin.permission:accounts:badges')
            ->name('certification.grant');

        // Retirer la certification d'un utilisateur
        Route::post('/{id}/certification/remove', [AdminAccountSanctionController::class, 'removeCertification'])
            ->middleware('admin.permission:accounts:badges')
            ->name('certification.remove');

        // Contact direct de l'utilisateur (5.10)
        Route::post('/{id}/contact', [AdminAccountOperationsController::class, 'contact'])
            ->middleware('admin.permission:support:action')
            ->name('contact');

        // Anonymisation RGPD à double approbation (5.11)
        // Initier une demande d'anonymisation / droit à l'oubli RGPD (1er œil)
        Route::post('/{id}/anonymize', [AdminAccountGdprController::class, 'requestAnonymization'])
            ->middleware('admin.permission:accounts:gdpr:request')
            ->name('anonymize.request');

        // Valider l'anonymisation RGPD par un second opérateur (2e œil)
        Route::post('/{id}/anonymize/approve', [AdminAccountGdprController::class, 'approveAnonymization'])
            ->middleware('admin.permission:accounts:gdpr:approve')
            ->name('anonymize.approve');

        // Opérations de sécurité (5.12)
        Route::post('/{id}/security/{kind}', [AdminAccountSecurityController::class, 'securityAction'])
            ->middleware('admin.permission:accounts:security')
            ->name('security.action');

        // Export de portabilité RGPD (5.13)
        Route::post('/{id}/exports', [AdminAccountGdprController::class, 'portabilityExport'])
            ->middleware('admin.permission:accounts:export')
            ->name('exports.portability');

        // Détail d'un compte (5.1)
        Route::get('/{id}', [AdminAccountOperationsController::class, 'show'])
            ->middleware('admin.permission:accounts:view')
            ->name('show');
    });

    // Alias direct pour la gestion des utilisateurs / certification (Point 46)
    Route::prefix('users')->name('users.')->group(function () {
        // Alias pour octroyer la certification officielle d'un utilisateur
        Route::post('/{id}/certification', [AdminAccountSanctionController::class, 'certify'])
            ->middleware('admin.permission:accounts:badges')
            ->name('certification.grant');

        // Alias pour retirer la certification d'un utilisateur
        Route::post('/{id}/certification/remove', [AdminAccountSanctionController::class, 'removeCertification'])
            ->middleware('admin.permission:accounts:badges')
            ->name('certification.remove');
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

        // Levée du verrou de retrait BANGO (Point 17)
        Route::post('/{ref}/lift-withdrawal', [AdminListingControlController::class, 'liftWithdrawal'])
            ->middleware('admin.permission:listings:status')
            ->name('lift_withdrawal');

        // Marquage d'un bien comme loué ou occupé (B.7)
        Route::post('/{ref}/rented', [AdminListingControlController::class, 'markRented'])
            ->middleware('admin.permission:listings:status')
            ->name('rented');
    });

    // Republication via convention racine /republish avec paramètre ref en body (B.6 bis alias)
    Route::post('/republish', [AdminListingControlController::class, 'republish'])
        ->middleware('admin.permission:listings:status')
        ->name('republish');

    // ==================================================================
    // MODULE 13 : DIRECTION, AUDIT CHAÎNÉ SHA-256, PARAMÈTRES & STATS (ÉTAPE 6)
    // ==================================================================

    // ------------------------------------------------------------------
    // SECTION 4 : AUDIT CHAÎNÉ CRYPTOGRAPHIQUE & GOUVERNANCE DES RÔLES
    // ------------------------------------------------------------------
    Route::prefix('audit')->name('audit.')->group(function () {
        // 4.1 Registre d'audit & vérification cryptographique
        // Consultation du registre d'audit administratif paginé et filtré
        Route::get('/', [AdminAuditController::class, 'index'])
            ->middleware('admin.permission:audit:view')
            ->name('index');

        // Vérification cryptographique de l'intégrité de la chaîne d'audit SHA-256
        Route::get('/verify', [AdminAuditController::class, 'verify'])
            ->middleware('admin.permission:audit:verify')
            ->name('verify');

        // Historique des exports d'audit réalisés
        Route::get('/exports', [AdminAuditController::class, 'exports'])
            ->middleware('admin.permission:audit:export')
            ->name('exports');

        // Prévisualisation des données d'un export d'audit avant génération
        Route::post('/exports/preview', [AdminAuditController::class, 'previewExport'])
            ->middleware('admin.permission:audit:export')
            ->name('exports.preview');

        // Génération et téléchargement sécurisé d'un export d'audit scellé
        Route::post('/exports', [AdminAuditController::class, 'export'])
            ->middleware('admin.permission:audit:export')
            ->name('exports.download');

        // Vérification de la signature cryptographique d'un fichier d'export
        Route::get('/exports/verify', [AdminAuditController::class, 'verifyExport'])
            ->middleware('admin.permission:audit:verify')
            ->name('exports.verify');

        // 4.4 & 4.5 Gouvernance des Rôles (Matrice, Prévisualisation & 4-Yeux)
        Route::prefix('roles')->name('roles.')->group(function () {
            // Matrice des rôles, permissions et habilitations des opérateurs
            Route::get('/', [AdminRoleGovernanceController::class, 'matrix'])
                ->middleware('admin.permission:roles:view')
                ->name('index');

            // Prévisualisation de l'impact de l'attribution d'un rôle
            Route::get('/assign-preview', [AdminRoleGovernanceController::class, 'assignPreview'])
                ->middleware('admin.permission:roles:view')
                ->name('assign_preview');

            // Prévisualisation de l'impact de la révocation d'un rôle
            Route::get('/revoke-preview', [AdminRoleGovernanceController::class, 'revokePreview'])
                ->middleware('admin.permission:roles:view')
                ->name('revoke_preview');

            // Création d'une demande de modification de rôle soumise au principe des 4-yeux
            Route::post('/requests', [AdminRoleGovernanceController::class, 'storeRequest'])
                ->middleware('admin.permission:roles:request')
                ->name('requests.store');

            // Confirmation ou rejet par un second administrateur (validation 4-yeux)
            Route::post('/confirm', [AdminRoleGovernanceController::class, 'confirm'])
                ->middleware('admin.permission:roles:confirm')
                ->name('confirm');
        });

        // 4.1 bis & 4.3 Détail & Tentative d'altération
        // Détail complet d'une entrée de journal d'audit cryptographique
        Route::get('/{id}', [AdminAuditController::class, 'show'])
            ->middleware('admin.permission:audit:view')
            ->name('show');

        // Enregistrement d'une tentative d'altération d'un enregistrement d'audit
        Route::post('/{id}/edit-attempt', [AdminAuditController::class, 'editAttempt'])
            ->middleware('admin.permission:audit:view')
            ->name('edit_attempt');
    });

    // Alias plats pour l'audit et la gouvernance (Section 4 & prompt)
    // Alias direct pour la prévisualisation de l'attribution d'un rôle
    Route::get('/assign-preview', [AdminRoleGovernanceController::class, 'assignPreview'])
        ->middleware('admin.permission:roles:view')
        ->name('assign_preview');

    // Alias direct pour la prévisualisation de la révocation d'un rôle
    Route::get('/revoke-preview', [AdminRoleGovernanceController::class, 'revokePreview'])
        ->middleware('admin.permission:roles:view')
        ->name('revoke_preview');

    // Alias direct pour la validation 4-yeux d'un changement de rôle
    Route::post('/confirm', [AdminRoleGovernanceController::class, 'confirm'])
        ->middleware('admin.permission:roles:confirm')
        ->name('confirm');

    // Alias direct pour l'aperçu d'un export d'audit
    Route::post('/exports/preview', [AdminAuditController::class, 'previewExport'])
        ->middleware('admin.permission:audit:export')
        ->name('exports.preview');

    // Alias direct pour la génération d'un export d'audit
    Route::post('/exports', [AdminAuditController::class, 'export'])
        ->middleware('admin.permission:audit:export')
        ->name('exports.download');

    // Alias direct pour la vérification d'intégrité d'un export d'audit
    Route::get('/exports/verify', [AdminAuditController::class, 'verifyExport'])
        ->middleware('admin.permission:audit:verify')
        ->name('exports.verify');

    // ------------------------------------------------------------------
    // SECTION 5 : PARAMÈTRES & CONFIGURATION SYSTÈME
    // ------------------------------------------------------------------
    Route::prefix('settings')->name('settings.')->group(function () {
        // 5.1 Tarification & Offres
        // Consultation de la grille tarifaire et des paramètres d'offres
        Route::get('/pricing', [AdminSystemSettingsController::class, 'pricing'])
            ->middleware('admin.permission:settings:pricing')
            ->name('pricing.index');

        // Simulation et analyse d'impact d'un changement de tarification
        Route::get('/pricing/impact', [AdminSystemSettingsController::class, 'pricingImpact'])
            ->middleware('admin.permission:settings:pricing')
            ->name('pricing.impact');

        // Proposition d'une modification de grille tarifaire (validation requise)
        Route::post('/pricing/changes', [AdminSystemSettingsController::class, 'proposePricingChange'])
            ->middleware('admin.permission:settings:pricing')
            ->name('pricing.changes');

        // 5.2 Seuils automatiques de modération
        // Consultation des seuils automatiques d'alerte et de modération
        Route::get('/thresholds', [AdminSystemSettingsController::class, 'thresholds'])
            ->middleware('admin.permission:settings:thresholds')
            ->name('thresholds.index');

        // Mise à jour des seuils automatiques de modération
        Route::put('/thresholds', [AdminSystemSettingsController::class, 'updateThresholds'])
            ->middleware('admin.permission:settings:thresholds')
            ->name('thresholds.update');

        // 5.3 Opérateurs Mobile Money
        // Liste des opérateurs de paiement mobile money et leur statut opérationnel
        Route::get('/operators', [AdminSystemSettingsController::class, 'operators'])
            ->middleware('admin.permission:settings:operators')
            ->name('operators.index');

        // Analyse d'impact en cas de suspension ou défaillance d'un opérateur
        Route::get('/operators/impact', [AdminSystemSettingsController::class, 'operatorImpact'])
            ->middleware('admin.permission:settings:operators')
            ->name('operators.impact');

        // Suspension temporaire des flux de paiement pour un opérateur
        Route::post('/operators/{id}/suspend', [AdminSystemSettingsController::class, 'suspendOperator'])
            ->middleware('admin.permission:settings:operators')
            ->name('operators.suspend');

        // Reprise des flux de paiement pour un opérateur suspendu
        Route::post('/operators/{id}/resume', [AdminSystemSettingsController::class, 'resumeOperator'])
            ->middleware('admin.permission:settings:operators')
            ->name('operators.resume');

        // 5.4 Référentiel géographique (Villes & Quartiers)
        // Liste des villes configurées dans le référentiel géographique
        Route::get('/cities', [AdminSystemSettingsController::class, 'cities'])
            ->middleware('admin.permission:settings:geo')
            ->name('cities.index');

        // Liste des quartiers et zones géographiques avec compteurs d'annonces
        Route::get('/areas', [AdminSystemSettingsController::class, 'areas'])
            ->middleware('admin.permission:settings:geo')
            ->name('areas.index');

        // Exclusion ou masquage d'une zone géographique non couverte
        Route::post('/areas/ignore', [AdminSystemSettingsController::class, 'ignoreArea'])
            ->middleware('admin.permission:settings:geo')
            ->name('areas.ignore');

        // Ajout d'un nouveau quartier rattaché à une ville
        Route::post('/areas/{city}', [AdminSystemSettingsController::class, 'createArea'])
            ->middleware('admin.permission:settings:geo')
            ->name('areas.create');

        // Modification du libellé ou des métadonnées d'un quartier
        Route::patch('/areas/{city}/{name}', [AdminSystemSettingsController::class, 'updateArea'])
            ->middleware('admin.permission:settings:geo')
            ->name('areas.update');

        // Exclusion d'un quartier par son identifiant unique
        Route::post('/areas/{id}/ignore', [AdminSystemSettingsController::class, 'ignoreArea'])
            ->middleware('admin.permission:settings:geo')
            ->name('areas.ignore_by_id');

        // 5.5 Centres d'intérêt
        // Liste des points d'intérêt et commodités de proximité
        Route::get('/interests', [AdminSystemSettingsController::class, 'interests'])
            ->middleware('admin.permission:settings:interests')
            ->name('interests.index');

        // Création d'un nouveau point d'intérêt dans le catalogue
        Route::post('/interests', [AdminSystemSettingsController::class, 'createInterest'])
            ->middleware('admin.permission:settings:interests')
            ->name('interests.store');

        // Modification d'un point d'intérêt existant
        Route::patch('/interests/{id}', [AdminSystemSettingsController::class, 'updateInterest'])
            ->middleware('admin.permission:settings:interests')
            ->name('interests.update');

        // 5.6 CGU & Versions légales
        // Liste des documents légaux, CGU et politiques de confidentialité
        Route::get('/legal', [AdminSystemSettingsController::class, 'legalDocuments'])
            ->middleware('admin.permission:settings:legal')
            ->name('legal.index');

        // Publication d'une nouvelle version officielle d'un document légal
        Route::post('/legal/{key}/publish', [AdminSystemSettingsController::class, 'publishLegalDocument'])
            ->middleware('admin.permission:settings:legal')
            ->name('legal.publish');

        // 5.7 Maintenance, Incidents & Version minimale
        // Statut des services, mode maintenance et versions minimales requises
        Route::get('/service', [AdminSystemSettingsController::class, 'service'])
            ->middleware('admin.permission:settings:maintenance')
            ->name('service.index');

        // Déclenchement d'une action opérationnelle de maintenance ou bascule d'incident
        Route::post('/service/actions', [AdminSystemSettingsController::class, 'serviceAction'])
            ->middleware('admin.permission:settings:maintenance')
            ->name('service.actions');

        // 5.8 Référentiel des délais
        // Consultation des délais réglementaires et SLA opérationnels de la plateforme
        Route::get('/delays', [AdminSystemSettingsController::class, 'delays'])
            ->middleware('admin.permission:settings:delays')
            ->name('delays.index');
    });

    // Alias plats pour les paramètres (Section 5 & prompt)
    // Alias direct pour l'analyse d'impact tarifaire
    Route::get('/impact', [AdminSystemSettingsController::class, 'pricingImpact'])
        ->middleware('admin.permission:settings:pricing')
        ->name('impact');

    // Alias direct pour proposer un changement de tarif
    Route::post('/changes', [AdminSystemSettingsController::class, 'proposePricingChange'])
        ->middleware('admin.permission:settings:pricing')
        ->name('changes');

    // Alias direct pour la mise à jour des seuils de modération
    Route::put('/thresholds', [AdminSystemSettingsController::class, 'updateThresholds'])
        ->middleware('admin.permission:settings:thresholds')
        ->name('thresholds');

    // Alias direct pour suspendre un opérateur Mobile Money
    Route::post('/suspend', [AdminSystemSettingsController::class, 'suspendOperator'])
        ->middleware('admin.permission:settings:operators')
        ->name('suspend');

    // Alias direct pour réactiver un opérateur Mobile Money
    Route::post('/resume', [AdminSystemSettingsController::class, 'resumeOperator'])
        ->middleware('admin.permission:settings:operators')
        ->name('resume');

    // Alias direct pour la liste des quartiers
    Route::get('/areas', [AdminSystemSettingsController::class, 'areas'])
        ->middleware('admin.permission:settings:geo')
        ->name('areas');

    // Alias direct pour l'ajout d'un quartier à une ville
    Route::post('/areas/{city}', [AdminSystemSettingsController::class, 'createArea'])
        ->middleware('admin.permission:settings:geo')
        ->name('areas.create_alias');

    // Alias direct pour la mise à jour d'un quartier
    Route::patch('/areas/{city}/{name}', [AdminSystemSettingsController::class, 'updateArea'])
        ->middleware('admin.permission:settings:geo')
        ->name('areas.update_alias');

    // Alias direct pour ignorer ou exclure un quartier
    Route::post('/ignore', [AdminSystemSettingsController::class, 'ignoreArea'])
        ->middleware('admin.permission:settings:geo')
        ->name('ignore');

    // Alias direct pour la création d'un point d'intérêt
    Route::post('/interests', [AdminSystemSettingsController::class, 'createInterest'])
        ->middleware('admin.permission:settings:interests')
        ->name('interests.store_alias');

    // Alias direct pour la modification d'un point d'intérêt
    Route::patch('/interests/{id}', [AdminSystemSettingsController::class, 'updateInterest'])
        ->middleware('admin.permission:settings:interests')
        ->name('interests.update_alias');

    // ------------------------------------------------------------------
    // SECTION 6 : STATISTIQUES STRATÉGIQUES & PILOTAGE
    // ------------------------------------------------------------------
    Route::prefix('stats')->name('stats.')->group(function () {
        // Vue d'ensemble des indicateurs de performance clés (KPI globaux)
        Route::get('/overview', [AdminStrategicStatsController::class, 'overview'])
            ->middleware('admin.permission:stats:view')
            ->name('overview');

        // Statistiques d'acquisition utilisateurs et de conversion des canaux
        Route::get('/acquisition', [AdminStrategicStatsController::class, 'acquisition'])
            ->middleware('admin.permission:stats:view')
            ->name('acquisition');

        // Métriques financières détaillées, revenus et volume d'affaires
        Route::get('/revenue', [AdminStrategicStatsController::class, 'revenue'])
            ->middleware('admin.permission:stats:view')
            ->name('revenue');

        // Statistiques d'activité de modération, temps de réponse et volumes
        Route::get('/moderation', [AdminStrategicStatsController::class, 'moderation'])
            ->middleware('admin.permission:stats:view')
            ->name('moderation');

        // Métriques d'engagement, churn et rétention des cohortes
        Route::get('/retention', [AdminStrategicStatsController::class, 'retention'])
            ->middleware('admin.permission:stats:view')
            ->name('retention');

        // Statistiques immobilières, liquidité du parc et délais moyens de location
        Route::get('/immo', [AdminStrategicStatsController::class, 'immo'])
            ->middleware('admin.permission:stats:view')
            ->name('immo');

        // Liste des rapports stratégiques périodiques générés
        Route::get('/reports', [AdminStrategicStatsController::class, 'reports'])
            ->middleware('admin.permission:stats:reports')
            ->name('reports.index');

        // Mise à jour ou validation d'un rapport stratégique
        Route::put('/reports/{id}', [AdminStrategicStatsController::class, 'updateReport'])
            ->middleware('admin.permission:stats:reports')
            ->name('reports.update');

        // Prévisualisation des données d'un export statistique
        Route::post('/exports/preview', [AdminStrategicStatsController::class, 'previewExport'])
            ->middleware('admin.permission:stats:export')
            ->name('exports.preview');

        // Génération et export des données statistiques au format tableur
        Route::post('/exports', [AdminStrategicStatsController::class, 'export'])
            ->middleware('admin.permission:stats:export')
            ->name('exports.download');
    });

    // Alias plat pour mise à jour des rapports (Section 6 & prompt)
    Route::put('/reports/{id}', [AdminStrategicStatsController::class, 'updateReport'])
        ->middleware('admin.permission:stats:reports')
        ->name('reports.update_alias');

    // ------------------------------------------------------------------
    // SECTION 7 : TABLEAU DE BORD GLOBAL & ONBOARDING DIRECTION
    // ------------------------------------------------------------------
    // Synthèse exécutive du tableau de bord direction
    Route::get('/dashboard', [AdminDirectionDashboardController::class, 'dashboard'])
        ->name('dashboard');

    // Export consolidé des indicateurs du tableau de bord direction
    Route::post('/dashboard/exports', [AdminDirectionDashboardController::class, 'exportDashboard'])
        ->name('dashboard.exports');

    // Téléchargement sécurisé d'un export exécutif généré
    Route::get('/exports/{id}', [AdminDirectionDashboardController::class, 'showExport'])
        ->name('exports.show');

    // Guide et statut du parcours d'onboarding de l'administrateur connecté
    Route::get('/me/onboarding', [AdminDirectionDashboardController::class, 'onboarding'])
        ->name('me.onboarding');
});
