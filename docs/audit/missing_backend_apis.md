# Analyse des Écarts API — Développées, Partielles et Restant à Développer

**Projet :** BANGO Platform (Frontend React Native & Backend Laravel)  
**Date de l'audit :** Octobre 2026  
**Objectif :** Répertorier avec précision ce qui existe, ce qui est incomplet, ce qui manque totalement et ce qui nécessite clarification.

---

## Catégorie A — API Déjà Développées

Opérations métier identifiées dans le frontend dont l'implémentation backend est **complète et opérationnelle** (route déclarée, contrôleur, validation, modèles et persistance en base de données).

### 1. Authentification, Session & Identité
- **Création de compte par email/mot de passe** : `POST api/auth/signup` (`Auth\SignupController`)
- **Connexion par identifiant (email ou téléphone) & mot de passe** : `POST api/auth/signin` (`Auth\SigninController`)
- **Validation du second facteur (2FA)** : `POST api/auth/signin/second-factor` (`Auth\SecondFactorController`)
- **Déconnexion de session (Révocation de token)** : `POST api/auth/logout` (`Auth\LogoutController`)
- **Consultation de l'utilisateur connecté** : `GET api/user` (`Closure UserResource`)
- **Émission de code OTP (SMS)** : `POST api/otp/request` (`Otp\OtpController@requestOtp`)
- **Vérification de code OTP** : `POST api/otp/verify` (`Otp\OtpController@verifyOtp`)
- **Renvoi de code OTP** : `POST api/otp/resend` (`Otp\OtpController@resendOtp`)
- **Mot de passe oublié — Demande d'OTP** : `POST api/auth/forgot-password` (`Auth\ForgotPasswordController@forgotPassword`)
- **Mot de passe oublié — Vérification du code** : `POST api/auth/forgot-password/verify` (`Auth\ForgotPasswordController@verify`)
- **Mot de passe oublié — Nouveau mot de passe** : `POST api/auth/forgot-password/reset` (`Auth\ForgotPasswordController@reset`)
- **Réinitialisation par lien magique** : `POST api/auth/reset-password/request` & `POST api/auth/reset-password` (`Auth\ResetPasswordController`)
- **Diagnostic de compte suspendu / supprimé** : `POST api/auth/account-state` (`Auth\AccountStateController`)
- **Rétablissement d'un compte soft-deleted sous 30 jours** : `POST api/auth/restore-account` (`Profile\AccountController@restore`)
- **Enregistrement de la progression d'onboarding (6 étapes)** : `POST api/onboarding/complete` (`Onboarding\OnboardingController@completeStep`)

### 2. Profil, Réseau Social & Paramètres
- **Consultation de son profil complet** : `GET api/profile` (`Profile\ProfileController@show`)
- **Consultation d'un profil public tiers** : `GET api/profile/public/{identifier}` (`Profile\ProfileController@showPublic`)
- **Vérification d'un pseudonyme unique (`@handle`)** : `POST api/profile/handle-check` (`Profile\HandleCheckController`)
- **Mise à jour d'identité (nom, handle, bio)** : `PUT api/profile/identity` (`Profile\ProfileController@updateIdentity`)
- **Mise à jour de date de naissance (contrôle ≥ 18 ans)** : `PUT api/profile/birthdate` (`Profile\ProfileController@updateBirthdate`)
- **Mise à jour du genre (avec option privé)** : `PUT api/profile/gender` (`Profile\ProfileController@updateGender`)
- **Mise à jour de l'ancrage géographique** : `PUT api/profile/location` (`Profile\ProfileController@updateLocation`)
- **Mise à jour de la biographie** : `PUT api/profile/bio` (`Profile\ProfileController@updateBio`)
- **Mise à jour des centres d'intérêt** : `PUT api/profile/interests` (`Profile\ProfileController@updateInterests`)
- **Mise à jour de l'avatar photo** : `PUT api/profile/avatar` (`Profile\AvatarController`)
- **Gestion des relations d'abonnements** : `GET api/profile/followers`, `GET api/profile/following`, `POST /follow`, `DELETE /follow`, `POST /toggle-follow` (`Profile\FollowController`)
- **Gestion des demandes privées d'abonnement** : `GET api/profile/follow-requests`, `POST /accept`, `POST /reject`, `POST /accept-all` (`Profile\FollowRequestController`)
- **Blocage et déblocage d'utilisateurs** : `GET api/profile/blocked`, `POST /block`, `DELETE /block` (`Profile\BlockController`)
- **Changement d'adresse email sécurisé (2 étapes)** : `POST api/settings/email/request` & `POST api/settings/email/verify` (`Profile\EmailController`)
- **Mise à jour du mot de passe authentifiée** : `PUT api/settings/password` (`Profile\PasswordController@update`)
- **Gestion des sessions actives d'appareils** : `GET api/settings/sessions`, `DELETE /sessions/{id}`, `POST /acknowledge` (`Profile\SecurityController`)
- **Bouton d'urgence "Sécuriser le compte"** : `POST api/settings/secure` (`Profile\SecurityController@secureAccount`)
- **Suspension temporaire (pause) du compte** : `POST api/settings/account/pause` (`Profile\AccountController@pause`)
- **Suppression programmée du compte** : `DELETE api/settings/account` (`Profile\AccountController@delete`)
- **Préférences de recherche de logement** : `GET` & `PUT api/housing/search` (`Housing\HousingSearchController`)
- **Résolution de QR code de profil** : `MATCH api/qr/resolve` (`Profile\QrController@resolve`)

### 3. Square (CR1) — Posts & Interactions Sociales
- **Fil d'actualité de quartier paginé** : `GET api/square-cr1/square/feed` (`Square\PostFeedController@index`)
- **Création complète d'un post Square** : `POST api/square-cr1/posts` (`Square\PostController@store`)
- **Consultation d'une publication** : `GET api/square-cr1/posts/{post}` (`Square\PostController@show`)
- **Modification de la légende d'un post** : `PATCH api/square-cr1/posts/{post}/caption` (`Square\PostCaptionController@update`)
- **Modification de l'audience d'un post** : `PATCH api/square-cr1/posts/{post}/audience` (`Square\PostAudienceController@update`)
- **Gestion du statut des commentaires** : `PATCH api/square-cr1/posts/{post}/comments-status` (`Square\PostCommentsStatusController@update`)
- **Archivage et désarchivage de post** : `POST api/square-cr1/posts/{post}/archive` & `/unarchive` (`Square\PostArchiveController`)
- **Suppression définitive de post** : `DELETE api/square-cr1/posts/{post}` (`Square\PostController@destroy`)
- **Like et Unlike de post** : `POST` & `DELETE api/square-cr1/posts/{post}/like` (`Square\PostLikeController`)
- **Partage de post (incrément atomique)** : `POST api/square-cr1/posts/{post}/share` (`Square\PostShareController@store`)
- **Commentaires de post (liste, ajout, sous-réponses, édition, suppression)** : `api/square-cr1/posts/{post}/comments`, `api/square-cr1/comments/{comment}` (`Square\PostCommentController`)
- **Réactions Like/Dislike sur commentaires** : `PUT` & `DELETE api/square-cr1/comments/{comment}/reaction` (`Square\PostCommentReactionController`)
- **Téléversement de médias pour carrousel Square** : `POST api/square-cr1/media/upload` (`Media\MediaUploadController@upload`)
- **Recherche d'utilisateurs à identifier** : `GET api/square-cr1/search/people` (`Search\PeopleSearchController@index`)
- **Recherche de lieux BANGO** : `GET api/square-cr1/search/places` (`Search\PlaceSearchController@index`)
- **Hashtags tendances** : `GET api/square-cr1/search/hashtags/trending` (`Search\HashtagSearchController@trending`)

### 4. Reels (CR2) & Studio Vidéo
- **Fil vertical des Reels** : `GET api/reels/feed` (`Reel\ReelFeedController@feed`)
- **Consultation et suppression d'un Reel** : `GET api/reels/{id}` & `DELETE api/reels/{reel}` (`Reel\ReelFeedController`)
- **Brouillons de montage Reel (CRUD complet)** : `api/reels/drafts/*` (`Reel\StudioReelController`)
- **Téléversement de médias vidéo/audio pour Reel** : `POST api/reels/media/upload` (`Reel\StudioReelController@uploadMedia`)
- **Publication asynchrone de Reel (Job transcodage)** : `POST api/reels/publish` (`Reel\StudioReelController@publish`)
- **Catalogue musical, favoris et Reels liés** : `api/reels/sounds/*` (`Reel\SoundController`)
- **Enregistrement de vue dédupliquée 30 min** : `POST api/reels/{reel}/view` (`Reel\ReelInteractionController@view`)
- **Likes & Dislikes exclusifs sur Reel** : `POST` & `DELETE api/reels/{reel}/like` & `/dislike` (`Reel\ReelInteractionController`)
- **Sauvegarde et partage de Reel** : `POST api/reels/{reel}/save`, `GET /user/saved`, `POST /share` (`Reel\ReelInteractionController`)
- **Feedback "Pas intéressé"** : `POST api/reels/{reel}/not-interested` (`Reel\ReelInteractionController@markNotInterested`)
- **Commentaires et réactions de Reels** : `api/reels/{reel}/comments/*` (`Reel\ReelCommentController`)

### 5. Stories (CR3) & Collections À la une
- **Rail horizontal de Stories actives** : `GET api/stories/rail` (`Story\StoryController@rail`)
- **Publication d'une Story multi-diapositives** : `POST api/stories` (`Story\StoryController@store`)
- **Mes Stories actives et archivées** : `GET api/stories/mine`, `GET /mine/{id}`, `GET /archived` (`Story\StoryController`)
- **Consultation nominative des spectateurs (privé)** : `GET api/stories/{id}/viewers` (`Story\StoryController@viewers`)
- **Vote unique sur sondage de Story** : `POST api/stories/{slideId}/poll/{stickerId}/vote` (`Story\StoryController@pollVote`)
- **Réponse privée Story (intention & snapshot)** : `POST api/stories/{id}/reply` (`Story\StoryController@reply`)
- **Signalement de Story pour modération** : `POST api/stories/{id}/report` (`Story\StoryController@report`)
- **Gestion de sa liste choisie (amis proches)** : `GET` & `PUT api/stories/close-list` (`Story\CloseListController`)
- **Collections permanentes À la une (Highlights)** : `api/highlights/*` (`Story\HighlightController`)

### 6. Abonnements, Badges & Paiements Mobile Money
- **Catalogue public des formules et tarifs** : `GET api/subscriptions/plans`, `GET api/catalog` (`Subscription\CatalogController`)
- **Statut en temps réel des abonnements et badges** : `GET api/subscriptions/status`, `GET api/badges/status` (`Subscription\SubscriptionController`)
- **Souscription d'abonnement / badge** : `POST api/subscriptions/subscribe` (`Subscription\SubscriptionController@subscribe`)
- **Résiliation et migration de plan** : `POST /cancel`, `POST /change-plan` (`Subscription\SubscriptionController`)
- **Renouvellement assisté (Info, Devis certifié, Confirmation)** : `api/subscriptions/{id}/renewal/*` (`Subscription\SubscriptionRenewalController`)
- **Historique des badges** : `GET api/badges/history` (`Subscription\SubscriptionController@history`)
- **Catalogue des tarifs des prestations payantes** : `GET api/payments/catalog` (`Payment\PaymentController@catalog`)
- **Devis certifié de paiement avant débit** : `POST api/payments/quote` (`Payment\PaymentController@quote`)
- **Initiation de paiement USSD Push (Monetbil) avec clé d'idempotence** : `POST api/payments/initiate` (`Payment\PaymentController@initiate`)
- **Polling de statut de paiement en temps réel** : `GET api/payments/{id}/status` (`Payment\PaymentController@status`)
- **Annulation et historique des transactions** : `POST /cancel`, `GET /history` (`Payment\PaymentController`)
- **Données géographiques publiques (Pays, Villes, Quartiers, Devises, Opérateurs)** : `GET api/geo/*`

---

## Catégorie B — API Partiellement Développées

Opérations dont une partie de la logique existe dans le backend, mais pour lesquelles **des endpoints, paramètres ou règles indispensables au frontend manquent**.

### 1. Pseudonymes & Suggestions
- **Ce qui existe :** `POST /api/profile/handle-check` vérifie unitairement si un pseudonyme spécifique est valide et disponible.
- **Ce qui manque :** L'écran `AU-16` attend un service de suggestion automatique de pseudonymes disponibles à partir du nom complet et de la ville (`suggestHandles(displayName, city)`).

### 2. Ré-acceptation des CGU (Légal)
- **Ce qui existe :** L'acceptation initiale des CGU est horodatée lors du signup (`terms_accepted_at`).
- **Ce qui manque :** Aucun endpoint `legal.acceptTerms(version)` permettant d'enregistrer l'acceptation d'une nouvelle version de CGU et de vérifier si l'utilisateur est bloqué par une mise à jour contractuelle obligatoire (`LG-07`).

### 3. Liste des personnes ayant aimé une publication
- **Ce qui existe :** Le compteur global de likes est renvoyé avec la publication Square (`likes_count`).
- **Ce qui manque :** L'écran `SQ-14` requiert la liste paginée nominative des utilisateurs ayant aimé la publication (`listReactors(postId)`), avec différenciation entre un profil ordinaire et un agent immobilier vérifié.

### 4. Alertes de recherche immobilière
- **Ce qui existe :** `HousingSearchController` permet d'enregistrer les critères d'intention de logement de l'utilisateur (`PUT /api/housing/search`).
- **Ce qui manque :** Aucun moteur d'alerte pour les annonces (`createAlert`, `listAlerts`, `toggleAlert`). Aucun déclencheur ne notifie l'utilisateur lorsqu'un bien correspondant à ses critères est mis en ligne.

### 5. Contenus éligibles au Boost publicitaire
- **Ce qui existe :** Les publications et les Reels de l'utilisateur peuvent être listés via leurs endpoints respectifs.
- **Ce qui manque :** L'écran `BO-01` attend un endpoint dédié `listBoostable` qui filtre les contenus éligibles (exclut les annonces et stories), agrège les interactions (likes, commentaires, partages) et calcule le score de sur-performance relative ("Performe mieux que 82% de tes contenus").

### 6. Rapprochement Story -> Messagerie
- **Ce qui existe :** `POST /api/stories/{id}/reply` enregistre l'interaction dans `story_replies`, génère un snapshot autonome de la Story dans `metadata`, émet l'événement `StoryReplyCreated`, et réserve les colonnes `future_conversation_id` et `future_message_id`.
- **Ce qui manque :** Le module Messagerie cible n'existe pas encore dans le backend pour écouter cet événement, instancier une conversation et convertir cette intention en message de chat effectif.

### 7. Reçus de paiement vérifiables
- **Ce qui existe :** L'historique et le détail des transactions sont fournis via `PaymentController@history` et `PaymentController@status`.
- **Ce qui manque :** L'écran `PAY-23` promet un "Reçu téléchargeable et vérifiable en ligne". Il manque l'URL publique de vérification cryptographique et la génération de document PDF certifié par le serveur (`payments.receipt`).

### 8. Réclamations de double débit
- **Ce qui existe :** Le back-office administrateur dispose de `POST /api/admin/payments/double-charge-claims` pour enregistrer un incident investigué.
- **Ce qui manque :** Aucun endpoint client mobile permettant à l'utilisateur de soumettre lui-même sa réclamation avec pièces justificatives depuis l'écran `app/double-charge.tsx` (`PAY-15`).

### 9. Signalements modération
- **Ce qui existe :** Uniquement `POST /api/stories/{id}/report` pour les Stories (vers `story_reports`).
- **Ce qui manque :** Aucun endpoint de signalement pour les publications du Square (`SQ-07`), les Reels (`RL-06`), les annonces immobilières (`IM-28`, `IM-29`) ni les profils d'utilisateurs (`SUP-10`).

---

## Catégorie C — API Restant à Développer (Manques Complets)

Fonctionnalités métier identifiées dans le frontend pour lesquelles **aucune route, aucun contrôleur et aucune table de données n'existent dans le backend**.

---

### 1. Domaine Immobilier (IM & CR4)
Le backend actuel ne possède **aucune table relative aux logements** (pas de table `listings`, `properties`, `listing_media`, `listing_visits`, `visit_slots`).

1. **`POST /api/listings`** : Création et soumission d'une annonce immobilière complète (titre, localisation quartier, prix loyer, caution, avance, charges incluses/exclues, devise, caractéristiques techniques, vidéo guidée, photos qualifiées par pièce).
2. **`GET /api/listings`** : Feed vertical et paginé des annonces avec visites vidéo, filtres de mode (location, vente, colocation) et statut de disponibilité (`available`, `taken`).
3. **`GET /api/listings/{id}`** : Fiche détaillée d'un bien avec liste ordonnée des photos de pièces (`living`, `bedroom`, `kitchen`, etc.) et contact du gestionnaire.
4. **`GET /api/listings/map`** : Recherche immobilière par emprise géographique (coordonnées `bounds: north, south, east, west`) avec clustering serveur.
5. **`GET /api/listings/count`** : Comptage dynamique instantané du nombre de biens correspondant à un ensemble de filtres (`countMatching`).
6. **`GET /api/listings/{id}/slots`** : Grille des disponibilités de visite configurées par le bailleur (`listVisitDays`).
7. **`POST /api/listings/{id}/visits`** : Réservation d'un créneau de visite avec retenue temporaire (`holding`) et initiation des frais de visite.
8. **`POST /api/visits/{id}/confirm`** : Confirmation de la visite après validation du paiement Mobile Money et transmission de l'adresse exacte au prospect.
9. **`GET /api/visits/mine`** : Liste des visites programmées de l'utilisateur connecté avec statut (`awaiting_payment`, `confirmed`, `cancelled`).
10. **`POST /api/visits/{id}/cancel`** : Annulation d'une visite avec déclenchement automatique du remboursement ou du Crédit BANGO.
11. **`POST /api/listings/{id}/save`** & **`GET /api/listings/saved`** : Mise en favori et consultation de sa liste de biens enregistrés.
12. **`POST /api/listings/form/draft`** & **`GET /api/listings/form/draft`** : Persistance serveur du brouillon de formulaire d'annonce guidée (`listingForm.saveListingForm`).
13. **`POST /api/listings/{id}/report-taken`** : Signalement prospect certifiant qu'un bien en ligne est déjà loué (`IM-29`).

---

### 2. Domaine Espace Bailleur (BL)

1. **`GET /api/landlord/properties`** : Tableau de bord du bailleur listant ses biens avec compteurs d'activité (vues vidéo, enregistrements, demandes en attente).
2. **`PATCH /api/landlord/properties/{id}/status`** : Modification du statut du bien (`published`, `reserved`, `taken`).
3. **`POST /api/landlord/properties/{id}/taken`** : Marquer un bien comme loué avec arrêt automatique des campagnes publicitaires associées et notification aux prospects.
4. **`GET /api/landlord/properties/{id}/stats`** : Métriques détaillées d'audience et de conversion d'une annonce.
5. **`GET` & `PUT /api/landlord/properties/{id}/slots`** : Configuration des créneaux horaires d'ouverture des visites pour un bien.
6. **`GET /api/landlord/visit-requests`** : Liste des demandes de visite reçues avec possibilité d'acceptation, report ou refus.
7. **`POST /api/landlord/kyc/submit`** : Dépôt des pièces d'identité et titres de propriété pour homologation du Badge Bailleur.

---

### 3. Domaine Espace Agent Immobilier (AG)

1. **`GET /api/agent/properties`** : Portefeuille des mandats actifs confiés à l'agent connecté.
2. **`GET /api/agent/invitations`** : Liste des invitations de délégation reçues de la part de bailleurs.
3. **`POST /api/agent/invitations/{id}/accept`** & `/reject` : Réponse à une proposition de mandat.
4. **`POST /api/landlord/delegations`** : Invitation d'un agent par un bailleur avec périmètre de capacités (`visits`, `reply`, `edit`).
5. **`DELETE /api/landlord/delegations/{id}`** : Révocation du mandat d'un agent (passage immédiat en lecture seule pour l'agent).
6. **`GET /api/agents/search`** : Moteur de recherche et annuaire d'agents certifiés par ville et quartier.
7. **`GET /api/agent/prospects`** : Répertoire des contacts et prospects liés aux annonces déléguées à l'agent.

---

### 4. Domaine Messagerie Privée (MS)
Le backend actuel ne possède **aucune table relative à la messagerie** (pas de table `threads`, `conversations`, `messages`, `attachments`, `thread_members`).

1. **`GET /api/messages/threads`** : Liste paginée des conversations actives avec dernier message, état de lecture et contexte.
2. **`POST /api/messages/threads`** : Démarrage d'un nouveau fil de discussion (individuel ou avec contexte annonce immobilière).
3. **`GET /api/messages/threads/{id}/messages`** : Historique chronologique des messages d'une conversation.
4. **`POST /api/messages/threads/{id}/messages`** : Envoi d'un message texte ou média avec clé d'idempotence.
5. **`POST /api/messages/threads/{id}/voice`** : Téléversement et enregistrement d'une note vocale audio.
6. **`POST /api/messages/threads/{id}/read`** : Acquittement de lecture des messages d'un fil.
7. **`POST /api/messages/groups`** : Création d'une conversation de groupe avec attribution du rôle administrateur.
8. **`PUT /api/messages/groups/{id}/members`** : Ajout ou retrait de membres dans un groupe de discussion.
9. **`GET /api/messages/requests`** : Boîte des demandes de messages en attente d'approbation.
10. **`POST /api/messages/requests/{id}/accept`** & `/reject` : Traitement d'une demande de message.

---

### 5. Domaine Pages Professionnelles (PG)

1. **`POST /api/pages`** : Création d'une page professionnelle d'entreprise ou d'agence.
2. **`GET /api/pages/mine`** : Liste des pages administrées par l'utilisateur connecté.
3. **`GET /api/pages/{id}`** : Fiche publique d'une page professionnelle.
4. **`PUT /api/pages/{id}/roles`** : Gestion des droits RBAC des collaborateurs (Propriétaire, Éditeur, Modérateur).
5. **`POST /api/pages/{id}/certify`** : Soumission du dossier de certification entreprise (RCCM, fiscal, identité gérant).
6. **`GET /api/pages/{id}/certify/status`** : Suivi de l'instruction du dossier de certification.

---

### 6. Domaine Boost & Publicité (BO)

1. **`POST /api/boost/estimate`** : Calculateur de portée prévisionnelle basé sur le budget, la durée et les critères de ciblage.
2. **`POST /api/boost/campaigns`** : Création et lancement d'une campagne de mise en avant payante d'un post ou reel.
3. **`GET /api/boost/campaigns/{id}`** : Statistiques en direct d'une campagne active (impressions, clics, répartition géographique).
4. **`POST /api/boost/campaigns/{id}/pause`** & `/cancel` : Interruption anticipée d'une campagne avec recrédit au prorata.
5. **`GET /api/user/credits`** : Consultation de son solde de Crédits BANGO utilisables en déduction de paiement.

---

### 7. Domaine Support, Signalements Généraux & Recours (SUP)

1. **`GET /api/support/articles`** & `/{id}` : Consultation de la base de connaissances et de la FAQ.
2. **`POST /api/support/tickets`** : Ouverture d'un ticket d'assistance client avec pièces jointes.
3. **`GET /api/support/tickets`** & `/{id}` : Suivi de ses tickets de support et échange de réponses.
4. **`POST /api/reports`** : Signalement universel anonyme (post Square, reel, annonce immobilière, compte).
5. **`GET /api/reports/mine`** : Suivi du statut de traitement de ses signalements émis (`SUP-13`).
6. **`POST /api/moderation/appeals`** : Dépôt formel d'un recours contre une suspension ou un avertissement (`app/appeal.tsx`).

---

### 8. Domaine Notifications Push & Alertes (NOTIF)

1. **`GET /api/notifications`** : Flux paginé des notifications du compte (alertes immo, interactions sociales, sécurité).
2. **`POST /api/notifications/{id}/read`** : Marquer une notification comme lue.
3. **`POST /api/notifications/mark-all-read`** : Acquittement global des notifications.
4. **`POST /api/notifications/device-token`** : Enregistrement du token push (APNs / FCM) pour l'appareil connecté.

---

### 9. Domaine États Système & Infrastructure (SY & RGPD)

1. **`POST /api/sync/flush`** : Synchronisation en lot d'actions effectuées hors-ligne (`like`, `save`, `follow`).
2. **`HEAD` & `PATCH /api/uploads/{id}`** : Protocole de téléversement par morceaux (Tus) avec reprise sur coupure réseau.
3. **`POST /api/user/data-export`** : Demande d'archive ZIP des données personnelles RGPD (`LG-10`).
4. **`GET /api/user/data-export/status`** : Vérification de la disponibilité de l'archive d'export.

---

## Catégorie D — Besoins à Clarifier (Spécifications Ambiguës)

Fonctionnalités frontend dont les règles métier ou les arbitrages de design comportent des contradictions ou des zones d'ombre nécessitant une décision produit avant développement.

1. **Cadence des relances de prélèvement d'abonnement échoué** :
   - Dans le frontend, l'écran `AB-05` annonce des tentatives de prélèvement à **J+2 et J+5**.
   - L'écran `P-02` annonce des relances à **Demain, J+3 et J+7**.
   - *Clarification requise :* Aligner la politique de recouvrement de la période de grâce (7 jours) avant de coder le cron de prélèvement.

2. **Politique de conversion monétaire pour les réservations de visite transfrontalières** :
   - Une annonce publiée au Cameroun (en XAF) peut être consultée et réservée par un utilisateur connecté depuis le Ghana (en GHS).
   - *Clarification requise :* Déterminer si le prospect paie les frais de visite dans la devise du bien (avec conversion au taux du jour par l'agrégateur) ou si le montant est débité dans sa devise locale avec marge de change appliquée.

3. **Statut des annonces en cas de révocation d'un agent par un bailleur** :
   - Le design system indique que l'agent révoqué conserve l'accès en "lecture seule" sur ses visites menées (`AG-06`), mais ne spécifie pas si les visites futures déjà programmées avec cet agent sont maintenues ou annulées avec réattribution au bailleur.

4. **Authentification Sociale (SSO Google & Apple) & Détection du premier accès** :
   - Le frontend utilise OAuth natif et transmet un jeton d'identité.
   - *Clarification requise :* Spécifier si le backend doit créer un compte utilisateur complet à la volée dès réception d'un token social valide ou s'il doit forcer la complétion des étapes d'identité (date de naissance, numéro de téléphone, pseudo) via le parcours d'onboarding avant d'accorder l'accès au Square.
