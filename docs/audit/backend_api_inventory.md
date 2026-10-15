# Inventaire Exhaustif des API Backend — API BANGO Laravel

**Projet :** BANGO Backend (Laravel 12 / PHP 8.4 / Passport)  
**Date de l'audit :** Octobre 2026  
**Source de référence :** `routes/api.php`, `app/Http/Controllers/`, `app/Services/`, `database/migrations/`  
**Nombre total de routes API inspectées :** **241 routes**

---

## 1. Répartition Globale des Routes API

| Domaine Métier Backend | Préfixe d'URI | Nombre de Routes | Contrôleurs Principaux |
| :--- | :--- | :--- | :--- |
| **Authentification & Session** | `api/auth/*` | 11 | `SignupController`, `SigninController`, `SecondFactorController`, `ForgotPasswordController`, `ResetPasswordController`, `AccountStateController` |
| **Session Utilisateur Courant** | `api/user`, `api/auth/logout` | 2 | `LogoutController`, Closure `api/user` |
| **Profil & Réseau Social** | `api/profile/*` | 30 | `ProfileController`, `AvatarController`, `HandleCheckController`, `FollowController`, `FollowRequestController`, `BlockController` |
| **Paramètres & Sécurité** | `api/settings/*` | 13 | `EmailController`, `PasswordController`, `PrivacyController`, `SecurityController`, `AccountController` |
| **OTP & Vérifications** | `api/otp/*` | 3 | `OtpController` |
| **Onboarding** | `api/onboarding/*` | 1 | `OnboardingController` |
| **Recherche de Logement** | `api/housing/search/*` | 2 | `HousingSearchController` |
| **Abonnements & Badges Client** | `api/subscriptions/*`, `api/badges/*`, `api/catalog/*` | 14 | `SubscriptionController`, `CatalogController`, `SubscriptionRenewalController` |
| **Paiements Client** | `api/payments/*` | 6 | `PaymentController` |
| **Square (CR1) — Posts & Social** | `api/square-cr1/*` | 23 | `PostController`, `PostFeedController`, `PostCommentController`, `PostCommentReactionController`, `PostLikeController`, `PostShareController`, `PostArchiveController`, `PostAudienceController`, `PostCaptionController`, `PostCommentsStatusController`, `MediaUploadController`, `PeopleSearchController`, `HashtagSearchController`, `PlaceSearchController` |
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
| **Back-Office — Paiements & Litiges** | `api/admin/payments/*` | 9 | `Admin\AdminPaymentController` |
| **Back-Office — Sonothèque** | `api/admin/sounds/*` | 9 | `Admin\AdminSoundCatalogController` |
| **Documentation / Outils** | `docs.openapi` | 1 | Scribe OpenAPI |
| **TOTAL** | — | **241** | — |

---

## 2. Inventaire Détaillé par Domaine Métier

---

### A. Authentification, Session & Récupération (13 routes)

| Méthode | URI | Nom de Route | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `POST` | `api/auth/signup` | — | `Auth\SignupController` | `User` (`users`), `Role` | `email`, `phone`, `password`, `country_code`, `device_name` | Création de compte utilisateur, hachage bcrypt, attribution du rôle par défaut, génération de token Passport. |
| `POST` | `api/auth/signin` | — | `Auth\SigninController` | `User`, `DeviceSession` | `identifier` (email/téléphone), `password`, `device_name`, `device_id` | Validation d'identifiants, gestion des 5 échecs consécutifs (blocage 15 min), vérification de 2FA requise, émission token. |
| `POST` | `api/auth/signin/second-factor` | — | `Auth\SecondFactorController` | `User`, `Otp` | `user_id`, `code`, `trust_device` | Validation du code TOTP ou SMS 2FA pour finaliser la connexion. |
| `POST` | `api/auth/logout` | — | `Auth\LogoutController` | `OauthAccessToken` | Aucun (Bearer token) | Révocation immédiate du token Passport actif. |
| `GET` | `api/user` | — | `Closure` (routes/api.php:184) | `User`, `Interest` | Aucun (Bearer token) | Renvoie l'objet utilisateur connecté via `UserResource` avec ses centres d'intérêt chargés. |
| `POST` | `api/auth/forgot-password` | — | `Auth\ForgotPasswordController@forgotPassword` | `User`, `Otp` | `identifier` (email/téléphone), `channel` (sms/email) | Émission d'un code OTP à 6 chiffres pour la réinitialisation de mot de passe. |
| `POST` | `api/auth/forgot-password/verify` | — | `Auth\ForgotPasswordController@verify` | `Otp`, `ResetPasswordToken` | `identifier`, `code` | Validation de l'OTP et émission d'un token temporaire de réinitialisation. |
| `POST` | `api/auth/forgot-password/reset` | — | `Auth\ForgotPasswordController@reset` | `User`, `PasswordHistory` | `token`, `password`, `password_confirmation` | Contrôle de force du mot de passe, vérification d'unicité contre les 3 derniers mots de passe utilisés, mise à jour. |
| `POST` | `api/auth/reset-password/request` | — | `Auth\ResetPasswordController@requestLink` | `User` | `email` | Génération et envoi par email d'un lien magique de réinitialisation sécurisé. |
| `POST` | `api/auth/reset-password` | — | `Auth\ResetPasswordController@reset` | `User` | `token`, `email`, `password` | Réinitialisation du mot de passe via le token du lien magique. |
| `POST` | `api/auth/account-state` | — | `Auth\AccountStateController` | `User` | `identifier`, `password` | Diagnostic de l'état du compte après authentification (actif, suspendu temporairement, ou supprimé sous rétractation 30j). |
| `POST` | `api/auth/restore-account` | — | `Profile\AccountController@restore` | `User` | `identifier`, `password` | Rétractation de suppression de compte avant les 30 jours, réactivation complète. |

---

### B. Profil Utilisateur & Réseau Social (30 routes)

| Méthode | URI | Nom de Route | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/profile` | — | `Profile\ProfileController@show` | `User` | Aucun | Renvoie le profil complet du compte connecté avec compteurs statistiques. |
| `GET` | `api/profile/public/{identifier}` | — | `Profile\ProfileController@showPublic` | `User` | `{identifier}` (ID ou handle) | Profil public avec masquage des champs privés selon réglages et vérification de blocage mutuel. |
| `GET` | `api/profile/user/{identifier}` | — | `Profile\ProfileController@showPublic` | `User` | `{identifier}` | Alias de consultation de profil public. |
| `POST` | `api/profile/handle-check` | — | `Profile\HandleCheckController` | `User` | `handle` | Contrôle de validité du pseudo (format regex, longueur 3-20, mots réservés, disponibilité en base). |
| `PUT` | `api/profile/identity` | — | `Profile\ProfileController@updateIdentity` | `User` | `name`, `handle`, `bio` | Mise à jour des informations de présentation. |
| `PUT` | `api/profile/birthdate` | — | `Profile\ProfileController@updateBirthdate` | `User` | `birthdate` | Contrôle d'âge (≥ 18 ans) et mise à jour de la date de naissance. |
| `PUT` | `api/profile/gender` | — | `Profile\ProfileController@updateGender` | `User` | `gender`, `is_gender_private` | Mise à jour du genre avec interrupteur de masquage public. |
| `PUT` | `api/profile/location` | — | `Profile\ProfileController@updateLocation` | `User` | `country_id`, `city_id`, `neighbourhood_id` | Mise à jour de l'ancrage géographique de l'utilisateur. |
| `PUT` | `api/profile/bio` | — | `Profile\ProfileController@updateBio` | `User` | `bio` (max 150 caractères) | Mise à jour du texte de présentation. |
| `PUT` | `api/profile/interests` | — | `Profile\ProfileController@updateInterests` | `User`, `Interest` | `interest_ids` (array min:3) | Synchronisation de la table pivot `interest_user`. |
| `PUT` | `api/profile/avatar` | — | `Profile\AvatarController` | `User`, `Media` | `avatar` (image file) | Téléversement, redimensionnement et association de l'avatar du compte. |
| `GET` | `api/profile/privacy` | — | `Profile\PrivacyController@show` | `User` | Aucun | Lecture des préférences de confidentialité du profil. |
| `PUT` | `api/profile/privacy` | — | `Profile\PrivacyController@update` | `User` | `is_private`, `allow_tagging`, `allow_mentions` | Mise à jour des restrictions de visibilité et d'interaction. |
| `GET` | `api/profile/followers` | — | `Profile\FollowController@followers` | `Follow`, `User` | `per_page` | Liste paginée des abonnés de l'utilisateur connecté. |
| `GET` | `api/profile/following` | — | `Profile\FollowController@following` | `Follow`, `User` | `per_page` | Liste paginée des abonnements de l'utilisateur connecté. |
| `GET` | `api/profile/{id}/followers` | — | `Profile\FollowController@followers` | `Follow`, `User` | `{id}`, `per_page` | Liste des abonnés d'un tiers (si compte public ou relation autorisée). |
| `GET` | `api/profile/{id}/following` | — | `Profile\FollowController@following` | `Follow`, `User` | `{id}`, `per_page` | Liste des abonnements d'un tiers. |
| `POST` | `api/profile/{id}/follow` | — | `Profile\FollowController@follow` | `Follow`, `FollowRequest` | `{id}` | Abonnement direct (si compte public) ou création d'une demande d'abonnement (si privé). |
| `DELETE` | `api/profile/{id}/follow` | — | `Profile\FollowController@unfollow` | `Follow` | `{id}` | Désabonnement d'un compte (méthode DELETE). |
| `POST` | `api/profile/{id}/unfollow` | — | `Profile\FollowController@unfollow` | `Follow` | `{id}` | Désabonnement d'un compte (méthode POST pour compatibilité client). |
| `POST` | `api/profile/{id}/toggle-follow` | — | `Profile\FollowController@toggle` | `Follow` | `{id}` | Bascule atomique suivre / ne plus suivre. |
| `GET` | `api/profile/follow-requests` | — | `Profile\FollowRequestController@index` | `FollowRequest` | `per_page` | Liste des demandes d'abonnement reçues en attente. |
| `GET` | `api/profile/follow-requests/sent` | — | `Profile\FollowRequestController@sent` | `FollowRequest` | `per_page` | Liste des demandes d'abonnement envoyées par l'utilisateur. |
| `POST` | `api/profile/follow-requests/accept-all` | — | `Profile\FollowRequestController@acceptAll` | `FollowRequest`, `Follow` | Aucun | Acceptation en masse de toutes les demandes en attente. |
| `POST` | `api/profile/follow-requests/{id}/accept` | — | `Profile\FollowRequestController@accept` | `FollowRequest`, `Follow` | `{id}` | Acceptation d'une demande et bascule vers un abonnement actif. |
| `POST` | `api/profile/follow-requests/{id}/reject` | — | `Profile\FollowRequestController@reject` | `FollowRequest` | `{id}` | Rejet d'une demande d'abonnement. |
| `GET` | `api/profile/blocked` | — | `Profile\BlockController@index` | `UserBlock` | `per_page` | Liste des utilisateurs bloqués par le compte connecté. |
| `POST` | `api/profile/{id}/block` | — | `Profile\BlockController@block` | `UserBlock`, `Follow` | `{id}` | Blocage bilatéral immédiat et suppression des liens de suivi mutuels. |
| `DELETE` | `api/profile/{id}/block` | — | `Profile\BlockController@unblock` | `UserBlock` | `{id}` | Déblocage d'un utilisateur (méthode DELETE). |
| `POST` | `api/profile/{id}/unblock` | — | `Profile\BlockController@unblock` | `UserBlock` | `{id}` | Déblocage d'un utilisateur (méthode POST). |

---

### C. Paramètres & Sécurité du Compte (13 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `POST` | `api/settings/email/request` | `Profile\EmailController@requestChange` | `User`, `Otp` | `new_email`, `password` | Vérification du mot de passe, contrôle d'unicité de la nouvelle adresse, émission du code de confirmation vers la nouvelle adresse et alerte sur l'ancienne. |
| `POST` | `api/settings/email/verify` | `Profile\EmailController@verifyChange` | `User`, `Otp` | `code` | Validation de l'OTP et mise à jour effective de l'email de connexion. |
| `POST` | `api/settings/email/resend` | `Profile\EmailController@resendCode` | `Otp` | Aucun | Renvoi du code de confirmation avec respect des délais anti-spam. |
| `POST` | `api/settings/email/cancel` | `Profile\EmailController@cancelChange` | `Otp` | Aucun | Annulation de la procédure de changement d'email. |
| `PUT` | `api/settings/password` | `Profile\PasswordController@update` | `User`, `PasswordHistory` | `current_password`, `new_password` | Vérification du mot de passe actuel, contrôle de robustesse, interdiction des 3 anciens mots de passe, mise à jour. |
| `GET` | `api/settings/privacy` | `Profile\PrivacyController@show` | `User` | Aucun | Consultation des réglages globaux de confidentialité du compte. |
| `PUT` | `api/settings/privacy` | `Profile\PrivacyController@update` | `User` | `search_engine_indexing`, `read_receipts` | Mise à jour des options de confidentialité. |
| `POST` | `api/settings/secure` | `Profile\SecurityController@secureAccount` | `User`, `DeviceSession` | Aucun | Bouton d'urgence : révocation de tous les tokens/sessions autres que l'actuel, forçage du renouvellement de mot de passe. |
| `GET` | `api/settings/sessions` | `Profile\SecurityController@getSessions` | `DeviceSession` | Aucun | Liste détaillée des sessions actives (OS, navigateur, IP, ville géolocalisée, date de dernière activité). |
| `DELETE` | `api/settings/sessions/{id}` | `Profile\SecurityController@revokeSession` | `DeviceSession` | `{id}` | Déconnexion à distance d'un terminal spécifique. |
| `POST` | `api/settings/sessions/{id}/acknowledge` | `Profile\SecurityController@acknowledgeSession` | `DeviceSession` | `{id}` | Acquittement d'une alerte de connexion inhabituelle par l'utilisateur. |
| `POST` | `api/settings/account/pause` | `Profile\AccountController@pause` | `User` | `duration_days` | Mise en pause temporaire du compte (masquage public réversible). |
| `DELETE` | `api/settings/account` | `Profile\AccountController@delete` | `User` | `password` | Programmation de la suppression définitive du compte avec période de grâce de 30 jours (soft delete). |

---

### D. OTP, Onboarding & Préférences Logement (6 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `POST` | `api/otp/request` | `Otp\OtpController@requestOtp` | `Otp` | `phone`, `context` | Génération et envoi d'un code OTP par SMS via passerelle d'envoi. |
| `POST` | `api/otp/verify` | `Otp\OtpController@verifyOtp` | `Otp` | `phone`, `code`, `context` | Validation de l'OTP avec limitation du nombre de tentatives. |
| `POST` | `api/otp/resend` | `Otp\OtpController@resendOtp` | `Otp` | `phone`, `context` | Renvoi de code après écoulement du délai légal. |
| `POST` | `api/onboarding/complete` | `Onboarding\OnboardingController@completeStep` | `User` | `step` (`credentials`, `identity`, `phone`, `profile`, `interests`, `housing`) | Enregistrement de l'état d'avancement des 6 étapes de l'onboarding mobile dans `onboarding_progress`. |
| `GET` | `api/housing/search` | `Housing\HousingSearchController@show` | `HousingSearch` | Aucun | Récupération des préférences de recherche de logement du compte connecté. |
| `PUT` | `api/housing/search` | `Housing\HousingSearchController@update` | `HousingSearch` | `intent`, `types`, `budget`, `currency`, `city`, `neighbourhoods`, `place` | Mise à jour des critères de recherche et d'alerte de logement. |

---

### E. Subscriptions, Badges & Tarifs (14 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/subscriptions` | `Subscription\SubscriptionController@index` | `Subscription` | `per_page` | Liste et historique des abonnements souscrits par l'utilisateur. |
| `GET` | `api/subscriptions/plans` | `Subscription\CatalogController@index` | `SubscriptionProduct`, `SubscriptionPrice` | `country_code` | Consultation du catalogue des offres et tarifs d'abonnements. |
| `GET` | `api/subscriptions/status` | `Subscription\SubscriptionController@status` | `Subscription`, `BadgeHistory` | Aucun | État en temps réel des abonnements et badges actifs du compte. |
| `POST` | `api/subscriptions/subscribe` | `Subscription\SubscriptionController@subscribe` | `Subscription`, `PaymentTransaction` | `price_id`, `payment_method` | Création d'une nouvelle souscription et initiation du flux de paiement. |
| `GET` | `api/subscriptions/{id}` | `Subscription\SubscriptionController@show` | `Subscription` | `{id}` | Détail d'un contrat d'abonnement avec dates de renouvellement et période de grâce. |
| `POST` | `api/subscriptions/{id}/cancel` | `Subscription\SubscriptionController@cancel` | `Subscription` | `{id}`, `reason` | Résiliation de la reconduction tacite d'un abonnement. |
| `POST` | `api/subscriptions/{id}/change-plan` | `Subscription\SubscriptionController@changePlan` | `Subscription` | `{id}`, `new_price_id` | Migration de formule d'abonnement avec calcul de prorata. |
| `GET` | `api/subscriptions/{id}/renewal` | `Subscription\SubscriptionRenewalController@renewalInfo` | `Subscription` | `{id}` | Contrôle d'éligibilité au renouvellement assisté d'un abonnement. |
| `POST` | `api/subscriptions/{id}/renewal/quote` | `Subscription\SubscriptionRenewalController@quote` | `SubscriptionPrice` | `{id}`, `price_id` | Devis certifié serveur pour le renouvellement anticipé. |
| `POST` | `api/subscriptions/{id}/renewal/confirm` | `Subscription\SubscriptionRenewalController@confirm` | `Subscription` | `{id}`, `quote_id`, `payment_id` | Validation et confirmation du renouvellement avec extension de la date de fin. |
| `GET` | `api/badges/status` | `Subscription\SubscriptionController@status` | `BadgeHistory` | Aucun | Consultation des badges d'autorité actuellement détenus. |
| `GET` | `api/badges/history` | `Subscription\SubscriptionController@history` | `BadgeHistory` | Aucun | Relevé historique d'attribution, expiration et révocation de badges. |
| `GET` | `api/catalog` | `Subscription\CatalogController@index` | `SubscriptionProduct` | `country` | Consultation publique du catalogue des badges et services payants. |
| `GET` | `api/catalog/{code}` | `Subscription\CatalogController@show` | `SubscriptionProduct` | `{code}` | Détail d'un produit spécifique du catalogue par son code produit. |

---

### F. Paiements Mobile Money (6 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/payments/catalog` | `Payment\PaymentController@catalog` | `SubscriptionProduct` | `country` | Tarifs officiels des prestations payantes dans la devise demandée. |
| `POST` | `api/payments/quote` | `Payment\PaymentController@quote` | `PaymentTransaction` | `service_type`, `item_id`, `currency` | Émission d'un devis certifié serveur avec validité horodatée (15 min). |
| `POST` | `api/payments/initiate` | `Payment\PaymentController@initiate` | `PaymentTransaction`, `PaymentAttempt` | `quote_id`, `operator`, `phone`, `idempotency_key` | Déclenchement de la requête USSD Push Mobile Money avec clé d'idempotence stricte. |
| `GET` | `api/payments/{id}/status` | `Payment\PaymentController@status` | `PaymentTransaction` | `{id}` | Polling de statut en temps réel (interrogation de l'agrégateur Monetbil). |
| `POST` | `api/payments/{id}/cancel` | `Payment\PaymentController@cancel` | `PaymentTransaction` | `{id}` | Annulation d'une transaction en attente de validation. |
| `GET` | `api/payments/history` | `Payment\PaymentController@history` | `PaymentTransaction` | `per_page` | Relevé paginé des paiements et transactions du compte client. |

---

### G. Square CR1 — Publications, Feed & Interactions (23 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/square-cr1/square/feed` | `Square\PostFeedController@index` | `Post`, `PostMedia`, `PostLike` | `scope` (`neighbourhood`, `following`, `discover`), `per_page` | Fil d'actualité paginé du quartier avec algorithme de portée et exclusion des bloqués. |
| `POST` | `api/square-cr1/posts` | `Square\PostController@store` | `Post`, `PostMedia`, `PostTag` | `caption`, `media_ids`, `tags`, `place_id`, `audience`, `allow_comments` | Création complète d'un post Square avec carrousel médias et géolocalisation. |
| `GET` | `api/square-cr1/posts/{post}` | `Square\PostController@show` | `Post`, `PostMedia` | `{post}` | Consultation d'un post avec contrôle de l'audience et des permissions. |
| `DELETE` | `api/square-cr1/posts/{post}` | `Square\PostController@destroy` | `Post` | `{post}` | Suppression définitive d'un post et de ses dépendances (réservé à l'auteur). |
| `PATCH` | `api/square-cr1/posts/{post}/caption` | `Square\PostCaptionController@update` | `Post` | `{post}`, `caption` (max 2 200 car.) | Modification de la légende d'une publication par son auteur. |
| `PATCH` | `api/square-cr1/posts/{post}/audience` | `Square\PostAudienceController@update` | `Post` | `{post}`, `audience` | Modification de la visibilité (`public`, `followers`, `neighbourhood`). |
| `PATCH` | `api/square-cr1/posts/{post}/comments-status` | `Square\PostCommentsStatusController@update` | `Post` | `{post}`, `allow_comments` | Activation ou fermeture des commentaires sur un post. |
| `POST` | `api/square-cr1/posts/{post}/archive` | `Square\PostArchiveController@archive` | `Post` | `{post}` | Masquage du post des flux publics sans suppression physique. |
| `POST` | `api/square-cr1/posts/{post}/unarchive` | `Square\PostArchiveController@unarchive` | `Post` | `{post}` | Rétablissement de la visibilité d'un post précédemment archivé. |
| `POST` | `api/square-cr1/posts/{post}/like` | `Square\PostLikeController@store` | `PostLike`, `Post` | `{post}` | Ajout d'un J'aime avec incrément atomique et idempotence. |
| `DELETE` | `api/square-cr1/posts/{post}/like` | `Square\PostLikeController@destroy` | `PostLike`, `Post` | `{post}` | Retrait d'un J'aime avec décrément atomique. |
| `POST` | `api/square-cr1/posts/{post}/share` | `Square\PostShareController@store` | `PostShare`, `Post` | `{post}` | Enregistrement d'un partage avec incrément atomique du compteur. |
| `GET` | `api/square-cr1/posts/{post}/comments` | `Square\PostCommentController@index` | `PostComment` | `{post}`, `sort` (`recent`, `top`) | Liste paginée des commentaires d'un post ordonnés selon le critère choisi. |
| `POST` | `api/square-cr1/posts/{post}/comments` | `Square\PostCommentController@store` | `PostComment`, `Post` | `{post}`, `content` | Publication d'un commentaire sur un post ouvert. |
| `GET` | `api/square-cr1/comments/{comment}/replies` | `Square\PostCommentController@replies` | `PostComment` | `{comment}`, `per_page` | Liste des réponses directes à un commentaire donné. |
| `PATCH` | `api/square-cr1/comments/{comment}` | `Square\PostCommentController@update` | `PostComment` | `{comment}`, `content` | Modification du texte d'un commentaire par son auteur. |
| `DELETE` | `api/square-cr1/comments/{comment}` | `Square\PostCommentController@destroy` | `PostComment` | `{comment}` | Suppression d'un commentaire (auteur du commentaire ou du post). |
| `PUT` | `api/square-cr1/comments/{comment}/reaction` | `Square\PostCommentReactionController@react` | `PostCommentReaction` | `{comment}`, `type` (`like`/`dislike`) | Définition d'une réaction sur un commentaire avec exclusivité mutuelle. |
| `DELETE` | `api/square-cr1/comments/{comment}/reaction` | `Square\PostCommentReactionController@destroy` | `PostCommentReaction` | `{comment}` | Suppression de sa réaction sur un commentaire. |
| `POST` | `api/square-cr1/media/upload` | `Media\MediaUploadController@upload` | `Media` | `file` (image ou vidéo) | Téléversement sécurisé de fichier média avec contrôle MIME et génération de vignette. |
| `GET` | `api/square-cr1/search/people` | `Search\PeopleSearchController@index` | `User` | `query`, `per_page` | Recherche d'utilisateurs pour identification sur photo ou mention `@`. |
| `GET` | `api/square-cr1/search/hashtags/trending` | `Search\HashtagSearchController@trending` | `Hashtag` | `city_id`, `limit` | Liste des hashtags tendances ordonnés par volume de publications. |
| `GET` | `api/square-cr1/search/places` | `Search\PlaceSearchController@index` | `City`, `Neighbourhood` | `query`, `country_id` | Recherche de lieux réels BANGO pour géolocaliser une publication. |

---

### H. Reels CR2 & Sonothèque (33 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/reels/feed` | `Reel\ReelFeedController@feed` | `Reel`, `Sound` | `cursor`, `per_page` | Fil vertical de vidéos courtes avec gestion de l'historique et des exclusions. |
| `GET` | `api/reels/{id}` | `Reel\ReelFeedController@show` | `Reel` | `{id}` (UUID) | Consultation d'un Reel individuel et de ses métadonnées de diffusion. |
| `DELETE` | `api/reels/{reel}` | `Reel\ReelFeedController@destroy` | `Reel` | `{reel}` | Suppression définitive d'un Reel publié par son créateur. |
| `POST` | `api/reels/drafts` | `Reel\StudioReelController@saveDraft` | `ReelDraft` | `composition_json`, `media_ids` | Enregistrement ou mise à jour de la composition d'un brouillon de Reel. |
| `GET` | `api/reels/drafts` | `Reel\StudioReelController@listDrafts` | `ReelDraft` | Aucun | Liste de tous les brouillons sauvegardés par l'utilisateur connecté. |
| `GET` | `api/reels/drafts/{id}` | `Reel\StudioReelController@getDraft` | `ReelDraft` | `{id}` | Détail d'un brouillon pour reprise de montage dans le studio. |
| `DELETE` | `api/reels/drafts/{id}` | `Reel\StudioReelController@deleteDraft` | `ReelDraft` | `{id}` | Suppression d'un brouillon et purge des médias temporaires associés. |
| `POST` | `api/reels/media/upload` | `Reel\StudioReelController@uploadMedia` | `Media` | `file`, `type` (`video`, `audio`, `cover`) | Téléversement d'un clip vidéo ou d'une couverture pour Reel. |
| `POST` | `api/reels/publish` | `Reel\StudioReelController@publish` | `Reel`, `Job` | `draft_id`, `caption`, `sound_id`, `cover_time` | Lancement de la publication asynchrone (Job de transcodage Cloudflare Stream). |
| `GET` | `api/reels/sounds` | `Reel\SoundController@index` | `Sound` | `category`, `query`, `per_page` | Catalogue des sons musicaux disponibles pour composition. |
| `GET` | `api/reels/sounds/user/favourites` | `Reel\SoundController@favourites` | `Sound`, `SoundFavourite` | `per_page` | Liste des pistes audio mises en favoris par l'utilisateur. |
| `GET` | `api/reels/sounds/{id}` | `Reel\SoundController@show` | `Sound` | `{id}` | Détail complet d'un son (auteur, durée, pochette, droits). |
| `GET` | `api/reels/sounds/{id}/reels` | `Reel\SoundController@reels` | `Reel`, `Sound` | `{id}`, `per_page` | Liste de tous les Reels utilisant un son audio spécifique. |
| `POST` | `api/reels/sounds/{id}/favourite` | `Reel\SoundController@toggleFavourite` | `SoundFavourite` | `{id}` | Ajout ou retrait d'un son de sa liste de favoris. |
| `PUT` | `api/reels/{id}/original-sound/lock` | `Reel\SoundController@lockOriginal` | `Sound` | `{id}`, `locked` | Verrouillage de la réutilisation de son audio original par des tiers. |
| `POST` | `api/reels/{reel}/view` | `Reel\InteractionController@view` | `ReelView`, `Reel` | `{reel}` | Enregistrement d'une vue avec fenêtre anti-abus 30 min et anonymisation IP. |
| `POST` | `api/reels/{reel}/reaction` | `Reel\InteractionController@react` | `ReelReaction` | `{reel}`, `type` (`like`/`dislike`) | Définition d'une réaction avec bascule et exclusivité mutuelle stricte. |
| `DELETE` | `api/reels/{reel}/reaction` | `Reel\InteractionController@removeReaction` | `ReelReaction` | `{reel}` | Retrait de toute réaction sur le Reel. |
| `POST` | `api/reels/{reel}/like` | `Reel\InteractionController@like` | `ReelReaction`, `Reel` | `{reel}` | Application d'un Like sur le Reel. |
| `DELETE` | `api/reels/{reel}/like` | `Reel\InteractionController@unlike` | `ReelReaction`, `Reel` | `{reel}` | Annulation du Like. |
| `POST` | `api/reels/{reel}/dislike` | `Reel\InteractionController@dislike` | `ReelReaction`, `Reel` | `{reel}` | Application d'un Dislike. |
| `DELETE` | `api/reels/{reel}/dislike` | `Reel\InteractionController@undislike` | `ReelReaction`, `Reel` | `{reel}` | Annulation du Dislike. |
| `POST` | `api/reels/{reel}/share` | `Reel\InteractionController@share` | `ReelShare`, `Reel` | `{reel}` | Enregistrement d'un partage avec incrément atomique et idempotence. |
| `POST` | `api/reels/{reel}/save` | `Reel\InteractionController@toggleSave` | `ReelSave` | `{reel}` | Sauvegarde ou retrait du Reel dans les favoris privés. |
| `GET` | `api/reels/user/saved` | `Reel\InteractionController@listSaved` | `ReelSave`, `Reel` | `per_page` | Relevé des Reels enregistrés dans les favoris de l'utilisateur. |
| `POST` | `api/reels/{reel}/not-interested` | `Reel\InteractionController@markNotInterested` | `ReelNotInterested` | `{reel}` | Masquage du Reel et enregistrement du feedback négatif dans l'algorithme. |
| `GET` | `api/reels/{reel}/comments` | `Reel\ReelCommentController@index` | `ReelComment` | `{reel}`, `per_page` | Liste des commentaires de premier niveau d'un Reel. |
| `GET` | `api/reels/comments/{comment}/replies` | `Reel\ReelCommentController@replies` | `ReelComment` | `{comment}` | Réponses associées à un commentaire de Reel. |
| `POST` | `api/reels/{reel}/comments` | `Reel\ReelCommentController@store` | `ReelComment`, `Reel` | `{reel}`, `content` | Publication d'un commentaire sur un Reel. |
| `PATCH` | `api/reels/comments/{comment}` | `Reel\ReelCommentController@update` | `ReelComment` | `{comment}`, `content` | Édition d'un commentaire par son auteur. |
| `DELETE` | `api/reels/comments/{comment}` | `Reel\ReelCommentController@destroy` | `ReelComment` | `{comment}` | Suppression d'un commentaire et de ses réponses en cascade. |
| `PUT` | `api/reels/comments/{comment}/reaction` | `Reel\ReelCommentController@react` | `ReelCommentReaction` | `{comment}`, `type` | Réaction Like/Dislike sur un commentaire de Reel. |
| `DELETE` | `api/reels/comments/{comment}/reaction` | `Reel\ReelCommentController@removeReaction` | `ReelCommentReaction` | `{comment}` | Retrait de sa réaction sur un commentaire de Reel. |

---

### I. Stories CR3 & Collections À la une (19 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/stories/rail` | `Story\StoryController@rail` | `Story`, `StoryView` | Aucun | Rail horizontal des Stories actives (< 24h) regroupées par utilisateur, avec indicateur vu/non vu. |
| `GET` | `api/stories/mine` | `Story\StoryController@mine` | `Story` | Aucun | Liste de ses propres Stories actives actuellement à l'antenne. |
| `GET` | `api/stories/archived` | `Story\StoryController@archived` | `Story` | `per_page` | Historique de ses Stories archivées au-delà de 24h. |
| `GET` | `api/stories/close-list` | `Story\CloseListController@index` | `CloseList`, `User` | Aucun | Liste des membres de sa "liste choisie" (amis proches). |
| `PUT` | `api/stories/close-list` | `Story\CloseListController@update` | `CloseList` | `user_ids` (array) | Remplacement atomique des membres de sa liste choisie. |
| `POST` | `api/stories` | `Story\StoryController@store` | `Story`, `StorySlide` | `slides` (médias, stickers, sondages), `audience`, `allow_replies`, `archive` | Publication d'une nouvelle Story avec diapositives créatives. |
| `GET` | `api/stories/mine/{id}` | `Story\StoryController@myStory` | `Story` | `{id}` (UUID) | Consultation de sa Story avec statistiques détaillées (vues, votes, réponses). |
| `GET` | `api/stories/{id}/viewers` | `Story\StoryController@viewers` | `StoryView`, `User` | `{id}`, `per_page` | Liste nominative privée des spectateurs de sa Story (interdit aux tiers). |
| `GET` | `api/stories/{id}/slides` | `Story\StoryController@slides` | `StorySlide` | `{id}` | Diapositives composant la Story avec leurs stickers interactifs. |
| `GET` | `api/stories/{id}` | `Story\StoryController@show` | `Story` | `{id}` | Consultation d'une Story accessible selon l'audience (public, followers, close-list). |
| `DELETE` | `api/stories/{id}` | `Story\StoryController@destroy` | `Story` | `{id}` | Suppression d'une Story personnelle (les copies archivées sont conservées si demandé). |
| `POST` | `api/stories/{id}/view` | `Story\StoryController@view` | `StoryView`, `Story` | `{id}`, `slide_id` | Enregistrement de consultation unique par utilisateur (dédupliqué). |
| `POST` | `api/stories/{slideId}/poll/{stickerId}/vote` | `Story\StoryController@pollVote` | `StoryPollVote` | `slideId`, `stickerId`, `option_index` | Vote unique sur un sticker sondage avec calcul des pourcentages serveur. |
| `POST` | `api/stories/{id}/reply` | `Story\StoryController@reply` | `StoryReply` | `{id}`, `text`, `type`, `idempotency_key` | Enregistrement de l'intention de réponse privée avec snapshot immuable de la Story (`metadata`) et émission de l'événement `StoryReplyCreated`. |
| `POST` | `api/stories/{id}/report` | `Story\StoryController@report` | `StoryReport` | `{id}`, `reason`, `details` | Signalement d'une Story pour examen par la modération. |
| `GET` | `api/highlights` | `Story\HighlightController@index` | `StoryHighlight` | `user_id` | Collections "À la une" visibles sur le profil d'un utilisateur. |
| `POST` | `api/highlights` | `Story\HighlightController@store` | `StoryHighlight` | `title` (max 20 car.), `cover_url`, `story_ids` | Création d'une collection permanente À la une. |
| `GET` | `api/highlights/{id}` | `Story\HighlightController@show` | `StoryHighlight` | `{id}` | Détail d'une collection avec ses Stories toujours consultables. |
| `DELETE` | `api/highlights/{id}` | `Story\HighlightController@destroy` | `StoryHighlight` | `{id}` | Suppression d'une collection À la une sans affecter les Stories sous-jacentes. |

---

### J. Données Géographiques & Services Communs (15 routes)

| Méthode | URI | Contrôleur & Méthode | Modèles & Tables | Paramètres Attendus | Logique Métier Implémentée |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `GET` | `api/geo/countries` | `Geo\CountryController@index` | `Country` | Aucun | Liste publique de tous les pays supportés. |
| `GET` | `api/geo/countries/{country}` | `Geo\CountryController@show` | `Country` | `{country}` | Détail d'un pays avec indicatif, devise et règles de numérotation. |
| `GET` | `api/geo/currencies` | `Geo\CurrencyController@index` | `Currency` | Aucun | Liste publique des devises gérées (XAF, XOF, GHS, NGN, etc.). |
| `GET` | `api/geo/currencies/{currency}` | `Geo\CurrencyController@show` | `Currency` | `{currency}` | Détail d'une devise (symbole, format, décimales). |
| `GET` | `api/geo/cities` | `Geo\CityController@index` | `City` | `country_id` | Liste publique des villes desservies par pays. |
| `GET` | `api/geo/cities/{city}` | `Geo\CityController@show` | `City` | `{city}` | Fiche d'une ville avec ses coordonnées et limites géographiques. |
| `GET` | `api/geo/neighbourhoods` | `Geo\NeighbourhoodController@index` | `Neighbourhood` | `city_id` | Liste publique des quartiers d'une ville. |
| `GET` | `api/geo/neighbourhoods/{neighbourhood}` | `Geo\NeighbourhoodController@show` | `Neighbourhood` | `{neighbourhood}` | Fiche d'un quartier avec coordonnées de centrage. |
| `GET` | `api/geo/operators` | `Geo\OperatorController@index` | `Operator` | `country_id` | Opérateurs de téléphonie et Mobile Money par pays. |
| `GET` | `api/geo/operators/{operator}` | `Geo\OperatorController@show` | `Operator` | `{operator}` | Détail d'un opérateur (préfixes de numéros, code USSD). |
| `GET` | `api/interests` | `Closure` (routes/api.php:131) | `Interest` | Aucun | Liste publique de tous les centres d'intérêt configurés sur la plateforme. |
| `MATCH` | `api/qr/resolve` | `Profile\QrController@resolve` | `User` | `payload` ou `url` | Décodage d'un QR code scanné et redirection vers le profil concerné. |
| `GET` | `api/profiles/{identifier}` | `Profile\ProfileController@showPublic` | `User` | `{identifier}` | Fiche profil public accessible sans authentification préalable. |
| `POST` | `api/webhooks/payments/monetbil` | `Payment\MonetbilWebhookController@handle` | `PaymentTransaction` | Payload IPN Monetbil, signature MD5 | Traitement asynchrone des notifications de paiement Monetbil avec vérification cryptographique timing-safe. |
| `POST` | `api/webhooks/cloudflare-stream` | `Reel\CloudflareStreamWebhookController@handle` | `Reel` | Payload Cloudflare Stream | Traitement du statut de transcodage vidéo et activation automatique du Reel en ligne. |

---

### K. Back-Office Administrateur (auth:api + admin) (64 routes)

| Domaine Admin | Méthodes & URI | Contrôleur | Fonction Métier & Opérations Permises |
| :--- | :--- | :--- | :--- |
| **Rôles Système** (6 routes) | `api/roles` (CRUD complet : `index`, `store`, `show`, `update`, `destroy`) + `api/roles/{role}/users` | `Admin\RoleController` | Gestion des rôles RBAC de la plateforme et inventaire des utilisateurs affectés à un rôle. |
| **Gestion Utilisateurs** (13 routes) | `api/users` (CRUD), `api/users/{user}/role`, `api/users/{user}/suspend`, `api/users/{user}/unsuspend`, `api/users/{user}/block`, `api/users/{user}/unblock`, `api/users/{user}/sessions` (list, revoke, revokeAll) | `Admin\UserController`, `Admin\RoleController` | Supervision des usagers, suspension administrative immédiate, déblocage, purge de sessions suspectes, attribution de rôles. |
| **Géographie CRUD** (15 routes) | `api/geo/countries`, `api/geo/currencies`, `api/geo/cities`, `api/geo/neighbourhoods`, `api/geo/operators` (`POST`, `PUT/PATCH`, `DELETE`) | `Admin` Geo Controllers | Administration des pays, villes, quartiers et opérateurs disponibles sur BANGO. |
| **Catalogue d'Offres** (6 routes) | `api/admin/catalog/products` (`index`, `store`, `show`, `update`), `POST /products/{id}/prices`, `PUT /prices/{priceId}` | `Admin\SubscriptionCatalogController` | Création et paramétrage des produits d'abonnement, badges et grilles tarifaires par devise. |
| **Gestion Abonnements** (7 routes) | `api/admin/subscriptions` (`index`, `show`, `suspend`, `reactivate`, `extend`, `revoke`), `GET api/admin/badges/history` | `Admin\SubscriptionManagementController` | Supervision des souscriptions, suspension/réactivation manuelle, extension de durée, révocation de badges et journal d'audit. |
| **Paiements & Litiges** (9 routes) | `api/admin/payments/transactions` (`index`, `show`, `events`, `refund`), `/disputes` (`index`, `create`, `show`, `status`, `notes`), `/double-charge-claims` (`index`, `create`) | `Admin\AdminPaymentController` | Supervision des flux financiers, traitement des litiges, réclamations de double prélèvement et exécution de remboursements. |
| **Sonothèque Audio** (9 routes) | `api/admin/sounds` (`index`, `store`, `show`, `update`, `destroy`, `restore`, `remove`), `PATCH /{id}/publication`, `PATCH /{id}/rights` | `Admin\AdminSoundCatalogController` | Alimentation du catalogue musical, vérification des licences de diffusion et gestion des métadonnées audio. |
| **Documentation** (1 route) | `GET docs.openapi` | Scribe OpenAPI | Spécification OpenAPI générée automatiquement. |
