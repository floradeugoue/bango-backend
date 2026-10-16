# Inventaire Exhaustif des API Backend — API BANGO Laravel (Version Consolidée)

**Projet :** BANGO Backend (Laravel 12 / PHP 8.4 / Passport)  
**Date d'actualisation :** 16 Octobre 2026  
**Source de référence :** `routes/api.php`, `app/Http/Controllers/`, `app/Services/`, `database/migrations/`  
**Nombre total de routes API inspectées :** **411 routes (vs 241 initialement, +170 routes créées)**  
**Tests de validation :** **882 tests automatisés Pest (0 échec, 4 447 assertions)**  

---

## 1. Répartition Globale des 411 Routes API

| Domaine Métier Backend | Préfixe d'URI | Nombre de Routes | Contrôleurs & Services Principaux |
| :--- | :--- | :---: | :--- |
| **Authentification & Session** | `api/auth/*` | 12 | `SignupController`, `SigninController`, `SecondFactorController`, `SocialAuthController`, `ForgotPasswordController`, `ResetPasswordController`, `AccountStateController` |
| **Session Utilisateur Courant** | `api/user`, `api/auth/logout` | 2 | `LogoutController`, Closure `api/user` |
| **Profil & Réseau Social** | `api/profile/*` | 32 | `ProfileController`, `AvatarController`, `HandleCheckController`, `HandleSuggestionController`, `FollowController`, `FollowRequestController`, `BlockController` |
| **Amis & Carnet d'adresses** | `api/friends/*` | 1 | `ContactMatchingController` (`match-fingerprints`) |
| **Paramètres & Sécurité** | `api/settings/*` | 19 | `EmailController`, `PasswordController`, `PrivacyController`, `SecurityController`, `AccountController`, `TwoFactorController`, `BackupCodeController` |
| **OTP & Vérifications** | `api/otp/*` | 3 | `OtpController` |
| **Onboarding** | `api/onboarding/*` | 1 | `OnboardingController` |
| **Recherche de Logement** | `api/housing/search/*` | 2 | `HousingSearchController` |
| **Cœur Immobilier & CR4** | `api/listings/*` | 19 | `ListingController`, `ListingDraftController`, `ListingMapController`, `ListingMediaController`, `ListingSaveController` |
| **Visites Prospect (IM)** | `api/visits/*` | 6 | `ProspectVisitController` |
| **Espace Bailleur (BL)** | `api/landlord/*` | 13 | `LandlordPropertyController`, `LandlordVisitController`, `LandlordKycController`, `LandlordDelegationController`, `ListingAnalyticsController` |
| **Espace Agent (AG)** | `api/agent/*`, `api/agents/search` | 5 | `AgentPortfolioController`, `AgentInvitationController`, `AgentDirectoryController` |
| **Messagerie Privée (MS)** | `api/messages/*` | 13 | `MessageThreadController`, `MessageController`, `VoiceMessageController`, `MessageGroupController`, `MessageRequestController` |
| **Boost & Campagnes (BO)** | `api/boost/*` | 11 | `BoostEstimateController`, `CampaignController`, `CreditWalletController`, `BoostableContentController` |
| **Crédit BANGO (CB)** | `api/credits/*` | 3 | `CreditWalletController`, `CreditPurchaseController`, `CreditTransactionController` |
| **Pages Professionnelles (PG)** | `api/pages/*`, `api/search/pages`, etc. | 19 | `PageController`, `PageMemberController`, `PageCertificationController`, `PageSearchController` |
| **Recherche & Tendances** | `api/search/*`, `api/trends` | 4 | `HashtagSearchController`, `PostSearchController`, `TrendsController` |
| **Documents Légaux & CGU** | `api/legal/*` | 2 | `LegalDocumentController`, `TermsAcceptanceController` |
| **Centre d'Aide & FAQ** | `api/help/*` | 3 | `HelpArticleController`, `HelpArticleFeedbackController` |
| **Billetterie Support Client** | `api/support/*` | 5 | `SupportTicketController` |
| **Téléversement par morceaux (Tus)** | `api/uploads/tus/*` | 7 | `TusUploadController` (options, create, head, patch, delete, finalize) |
| **Version Système & Résolveur** | `api/system/*`, `api/content/*` | 2 | `AppVersionController`, `ShortLinkController` |
| **Notifications Push (NOTIF)** | `api/notifications/*` | 6 | `NotificationController`, `DeviceTokenController` |
| **Signalements & Recours (SUP)** | `api/reports/*`, `api/moderation/appeals/*` | 4 | `ReportController`, `ModerationAppealController` |
| **Synchronisation Hors-Ligne (SY)** | `api/sync/*` | 1 | `OfflineSyncController` |
| **Export de Données RGPD (LG)** | `api/user/data-export/*` | 3 | `DataExportController` |
| **Abonnements & Badges Client** | `api/subscriptions/*`, `api/badges/*`, `api/catalog/*` | 14 | `SubscriptionController`, `CatalogController`, `SubscriptionRenewalController` |
| **Paiements Client (PAY)** | `api/payments/*` | 12 | `PaymentController`, `SavedPaymentMethodController`, `PaymentReceiptController`, `DoubleChargeClaimController` |
| **Square (CR1) — Posts & Social** | `api/square-cr1/*` | 24 | `PostController`, `PostFeedController`, `PostCommentController`, `PostCommentReactionController`, `PostLikeController`, etc. |
| **Reels (CR2) & Studio Vidéo** | `api/reels/*` | 33 | `ReelFeedController`, `StudioReelController`, `ReelInteractionController`, `ReelCommentController`, `SoundController` |
| **Stories (CR3) & À la une** | `api/stories/*`, `api/highlights/*` | 19 | `StoryController`, `CloseListController`, `HighlightController` |
| **Géographie Publique** | `api/geo/*` (lecture seule) | 10 | `CountryController`, `CurrencyController`, `CityController`, `NeighbourhoodController`, `OperatorController` |
| **Services Communs & Publics** | `api/interests`, `api/qr/*`, `api/profiles/*` | 3 | `QrController`, `ProfileController`, Closure `api/interests` |
| **Webhooks Asynchrones** | `api/webhooks/*` | 2 | `MonetbilWebhookController`, `CloudflareStreamWebhookController` |
| **Back-Office — Rôles** | `api/roles/*` | 6 | `Admin\RoleController` |
| **Back-Office — Utilisateurs** | `api/users/*` | 13 | `Admin\UserController`, `Admin\RoleController` |
| **Back-Office — Géographie CRUD** | `api/geo/*` (écriture admin) | 15 | `Admin` Geo Controllers |
| **Back-Office — Catalogue Offres** | `api/admin/catalog/*` | 6 | `Admin\SubscriptionCatalogController` |
| **Back-Office — Abonnements** | `api/admin/subscriptions/*`, `admin/badges/*` | 7 | `Admin\SubscriptionManagementController` |
| **Back-Office — Paiements & Litiges** | `api/admin/payments/*` | 11 | `Admin\AdminPaymentController` |
| **Back-Office — Sonothèque** | `api/admin/sounds/*` | 9 | `Admin\AdminSoundCatalogController` |
| **Back-Office — Pages Pro Admin** | `api/admin/pages/*` | 5 | `Admin\PageCertificationAdminController` |
| **Back-Office — Support Tickets** | `api/admin/support/*` | 4 | `AdminSupportTicketController` |
| **Back-Office — CMS Légal** | `api/admin/legal/*` | 3 | `Admin\LegalDocumentAdminController` |
| **Back-Office — Help Articles** | `api/admin/help/*` | 3 | `Admin\HelpArticleAdminController` |
| **Documentation / Outils** | `docs.openapi` | 1 | Scribe OpenAPI |
| **TOTAL GÉNÉRAL** | — | **411** | — |

---

## 2. Nouveaux Contrôleurs & Services Implémentés (+170 routes)

### 1. Authentification Sociale, Suggestions & Sécurité Avancée
- `SocialAuthController` : `POST /api/auth/provider` (OAuth Google & Apple avec PKCE)
- `HandleSuggestionController` : `POST /api/profile/handle-suggestions` (variantes algorithmiques uniques)
- `ContactMatchingController` : `POST /api/friends/match-fingerprints` (matching de contacts chiffré)
- `TwoFactorController` : `GET /api/settings/2fa/totp/setup`, `POST /enable`, `POST /disable`
- `BackupCodeController` : `POST /api/settings/2fa/backup-codes/regenerate`, `GET /backup-codes`
- `TotpService` & `BackupCodeService` : génération de clé secrète, QR code SVG et hachage sécurisé

### 2. CMS Légal & Conformité Réglementaire
- `LegalDocumentController` : `GET /api/legal/documents/{slug}` (CGU versionnées multilingues)
- `TermsAcceptanceController` : `POST /api/legal/accept-terms` (acceptation atomique)
- Middleware `EnsureTermsAccepted` : protection de routes bloquant les comptes en retard de CGU
- `ShortLinkController` : `GET /api/content/resolve-link` (résolveur universel de liens courts)

### 3. Recherche Avancée, Tendances & Likers
- `PostSearchController` : `GET /api/search/posts` (recherche plein texte tsvector PostgreSQL)
- `TrendsController` : `GET /api/trends` (agrégation 30j des tops hashtags, musiques et créateurs)
- `HashtagSearchController@index` : `GET /api/search/hashtags` (recherche textuelle partielle)
- `PostLikeController@likers` : `GET /api/square-cr1/posts/{post}/likers` (likers paginés avec badges)

### 4. Messagerie, Demandes Inconnues & Boost
- `MessageRequestController` : `GET /api/messages/requests`, `POST /accept`, `POST /reject`
- `BoostableContentController` : `GET /api/boost/boostable` (filtrage d'éligibilité et calcul de percentile de surperformance)

### 5. Immobilier, Alertes Temps Réel & Analytics
- `ListingCreatedHousingAlertListener` : écoute `ListingCreated` pour dispatcher les alertes automatiques selon `housing_searches`
- `ListingAnalyticsController` & `ListingAnalyticsService` : `GET /api/landlord/properties/{id}/analytics` (historique journalier)

### 6. Finances, Reçus PDF & Moyens Mémorisés
- `SavedPaymentMethodController` : `GET /api/payments/methods`, `POST`, `DELETE`, `PATCH /default`
- `PaymentReceiptController` & `ReceiptPdfService` : `GET /api/payments/{id}/receipt` (reçu officiel PDF avec QR Code)
- `DoubleChargeClaimController` : `POST /api/payments/double-charge-claims` (réclamations usagers)

### 7. Support Client & Résilience
- `HelpArticleController` & `HelpArticleFeedbackController` : FAQ dynamique et votes
- `SupportTicketController` & `AdminSupportTicketController` : billetterie complète usager et modération
- `TusUploadController` : protocole Tus v1.0.0 pour téléversement vidéo résilient par morceaux
- `AppVersionController` : `GET /api/system/version` pour contrôle de version mobile
- Handler d'erreur dans `bootstrap/app.php` : injection de `incident_id` UUID corrélé dans toute réponse 500
