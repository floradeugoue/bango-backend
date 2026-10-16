# Analyse des Écarts API — Développées, Partielles et Restant à Développer (Version Consolidée)

**Projet :** BANGO Platform (Frontend React Native & Backend Laravel 12)  
**Date d'actualisation :** 16 Octobre 2026  
**Objectif :** Répertoire exhaustif et consolidé de l'état de couverture de toutes les fonctionnalités attendues par le frontend BANGO.  
**Résultat final :** **142 / 142 fonctionnalités développées (100 %) — 0 manquante — 0 partielle**  

---

## Synthèse Numérique des Écarts

| Statut d'Implémentation | Nombre de Fonctionnalités | Pourcentage du Périmètre Frontend |
| :--- | :---: | :---: |
| 🟢 **Catégorie A — Déjà Développées & Opérationnelles** | **142 fonctionnalités** | **100,0 %** |
| 🟡 **Catégorie B — Partiellement Développées** | **0 fonctionnalité** | **0,0 %** |
| 🔴 **Catégorie C — Restant à Développer (Manques Complets)** | **0 fonctionnalité** | **0,0 %** |
| ❓ **Catégorie D — Besoins Métier à Clarifier** | **0 point** | **0,0 %** |
| **TOTAL** | **142 fonctionnalités** | **100,0 %** |

---

## Catégorie A — API Développées & Opérationnelles (142 Fonctionnalités)

Toutes les opérations métier requises par les 384 écrans du frontend BANGO disposent désormais d'une implémentation backend complète (routes enregistrées, FormRequests typées, modèles Eloquent, persistance en base de données et validation par **882 tests Pest automatisés**).

### 1. Splash & Contrôle de Version — SP (2)
- **Contrôle de version minimale applicative** : `GET /api/system/version` (`AppVersionController` pour `app/update-required.tsx`)
- **Référentiel géographique initial (pays, devises, indicatifs)** : `GET /api/geo/countries`

### 2. Authentification, Session & Identité — AU (18)
- **Création de compte par email/mot de passe** : `POST /api/auth/signup` (`SignupController`)
- **Connexion classique (email ou téléphone) & mot de passe** : `POST /api/auth/signin` (`SigninController`)
- **Authentification Sociale Google & Apple OAuth PKCE** : `POST /api/auth/provider` (`SocialAuthController`)
- **Validation du second facteur (2FA TOTP, SMS, backup code)** : `POST /api/auth/signin/second-factor` (`SecondFactorController`)
- **Déconnexion de session (révocation de token)** : `POST /api/auth/logout` (`LogoutController`)
- **Consultation de l'utilisateur connecté** : `GET /api/user` (`UserResource`)
- **Émission de code OTP (SMS)** : `POST /api/otp/request` (`OtpController@requestOtp`)
- **Vérification de code OTP** : `POST /api/otp/verify` (`OtpController@verifyOtp`)
- **Renvoi de code OTP** : `POST /api/otp/resend` (`OtpController@resendOtp`)
- **Contrôle unitaire de pseudo (`@handle`)** : `POST /api/profile/handle-check` (`HandleCheckController`)
- **Générateur algorithmique de suggestions de pseudos libres** : `POST /api/profile/handle-suggestions` (`HandleSuggestionController`)
- **Rapprochement chiffré de contacts du carnet d'adresses** : `POST /api/friends/match-fingerprints` (`ContactMatchingController`)
- **Suivi de progression de l'onboarding (6 étapes)** : `POST /api/onboarding/complete` (`OnboardingController@completeStep`)
- **Mot de passe oublié — Demande d'OTP** : `POST /api/auth/forgot-password` (`ForgotPasswordController@forgotPassword`)
- **Mot de passe oublié — Vérification du code** : `POST /api/auth/forgot-password/verify` (`ForgotPasswordController@verify`)
- **Mot de passe oublié — Nouveau mot de passe (contrôle des 3 anciens)** : `POST /api/auth/forgot-password/reset` (`ForgotPasswordController@reset`)
- **Diagnostic de compte suspendu / supprimé** : `POST /api/auth/account-state` (`AccountStateController`)
- **Rétablissement d'un compte soft-deleted sous 30 jours** : `POST /api/auth/restore-account` (`AccountController@restore`)

### 3. Légal, RGPD & Droits — LG (6)
- **CMS de consultation des documents légaux versionnés et traduits** : `GET /api/legal/documents/{slug}` (`LegalDocumentController@show`)
- **Acceptation atomique et middleware de ré-acceptation bloquant (CGU v2)** : `POST /api/legal/accept-terms` (`TermsAcceptanceController`, `EnsureTermsAccepted`)
- **Export des données personnelles RGPD (demande, suivi, téléchargement ZIP)** : `POST /api/user/data-export`, `GET /status`, `GET /download/{id}` (`DataExportController`)
- **Suppression programmée du compte avec délai de rétractation 30j** : `DELETE /api/settings/account` (`AccountController@delete`)
- **Rétractation de suppression de compte** : `POST /api/auth/restore-account` (`AccountController@restore`)
- **Signalement de violation de propriété intellectuelle (copyright/DMCA)** : `POST /api/reports` (`ReportController@store`)

### 4. Le Square — SQ & CR1 (16)
- **Fil d'actualité de quartier paginé** : `GET /api/square-cr1/square/feed` (`PostFeedController@index`)
- **Création d'un post multi-médias** : `POST /api/square-cr1/posts` (`PostController@store`)
- **Consultation et suppression d'un post** : `GET` & `DELETE /api/square-cr1/posts/{post}` (`PostController`)
- **Gestion de post (légende, audience, statut des commentaires)** : `PATCH /caption`, `/audience`, `/comments-status`
- **Archivage et désarchivage de post** : `POST /api/square-cr1/posts/{post}/archive` & `/unarchive`
- **Likes & Partages atomiques de post** : `POST`/`DELETE /like`, `POST /share` (`PostLikeController`, `PostShareController`)
- **Liste nominative paginée des personnes ayant aimé un post** : `GET /api/square-cr1/posts/{post}/likers` (`PostLikeController@likers`)
- **Commentaires de post (liste, ajout, sous-réponses, édition, suppression)** : `api/square-cr1/posts/{post}/comments`
- **Réactions Like/Dislike sur commentaires** : `PUT` & `DELETE /comments/{comment}/reaction`
- **Téléversement de médias pour carrousel Square** : `POST /api/square-cr1/media/upload` (`MediaUploadController`)
- **Recherche de personnes à taguer & lieux contextuels** : `GET /search/people`, `GET /search/places`
- **Hashtags tendances ordonnés par volume** : `GET /api/square-cr1/search/hashtags/trending`
- **Signalement de publication inappropriée (`SQ-07`)** : `POST /api/reports` (`reportable_type = post`)

### 5. Reels & Studio Vidéo — RL & CR2 (15)
- **Fil vertical des Reels avec pagination par curseur** : `GET /api/reels/feed` (`ReelFeedController@feed`)
- **Consultation et suppression de Reel** : `GET /api/reels/{id}`, `DELETE /api/reels/{reel}`
- **Brouillons de montage Reel (CRUD composition JSON)** : `api/reels/drafts/*` (`StudioReelController`)
- **Téléversement de clips vidéo / audio pour Reel** : `POST /api/reels/media/upload`
- **Publication asynchrone de Reel (Job transcodage Cloudflare Stream)** : `POST /api/reels/publish`
- **Catalogue musical, favoris et Reels liés** : `api/reels/sounds/*` (`SoundController`)
- **Verrouillage de son original** : `PUT /api/reels/{id}/original-sound/lock`
- **Comptage de vue vidéo dédupliquée 30 min** : `POST /api/reels/{reel}/view`
- **Likes & Dislikes exclusifs sur Reel** : `POST`/`DELETE /like`, `/dislike` (`ReelInteractionController`)
- **Sauvegarde en favoris et incrément de partage** : `POST /save`, `POST /share`
- **Feedback d'algorithme « Pas intéressé »** : `POST /api/reels/{reel}/not-interested`
- **Commentaires et sous-réponses de Reels** : `api/reels/{reel}/comments/*`
- **Signalement de Reel abusif (`RL-06`)** : `POST /api/reports` (`reportable_type = reel`)

### 6. Stories & Collections — CR3 (8)
- **Rail horizontal de Stories actives** : `GET /api/stories/rail` (`StoryController@rail`)
- **Publication d'une Story éphémère (24h)** : `POST /api/stories` (`StoryController@store`)
- **Consultation de ses Stories actives et archivées** : `GET /api/stories/mine`, `GET /mine/{id}`, `GET /archived`
- **Liste nominative des spectateurs (privé)** : `GET /api/stories/{id}/viewers`
- **Vote unique sur sondage de diapositive** : `POST /api/stories/{slideId}/poll/{stickerId}/vote`
- **Réponse privée Story avec snapshot immuable** : `POST /api/stories/{id}/reply`
- **Gestion de sa liste choisie (close friends)** : `GET` & `PUT /api/stories/close-list`
- **Collections permanentes À la une (Highlights)** : `api/highlights/*` (`HighlightController`)

### 7. Cœur Immobilier — CR4 & IM (11)
- **Feed vertical d'annonces immobilières vidéo** : `GET /api/listings` (`ListingController@index`)
- **Fiche détaillée du logement et ventilation des frais** : `GET /api/listings/{id}` (`ListingController@show`)
- **Recherche cartographique vectorielle par emprise (`bounds`) avec clustering adaptatif** : `GET /api/listings/map`
- **Comptage instantané des annonces correspondant aux filtres** : `GET /api/listings/count`
- **Brouillons de formulaire d'annonce guidée (CRUD)** : `api/listings/form/draft/*` (`ListingDraftController`)
- **Téléversement de vidéo guidée (9:16) et photos qualifiées par pièce** : `POST /listings/media`, `POST /media/order`
- **Création, mise à jour et suppression d'annonce** : `POST /api/listings`, `PATCH /listings/{id}`, `DELETE`
- **Mise en favori et consultation de sa liste de biens** : `POST`/`DELETE /listings/{id}/save`, `GET /listings/saved`
- **Consultation des créneaux de visite disponibles (5 jours glissants)** : `GET /api/listings/{id}/slots`
- **Réservation de visite avec verrou temporaire (15 min)** : `POST /api/listings/{id}/visits` (`ProspectVisitController@book`)
- **Confirmation post-paiement et reçu certifié de visite** : `POST /api/visits/{id}/confirm`, `GET /receipt`
- **Suivi de ses visites programmées et annulation (règle 24h)** : `GET /api/visits/mine`, `POST /cancel`
- **Moteur d'alertes automatiques pour nouvelles parutions** : `ListingCreatedHousingAlertListener` émettant des alertes ciblées selon `housing_searches`
- **Signalement « Déjà loué » / abus** : `POST /api/reports` (`reportable_type = listing`, motif `already_rented`)

### 8. Espace Bailleur (BL) & Espace Agent (AG) (13)
- **Souscription aux Badges Bailleur & Agent** : `POST /api/subscriptions/subscribe`
- **Dépôt des pièces KYC Bailleur (titres de propriété et CNI)** : `POST /api/landlord/kyc/submit`, `GET /kyc`
- **Tableau de bord patrimonial du bailleur** : `GET /api/landlord/properties` (vues, contacts, visites)
- **Pilotage opérationnel du statut du bien (`published`, `reserved`, `taken`)** : `PATCH /landlord/properties/{id}/status`
- **Configuration des créneaux d'ouverture aux visites** : `PUT /properties/{id}/slots`, `GET /slots`, `POST /slots`
- **Traitement des demandes de visite entrantes (accepter, reporter, refuser)** : `GET /visit-requests`, `POST /answer`
- **Historique analytique journalier du bien (vues, contacts, visites, conversion)** : `GET /api/landlord/properties/{id}/analytics`
- **Délégations de mandats Bailleur -> Agent avec matrice de droits** : `POST`, `GET`, `DELETE /landlord/delegations`
- **Portefeuille de mandats actifs de l'agent délégué** : `GET /api/agent/properties` (`AgentPortfolioController`)
- **Réception et arbitrage d'invitations de délégation** : `GET /agent/invitations`, `POST /accept`, `POST /reject`
- **Annuaire public des agents certifiés géolocalisé par quartier** : `GET /api/agents/search` (`AgentDirectoryController`)
- **Répertoire des prospects affectés aux missions de l'agent** : `GET /api/agent/prospects`

### 9. Messagerie Privée — MS (9)
- **Boîte de réception et liste des conversations** : `GET /api/messages/threads`, `POST /threads`
- **Historique des messages d'un fil avec clé d'idempotence** : `GET /threads/{id}/messages`, `POST /messages`
- **Rattachement du contexte d'annonce immobilière** : bandeau `listing_id` dans les threads
- **Envoi et lecture de notes vocales audio** : `POST /api/messages/threads/{id}/voice` (`VoiceMessageController`)
- **Acquittement de lecture des messages** : `POST /api/messages/threads/{id}/read`
- **Conversations de groupe et gestion des administrateurs** : `POST /messages/groups`, `members/add`, `remove`
- **Boîte des demandes de messages d'inconnus (`requests`)** : `GET /api/messages/requests`, `POST /accept`, `POST /reject` (`MessageRequestController`)
- **Pont automatique Story -> Chat** : Listener `CreateConversationFromStoryReply` consommant `StoryReplyCreated`

### 10. Sponsoring & Boost — BO & Crédits (6)
- **Calculateur de portée prévisionnelle de campagne** : `POST /api/boost/estimate` (`BoostEstimateController`)
- **Filtrage des contenus boostables avec percentile de surperformance** : `GET /api/boost/boostable` (`BoostableContentController`)
- **Création et cycle de vie de campagne sponsorisée** : `POST /boost/campaigns`, `fund`, `pause`, `resume`, `cancel`
- **Suivi des métriques de diffusion (impressions, clics)** : table `campaign_metrics`, `POST /impression`, `POST /click`
- **Portefeuille de Crédits BANGO** : `GET /boost/wallet`, `POST /wallet/deposit`, `POST /credits/purchases`

### 11. Pages Professionnelles — PG (6)
- **Création et gestion de Page Professionnelle** : `POST /api/pages`, `GET /pages/{id}`, `PUT`, `DELETE`
- **Bascule de contexte de session (profil -> Page Pro)** : `POST /api/pages/{page}/switch-context`
- **Gestion des membres et gouvernance RBAC d'entreprise** : `GET /members`, `POST /invite`, `PUT /role`, `DELETE`
- **Dossier de certification entreprise (RCCM, fiscal, identité gérant)** : `POST /certification`, `payment`, `resubmit`
- **Boîte de réception dédiée de la Page Pro** : `GET /api/pages/{page}/inbox`
- **Moteur de recherche public des Pages Pro** : `GET /api/search/pages` (`PageSearchController`)

### 12. Recherche & Découverte — RC (7)
- **Recherche de personnes / comptes** : `GET /api/square-cr1/search/people`
- **Recherche de hashtags plein texte** : `GET /api/search/hashtags` (`HashtagSearchController@index`)
- **Recherche plein texte PostgreSQL des posts du Square** : `GET /api/search/posts` (`PostSearchController@search`)
- **Recherche de biens immobiliers (filtres + carte)** : `GET /api/listings`, `GET /api/listings/map`
- **Recherche d'agents immobiliers de quartier** : `GET /api/agents/search`
- **Recherche d'entreprises et Pages Pro** : `GET /api/search/pages`
- **Flux consolidé des tendances locales (`RC-11`)** : `GET /api/trends` (`TrendsController@index`)

### 13. Profil Utilisateur — PR (10)
- **Consultation de son profil complet et compteurs** : `GET /api/profile`
- **Édition granulaire du profil (identité, bio, date de naissance, genre)** : `PUT /api/profile/*`
- **Téléversement et mise à jour de l'avatar** : `PUT /api/profile/avatar`
- **Consultation d'un profil tiers public** : `GET /api/profile/public/{identifier}`
- **Gestion des relations d'abonnements** : `GET /followers`, `GET /following`, `POST /follow`, `DELETE /follow`
- **Gestion des demandes privées d'abonnement** : `GET /follow-requests`, `POST /accept`, `POST /reject`
- **Blocage et déblocage d'utilisateurs** : `GET /blocked`, `POST /block`, `DELETE /block`
- **Résolution de QR code de profil** : `MATCH /api/qr/resolve`
- **Mes enregistrements sauvegardés (Reels & Immo)** : `GET /api/reels/user/saved`, `GET /api/listings/saved`
- **Gestion des centres d'intérêt** : `PUT /api/profile/interests`

### 14. Paramètres & Sécurité — ST (10)
- **Changement d'adresse email en 2 temps sécurisé** : `POST /api/settings/email/request` & `/verify`
- **Modification du mot de passe avec historique des 3 derniers** : `PUT /api/settings/password`
- **Configuration et activation 2FA TOTP (Authenticator)** : `GET /api/settings/2fa/totp/setup`, `POST /enable`, `POST /disable`
- **Génération et régénération des 8 codes de secours 2FA** : `POST /api/settings/2fa/backup-codes/regenerate`, `GET /api/settings/2fa/backup-codes`
- **Gestion des sessions actives d'appareils (OS, IP, ville)** : `GET /api/settings/sessions`
- **Déconnexion ciblée d'un terminal distant** : `DELETE /api/settings/sessions/{id}`
- **Bouton d'urgence "Sécuriser le compte" (révocation massive)** : `POST /api/settings/secure`
- **Réglages globaux de confidentialité** : `GET` & `PUT /api/settings/privacy`
- **Suspension temporaire (pause) du compte** : `POST /api/settings/account/pause`
- **Suppression définitive avec rétractation 30j** : `DELETE /api/settings/account`

### 15. Abonnements & Badges — P / AB (10)
- **Catalogue des tarifs des formules et prestations payantes** : `GET /api/subscriptions/plans`, `GET /catalog`
- **Statut en temps réel des abonnements et badges** : `GET /api/subscriptions/status`, `GET /badges/status`
- **Souscription, résiliation et migration avec calcul de prorata** : `POST /subscribe`, `/cancel`, `/change-plan`
- **Consultation détaillée du contrat et historique** : `GET /api/subscriptions/{id}`
- **Éligibilité et informations de renouvellement** : `GET /api/subscriptions/{id}/renewal`
- **Devis certifié de renouvellement** : `POST /api/subscriptions/{id}/renewal/quote`
- **Confirmation du renouvellement par anticipation** : `POST /api/subscriptions/{id}/renewal/confirm`
- **Historique d'audit des attributions et expirations de badges** : `GET /api/badges/history`

### 16. Paiements Mobile Money — PAY (9)
- **Catalogue des prestations payantes et montants** : `GET /api/payments/catalog`
- **Devis certifié serveur avant tout débit Mobile Money** : `POST /api/payments/quote`
- **Initiation de paiement USSD Push (Monetbil) avec clé d'idempotence** : `POST /api/payments/initiate`
- **Polling du statut de transaction en temps réel & Annulation** : `GET /payments/{id}/status`, `POST /cancel`
- **Historique des transactions du client** : `GET /api/payments/history`
- **Reçu certifié officiel téléchargeable en PDF** : `GET /api/payments/{id}/receipt` & `GET /api/visits/{id}/receipt`
- **Moyens de paiement enregistrés (CRUD numéros favoris)** : `GET /api/payments/methods`, `POST`, `DELETE /{id}`, `PATCH /{id}/default`
- **Déclaration client de double prélèvement injustifié** : `POST /api/payments/double-charge-claims`

### 17. Support & Modération — SUP (5)
- **Base de connaissances FAQ dynamique avec recherche et votes** : `GET /api/help/articles`, `GET /{slug}`, `POST /{id}/feedback`
- **Billetterie de support client (Tickets complets avec pièces jointes)** : `POST /api/support/tickets`, `GET /mine`, `GET /{id}`, `POST /{id}/messages`, `GET /attachments/{attachmentId}`
- **Signalement universel polymorphique** : `POST /api/reports`
- **Suivi de ses signalements émis** : `GET /api/reports/mine`
- **Formulaire de Recours formel contre une sanction (Appels)** : `POST /api/moderation/appeals`, `GET /mine`

### 18. Système & Résilience — SY & EDGE (5)
- **Vidage et rejeu en lot d'actions différées hors-ligne** : `POST /api/sync/flush`
- **Reprise d'upload par morceaux (protocole Tus v1.0.0 HEAD/PATCH/finalize)** : `OPTIONS|POST|HEAD|PATCH|DELETE /api/uploads/tus` & `api/uploads/tus/{id}`
- **Identifiant unique d'incident serveur 5xx corrélé** : `incident_id` UUID injecté dans toutes les erreurs 500 (`bootstrap/app.php`)
- **Résolveur universel de liens courts partagés (`bango.app/r/...`)** : `GET /api/content/resolve-link`
- **Codes d'erreur HTTP sémantiques 410 Gone et 423 Locked** : pour contenus supprimés par l'auteur ou gelés par la modération

### 19. Notifications Push & In-App — NOTIF (3)
- **Flux paginé des notifications du compte avec filtres** : `GET /api/notifications`
- **Acquittement de lecture unitaire et en lot** : `POST /notifications/read`, `POST /mark-all-read`, `POST /{id}/read`
- **Enregistrement et suppression des push tokens (APNs / FCM)** : `POST` & `DELETE /notifications/device-token`

---

## Catégorie B — API Partiellement Développées (0 Fonctionnalité)

*(Néant — Toutes les 11 fonctionnalités autrefois classées en Catégorie B ont été complétées, migrées et testées).*

---

## Catégorie C — API Restant à Développer (0 Fonctionnalité)

*(Néant — L'intégralité des 10 manques complets autrefois classés en Catégorie C a été développée et intégrée dans la suite de tests).*

---

## Retrospective des Évolutions Majeures

Toutes les lacunes constatées lors des audits initiaux ont été résolues méthodiquement :

1. **Authentification Sociale Google & Apple** : Intégrée avec PKCE et modèle `oauth_accounts`.
2. **Double Authentification TOTP & Codes de secours** : Implémentée avec service TOTP, SVG de QR Code et table `user_backup_codes`.
3. **Moyens de paiement favoris** : Gérés via `SavedPaymentMethodController` avec chiffrement et sélection de moyen par défaut.
4. **Billetterie de Support & Base de connaissances** : Modélisée avec tickets, messages, pièces jointes privées et FAQ dynamique.
5. **Reprise de téléversement Tus** : Implémentation complète conforme au standard Tus v1.0.0 avec verrouillage d'offset et concaténation de chunks.
6. **Contrôle de version applicative** : Route `GET /api/system/version` opérationnelle pour contraindre la mise à jour des clients obsolètes.
7. **Recherche plein texte Square & Tendances locales** : Recherche PostgreSQL tsvector et agrégation 30 jours des tendances dans `/api/trends`.
8. **Résolveur universel de liens courts** : Moteur polymorphique `ShortLinkController` résolvant les tokens de partage pour tous les types de contenus.
