# Matrice de Couverture API — Correspondance Frontend / Backend BANGO

**Projet :** BANGO Mobile (React Native) <-> BANGO Backend (Laravel)  
**Date de l'audit :** Octobre 2026  
**Livrable central :** Analyse de la couverture des fonctionnalités métier frontend par les API backend existantes.

---

## Statuts de Couverture Utilisés

- **Développée** : Une API backend dédiée existe, la route est déclarée et sa logique métier (contrôleur, validation, modèle, base de données) est effectivement implémentée.
- **Partiellement développée** : Une route ou un contrôleur existe mais ne couvre qu'une fraction des opérations requises par le frontend, ou des règles métier essentielles manquent.
- **Absente** : Aucune route ni logique backend n'existe pour répondre au besoin métier identifié dans le frontend.
- **À confirmer** : Le besoin métier ou la correspondance exacte ne peut pas être tranché avec certitude à partir du code actuel (ex. fonctionnalité non spécifiée dans le backend ou comportement ambigu).

---

## Tableau de Correspondance par Module Frontend

### 1. Module SP — Splash & Lancement

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SP** | Splash & Contrôle de version | Vérifier la version minimale requise de l'application mobile | *Aucune route dédiée* | **Absente** | L'écran `update-required.tsx` (`SY-21`) attend un contrôle de version minimale. Actuellement géré en dur dans l'app. |
| **SP** | Langue & Région initiale | Obtenir la liste des pays et langues supportés | `GET /api/geo/countries` | **Développée** | La route publique fournit les pays et indicatifs. |

---

### 2. Module AU — Authentification & Onboarding

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **AU** | Inscription Email & Mot de passe | Création de compte et émission de token | `POST /api/auth/signup` | **Développée** | `SignupController` gère la validation, le hachage et l'émission du token Passport. |
| **AU** | Inscription Sociale (Google / Apple) | Échange de token tiers contre session BANGO | *Aucune route dédiée* | **Absente** | Le frontend génère un idToken via OAuth PKCE (`auth.exchangeProviderToken`), mais aucune route `POST /api/auth/provider` n'existe dans Laravel. |
| **AU** | Connexion classique | Authentification par email ou téléphone | `POST /api/auth/signin` | **Développée** | `SigninController` gère les identifiants, le compteur d'échecs (5 tentatives) et le verrou 15 min. |
| **AU** | Validation Second Facteur (2FA) | Valider le code 2FA pour finaliser la session | `POST /api/auth/signin/second-factor` | **Développée** | `SecondFactorController` valide le code et délivre le token final. |
| **AU** | Émission du code OTP Téléphone | Envoi d'un code SMS à 6 chiffres | `POST /api/otp/request` | **Développée** | `OtpController@requestOtp` gère la création et l'envoi de code OTP. |
| **AU** | Vérification du code OTP | Contrôler le code saisi et déverrouiller l'étape | `POST /api/otp/verify` | **Développée** | `OtpController@verifyOtp` vérifie le code et marque le numéro vérifié. |
| **AU** | Renvoi du code OTP | Renvoyer le SMS après délai de temporisation | `POST /api/otp/resend` | **Développée** | `OtpController@resendOtp` gère la cadence de renvoi. |
| **AU** | Choix du pseudo (`@handle`) | Contrôle de validité et disponibilité en direct | `POST /api/profile/handle-check` | **Développée** | `HandleCheckController` valide la syntaxe et vérifie l'unicité en base. |
| **AU** | Suggestions de pseudos libres | Générer des pseudos dérivés disponibles | *Aucune méthode dédiée* | **Partiellement développée** | La vérification unitaire existe, mais pas l'endpoint de génération de suggestions libres (`suggestHandles`). |
| **AU** | Centres d'intérêt | Lister les thématiques disponibles | `GET /api/interests` | **Développée** | Route publique renvoyant la collection des centres d'intérêt. |
| **AU** | Sauvegarde centres d'intérêt | Associer au moins 3 centres au compte | `PUT /api/profile/interests` | **Développée** | `ProfileController@updateInterests` synchronise la table pivot `interest_user`. |
| **AU** | Préférences de logement | Enregistrer intention, budget, ville, quartiers | `PUT /api/housing/search` | **Développée** | `HousingSearchController@update` persiste les critères dans `housing_searches`. |
| **AU** | Suggestions de contacts / Amis | Rapprochement chiffré du carnet d'adresses | *Aucune route dédiée* | **Absente** | L'écran `AU-19` demande un matching d'empreintes de numéros (`matchFingerprints`), non implémenté. |
| **AU** | Suivi de progression onboarding | Enregistrer la complétion des 6 étapes | `POST /api/onboarding/complete` | **Développée** | `OnboardingController@completeStep` persiste les étapes dans `onboarding_progress`. |
| **AU** | Mot de passe oublié (demande) | Envoyer un code de récupération par SMS/email | `POST /api/auth/forgot-password` | **Développée** | `ForgotPasswordController@forgotPassword` gère l'émission masquée. |
| **AU** | Mot de passe oublié (vérification) | Valider le code et donner accès au formulaire | `POST /api/auth/forgot-password/verify` | **Développée** | `ForgotPasswordController@verify` délivre un token de réinitialisation. |
| **AU** | Mot de passe oublié (nouveau mdp) | Définir le nouveau mdp sans réutiliser les 3 anciens | `POST /api/auth/forgot-password/reset` | **Développée** | `ForgotPasswordController@reset` contrôle l'historique et met à jour. |
| **AU** | Diagnostic compte suspendu/supprimé | Expliquer le statut à la connexion échouée | `POST /api/auth/account-state` | **Développée** | `AccountStateController` détaille la sanction ou l'échéance de suppression. |
| **AU** | Rétablissement compte supprimé | Annuler la suppression dans la fenêtre 30j | `POST /api/auth/restore-account` | **Développée** | `AccountController@restore` réactive le compte soft-deleted. |

---

### 3. Module LG — Légal, RGPD & Droits

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **LG** | Consultation des CGU / Politique | Obtenir le texte officiel versionné et daté | *Aucune route dédiée* | **Absente** | Le frontend attend `legal.getLegalDocument(section, locale)`. Aucun CMS légal n'existe dans le backend. |
| **LG** | Acceptation des CGU | Enregistrer la version acceptée par le compte | *Aucune route dédiée* | **Partiellement développée** | Un champ existe lors du signup, mais aucun endpoint pour ré-acceptation bloquante des nouvelles CGU (`LG-07`). |
| **LG** | Export des données (RGPD) | Générer et télécharger l'archive personnelle | *Aucune route dédiée* | **Absente** | Les écrans `LG-10` à `LG-12` et `dataExport.ts` n'ont aucun équivalent backend. |
| **LG** | Suppression définitive du compte | Programmer la suppression et déconnecter | `DELETE /api/settings/account` | **Développée** | `AccountController@delete` passe le compte en soft delete avec délai de rétractation. |
| **LG** | Rétractation de suppression | Restaurer le compte avant 30 jours | `POST /api/auth/restore-account` | **Développée** | Endpoint opérationnel. |
| **LG** | Signalement Propriété Intellectuelle | Déposer une plainte pour droits d'auteur | *Aucune route dédiée* | **Absente** | L'écran `app/ip-report.tsx` (`LG-17`) n'a aucun endpoint dédié dans le backend. |

---

### 4. Module SQ — Le Square

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SQ** | Fil du Square | Obtenir le flux paginé par quartier/abonnés | `GET /api/square-cr1/square/feed` | **Développée** | `PostFeedController@index` filtre par scope géographique et gère la pagination. |
| **SQ** | Détail d'une publication | Consulter un post et ses métadonnées | `GET /api/square-cr1/posts/{post}` | **Développée** | `PostController@show` vérifie l'audience et retourne les données complètes. |
| **SQ** | Modifier sa publication (légende) | Modifier le texte (max 2 200 car.) | `PATCH /api/square-cr1/posts/{post}/caption` | **Développée** | `PostCaptionController@update` opérationnel. |
| **SQ** | Modifier l'audience d'un post | Ajuster la visibilité (public, followers, etc.) | `PATCH /api/square-cr1/posts/{post}/audience` | **Développée** | `PostAudienceController@update` opérationnel. |
| **SQ** | Gestion des commentaires (statut) | Ouvrir ou fermer les commentaires | `PATCH /api/square-cr1/posts/{post}/comments-status` | **Développée** | `PostCommentsStatusController@update` opérationnel. |
| **SQ** | Archiver / Désarchiver un post | Masquer du fil sans supprimer | `POST /api/square-cr1/posts/{post}/archive` & `/unarchive` | **Développée** | `PostArchiveController` opérationnel. |
| **SQ** | Supprimer sa publication | Suppression en cascade | `DELETE /api/square-cr1/posts/{post}` | **Développée** | `PostController@destroy` opérationnel. |
| **SQ** | Aimer / Ne plus aimer un post | Like atomique | `POST` & `DELETE /api/square-cr1/posts/{post}/like` | **Développée** | `PostLikeController` opérationnel. |
| **SQ** | Partager une publication | Incrément de partage | `POST /api/square-cr1/posts/{post}/share` | **Développée** | `PostShareController@store` opérationnel. |
| **SQ** | Lister les commentaires | Commentaires paginés et triés | `GET /api/square-cr1/posts/{post}/comments` | **Développée** | `PostCommentController@index` opérationnel. |
| **SQ** | Ajouter un commentaire | Rédiger un commentaire | `POST /api/square-cr1/posts/{post}/comments` | **Développée** | `PostCommentController@store` opérationnel. |
| **SQ** | Réponses aux commentaires | Fil de sous-commentaires | `GET /api/square-cr1/comments/{comment}/replies` | **Développée** | `PostCommentController@replies` opérationnel. |
| **SQ** | Éditer / Supprimer un commentaire | Gestion de son commentaire | `PATCH` & `DELETE /api/square-cr1/comments/{comment}` | **Développée** | `PostCommentController` opérationnel. |
| **SQ** | Réaction sur commentaire | Like / Dislike de commentaire | `PUT` & `DELETE /api/square-cr1/comments/{comment}/reaction` | **Développée** | `PostCommentReactionController` opérationnel. |
| **SQ** | Liste des personnes ayant aimé | Relevé des likes (`SQ-14`) | *Aucune route dédiée* | **Partiellement développée** | Le compteur total est renvoyé avec le post, mais il n'existe pas d'endpoint dédié `GET /posts/{id}/likers`. |
| **SQ** | Signalement de publication | Signaler un post abusif (`SQ-07`) | *Aucune route dédiée* | **Absente** | Seul le signalement de Story existe dans le backend (`POST /api/stories/{id}/report`). Aucun endpoint de signalement pour les posts Square. |

---

### 5. Module RL — Reels

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **RL** | Fil continu des Reels | Défilement vertical vidéo | `GET /api/reels/feed` | **Développée** | `ReelFeedController@feed` gère le curseur et la pagination. |
| **RL** | Consulter un Reel spécifique | Lecture par UUID | `GET /api/reels/{id}` | **Développée** | `ReelFeedController@show` opérationnel. |
| **RL** | Supprimer son Reel | Suppression par l'auteur | `DELETE /api/reels/{reel}` | **Développée** | `ReelFeedController@destroy` opérationnel. |
| **RL** | Enregistrer une vue vidéo | Vue dédupliquée 30 min | `POST /api/reels/{reel}/view` | **Développée** | `ReelInteractionController@view` opérationnel. |
| **RL** | Like / Dislike d'un Reel | Réactions exclusives | `POST` & `DELETE /api/reels/{reel}/like` & `/dislike` | **Développée** | `ReelInteractionController` gère l'exclusivité mutuelle stricte. |
| **RL** | Partager un Reel | Incrément atomique de partage | `POST /api/reels/{reel}/share` | **Développée** | `ReelInteractionController@share` opérationnel. |
| **RL** | Sauvegarder dans les favoris | Mettre en favori privé | `POST /api/reels/{reel}/save` | **Développée** | `ReelInteractionController@toggleSave` opérationnel. |
| **RL** | Lister ses Reels sauvegardés | Historique des favoris | `GET /api/reels/user/saved` | **Développée** | `ReelInteractionController@listSaved` opérationnel. |
| **RL** | « Pas intéressé » | Masquage et feedback algorithmique | `POST /api/reels/{reel}/not-interested` | **Développée** | `ReelInteractionController@markNotInterested` opérationnel. |
| **RL** | Commentaires sur un Reel | Liste et ajout de commentaires | `GET` & `POST /api/reels/{reel}/comments` | **Développée** | `ReelCommentController` opérationnel. |
| **RL** | Réponses & Réactions commentaires | Sous-commentaires et likes | `GET /comments/{id}/replies`, `PUT /reaction` | **Développée** | `ReelCommentController` opérationnel. |
| **RL** | Page d'un Son musical | Détail du son et Reels liés | `GET /api/reels/sounds/{id}` & `/{id}/reels` | **Développée** | `SoundController` opérationnel. |
| **RL** | Favoris sur les pistes audio | Mettre une musique en favori | `POST /api/reels/sounds/{id}/favourite` | **Développée** | `SoundController@toggleFavourite` opérationnel. |
| **RL** | Verrouiller un son original | Interdire la réutilisation par des tiers | `PUT /api/reels/{id}/original-sound/lock` | **Développée** | `SoundController@lockOriginal` opérationnel. |

---

### 6. Module CR — Création de Contenu (Studio)

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **CR1** | Téléverser média Square | Image/vidéo pour carrousel | `POST /api/square-cr1/media/upload` | **Développée** | `MediaUploadController@upload` opérationnel. |
| **CR1** | Recherche de personnes à taguer | Identifier des contacts sur photo | `GET /api/square-cr1/search/people` | **Développée** | `PeopleSearchController@index` opérationnel. |
| **CR1** | Recherche de lieux | Géolocaliser un post | `GET /api/square-cr1/search/places` | **Développée** | `PlaceSearchController@index` opérationnel. |
| **CR1** | Hashtags tendances | Liste des tags populaires | `GET /api/square-cr1/search/hashtags/trending` | **Développée** | `HashtagSearchController@trending` opérationnel. |
| **CR1** | Publication Square complète | Enregistrer post multi-médias | `POST /api/square-cr1/posts` | **Développée** | `PostController@store` opérationnel. |
| **CR2** | Brouillons de Reel | Sauvegarder composition, timeline | `POST` & `GET /api/reels/drafts` | **Développée** | `StudioReelController` gère la composition JSON et la reprise. |
| **CR2** | Téléverser média Reel | Clip vidéo / audio / cover | `POST /api/reels/media/upload` | **Développée** | `StudioReelController@uploadMedia` opérationnel. |
| **CR2** | Sonothèque pour Reel | Explorer les musiques | `GET /api/reels/sounds` | **Développée** | `SoundController@index` opérationnel. |
| **CR2** | Publier un Reel | Transcodage asynchrone | `POST /api/reels/publish` | **Développée** | `StudioReelController@publish` envoie le job de transcodage. |
| **CR3** | Publier une Story | Médias, stickers, sondages, audience | `POST /api/stories` | **Développée** | `StoryController@store` opérationnel. |
| **CR3** | Rail de Stories | Stories actives des abonnements | `GET /api/stories/rail` | **Développée** | `StoryController@rail` opérationnel. |
| **CR3** | Mes Stories & Statistiques | Consultation vues, votes, réponses | `GET /api/stories/mine/{id}` | **Développée** | `StoryController@myStory` opérationnel. |
| **CR3** | Liste des spectateurs | Qui a vu ma Story (privé) | `GET /api/stories/{id}/viewers` | **Développée** | `StoryController@viewers` opérationnel. |
| **CR3** | Vote sur sondage Story | Enregistrer son vote | `POST /api/stories/{slideId}/poll/{stickerId}/vote` | **Développée** | `StoryController@pollVote` opérationnel. |
| **CR3** | Réponse privée à une Story | Répondre en chat privé | `POST /api/stories/{id}/reply` | **Développée** | Enregistre l'interaction dans `story_replies` avec snapshot immuable et événement. |
| **CR3** | Gérer sa liste choisie | Amis proches pour Stories | `GET` & `PUT /api/stories/close-list` | **Développée** | `CloseListController` opérationnel. |
| **CR3** | Collections À la une | Highlights permanents sur profil | `POST`, `GET`, `DELETE /api/highlights/*` | **Développée** | `HighlightController` opérationnel. |
| **CR4** | Brouillon d'annonce immobilière | Sauvegarder la saisie guidée | *Aucune route dédiée* | **Absente** | Aucun endpoint de brouillon pour annonces immo (`saveListingForm`). |
| **CR4** | Téléversement vidéo guidée | Contrôle des métriques vidéo | *Aucune route dédiée* | **Absente** | Aucun endpoint pour réception et analyse de vidéo immobilière. |
| **CR4** | Téléversement photos par pièces | 20 photos avec qualification de pièce | *Aucune route dédiée* | **Absente** | Aucun endpoint pour photos immobilières avec pièces (`living`, `kitchen`, etc.). |
| **CR4** | Publication de l'annonce | Décomposition prix/charges et modération | *Aucune route dédiée* | **Absente** | Aucun endpoint de création d'annonce (`createListing`). |

---

### 7. Module IM — L'Immo (Consultation & Réservation)

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **IM** | Feed vertical des annonces | Liste d'annonces avec vidéos | *Aucune route dédiée* | **Absente** | Aucune table `listings` ni route `GET /api/listings`. |
| **IM** | Fiche détaillée d'un bien | Caractéristiques, photos, vidéo, charges | *Aucune route dédiée* | **Absente** | Aucune route de consultation d'annonce. |
| **IM** | Carte des biens | Recherche par emprise géographique (`bounds`) | *Aucune route dédiée* | **Absente** | Le frontend utilise des tuiles locales, aucune API backend de géolocalisation de biens. |
| **IM** | Filtres & Comptage dynamique | Connaître le nombre de résultats | *Aucune route dédiée* | **Absente** | Le frontend interroge `countMatching`, inexistant côté backend. |
| **IM** | Créneaux de visite du bien | Obtenir le calendrier de disponibilité | *Aucune route dédiée* | **Absente** | Aucune table de créneaux ni route `listVisitDays`. |
| **IM** | Demande de réservation de visite | Poser une option temporaire et payer | *Aucune route dédiée* | **Absente** | Aucune route `requestVisit` pour réserver un créneau de visite. |
| **IM** | Confirmation et reçu de visite | Confirmer après paiement Mobile Money | *Aucune route dédiée* | **Absente** | Aucune route `confirmVisit`. |
| **IM** | Suivi de mes visites | Historique de ses rendez-vous | *Aucune route dédiée* | **Absente** | Aucune route pour consulter ses visites passées/à venir. |
| **IM** | Favoris immobiliers | Sauvegarder des annonces | *Aucune route dédiée* | **Absente** | Aucune table ni endpoint pour favoris d'annonces. |
| **IM** | Alertes de recherche | Créer une alerte sur critères | *Aucune route dédiée* | **Partiellement développée** | `HousingSearch` mémorise des préférences utilisateur, mais il n'existe pas de moteur d'alertes d'annonces ni de notification de biens correspondants. |
| **IM** | Signaler « Déjà loué » | Signalement prospect d'un bien indisponible | *Aucune route dédiée* | **Absente** | Aucune route backend. |

---

### 8. Module BL — Espace Bailleur

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **BL** | Souscription au Badge Bailleur | Payer le badge d'autorité bailleur | `POST /api/subscriptions/subscribe` | **Développée** | Le catalogue contient le produit badge bailleur et le flux de paiement fonctionne. |
| **BL** | Dossier KYC Bailleur | Envoi des titres de propriété et CNI | *Aucune route dédiée* | **Absente** | Aucun endpoint de soumission de documents KYC de propriété. |
| **BL** | Liste de mes biens | Tableau de bord de ses logements | *Aucune route dédiée* | **Absente** | Aucune route `listProperties` pour le bailleur connecté. |
| **BL** | Modification du statut du bien | Marquer `published`, `reserved`, `taken` | *Aucune route dédiée* | **Absente** | Aucune route `changeStatus` ou `markTaken`. |
| **BL** | Configuration des créneaux | Définir ses plages de visite disponibles | *Aucune route dédiée* | **Absente** | Aucune route de gestion d'agenda de visite. |
| **BL** | Traitement des demandes de visite | Valider ou refuser les visites de prospects | *Aucune route dédiée* | **Absente** | Aucune route de gestion des demandes de visite. |
| **BL** | Statistiques analytiques d'un bien | Vues vidéo, contacts, favoris | *Aucune route dédiée* | **Absente** | Aucune route d'analytique immobilière. |
| **BL** | Gestion des accès et délégations | Inviter et révoquer des agents sur un bien | *Aucune route dédiée* | **Absente** | Aucune route de gestion de délégation. |

---

### 9. Module AG — Espace Agent Immobilier

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **AG** | Souscription au Badge Agent | Payer la licence/badge d'agent | `POST /api/subscriptions/subscribe` | **Développée** | Disponible via le catalogue d'abonnements. |
| **AG** | Portefeuille de mandats | Consulter les biens délégués par les bailleurs | *Aucune route dédiée* | **Absente** | Aucune table de mandats ni route `listDelegatedProperties`. |
| **AG** | Réception d'invitation de bailleur | Examiner, accepter ou décliner une mission | *Aucune route dédiée* | **Absente** | Aucune route d'invitation d'agent. |
| **AG** | Annuaire des agents | Recherche d'agents qualifiés par quartier | *Aucune route dédiée* | **Absente** | Aucune route de recherche spécialisée dans les agents immobiliers. |
| **AG** | Suivi des prospects | Liste des contacts affectés aux biens | *Aucune route dédiée* | **Absente** | Aucun module de gestion de prospects/leads. |

---

### 10. Module BO — Boost & Publicité

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **BO** | Liste des contenus boostables | Lister ses posts et reels éligibles | *Aucune route dédiée* | **Partiellement développée** | Les posts et reels peuvent être listés, mais aucun endpoint `listBoostable` calculant l'éligibilité et le score de performance n'existe. |
| **BO** | Estimation de portée | Calculer la portée prévisionnelle | *Aucune route dédiée* | **Absente** | Le frontend appelle `catalog.estimateReach` (formule locale) ; aucun endpoint serveur d'estimation d'inventaire publicitaire. |
| **BO** | Création de campagne sponsorisée | Paramétrer ciblage, budget, durée | *Aucune route dédiée* | **Absente** | Aucune table `campaigns` ni route de lancement de campagne publicitaire. |
| **BO** | Règlement de la campagne | Payer la campagne via Mobile Money | `POST /api/payments/initiate` | **Partiellement développée** | Le module de paiement générique peut initier un paiement, mais l'objet rattaché "campagne" n'existe pas en base. |
| **BO** | Suivi des métriques de diffusion | Impressions, clics, profil des spectateurs | *Aucune route dédiée* | **Absente** | Aucune table ni endpoint de métriques publicitaires. |
| **BO** | Solde et Crédit BANGO | Utiliser son crédit de compensation | *Aucune route dédiée* | **Absente** | Aucun portefeuille de crédits virtuels dans le backend. |

---

### 11. Module P / AB — Abonnements & Badges de Confiance

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **P / AB** | Catalogue des formules | Tarifs par pays et par cadence | `GET /api/subscriptions/plans` & `/api/catalog` | **Développée** | `CatalogController` renvoie les produits et tarifs. |
| **P / AB** | Statut d'abonnement et badges | Vérifier l'abonnement actif et la grâce | `GET /api/subscriptions/status` | **Développée** | `SubscriptionController@status` renvoie le statut, la date d'échéance et le badge. |
| **P / AB** | Souscrire un abonnement | Choix du plan et initiation paiement | `POST /api/subscriptions/subscribe` | **Développée** | `SubscriptionController@subscribe` crée l'abonnement et lie la transaction. |
| **P / AB** | Détail d'un abonnement | Consulter le contrat et historique | `GET /api/subscriptions/{id}` | **Développée** | `SubscriptionController@show` opérationnel. |
| **P / AB** | Résiliation | Arrêter le renouvellement automatique | `POST /api/subscriptions/{id}/cancel` | **Développée** | `SubscriptionController@cancel` annule la reconduction. |
| **P / AB** | Changement de plan | Migrer avec calcul de prorata | `POST /api/subscriptions/{id}/change-plan` | **Développée** | `SubscriptionController@changePlan` recalcule le tarif. |
| **P / AB** | Éligibilité au renouvellement | Vérifier si renouvelable par anticipation | `GET /api/subscriptions/{id}/renewal` | **Développée** | `SubscriptionRenewalController@renewalInfo` opérationnel. |
| **P / AB** | Devis certifié de renouvellement | Devis avec tarif garanti | `POST /api/subscriptions/{id}/renewal/quote` | **Développée** | `SubscriptionRenewalController@quote` opérationnel. |
| **P / AB** | Confirmation du renouvellement | Confirmer et prolonger la validité | `POST /api/subscriptions/{id}/renewal/confirm` | **Développée** | `SubscriptionRenewalController@confirm` opérationnel. |
| **P / AB** | Historique des badges | Journal des attributions/expirations | `GET /api/badges/history` | **Développée** | `SubscriptionController@history` consulte `badge_histories`. |

---

### 12. Module PG — Pages Professionnelles

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **PG** | Création d'une Page Pro | Créer une entité entreprise | *Aucune route dédiée* | **Absente** | Aucune table `pages` ni contrôleur dans le backend. |
| **PG** | Gestion de mes Pages | Lister ses pages administrées | *Aucune route dédiée* | **Absente** | Aucune route backend. |
| **PG** | Rôles et membres de la page | Assigner Propriétaire, Éditeur, Modérateur | *Aucune route dédiée* | **Absente** | Aucune gestion de membres de page. |
| **PG** | Dossier de certification | Envoi RCCM, fiscal et paiement d'examen | *Aucune route dédiée* | **Absente** | Aucun système de vérification d'entreprise. |
| **PG** | Messagerie de la Page | Boîte de réception cloisonnée | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |

---

### 13. Module MS — Messagerie Privée

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MS** | Liste des conversations | Fils de chat paginés avec aperçu | *Aucune route dédiée* | **Absente** | Aucune table de conversations ni contrôleur de messagerie. |
| **MS** | Détail d'un fil de discussion | Historique complet des messages | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Envoi de message texte | Écrire un message avec idempotence | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Envoi de message vocal | Téléverser et lire une note vocale | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Contexte d'annonce immobilière | Rattacher un bien et créneau au fil | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Conversations de groupe | Créer groupe, ajouter membres, admins | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Demandes de messages | Filtrer les premiers contacts inconnus | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **MS** | Accusés de lecture & Saisie | Signaux de lecture et "en train d'écrire" | *Aucune route dédiée* | **Absente** | Aucun système WebSocket ou pusher de messagerie. |
| **MS** | Pont Story -> Messagerie | Transformer une réponse Story en message | `POST /api/stories/{id}/reply` | **Partiellement développée** | CR3 Story stocke l'intention dans `story_replies` avec `future_conversation_id`, mais le module Messagerie cible n'existe pas pour consommer cet événement. |

---

### 14. Module RC — Recherche & Découverte

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **RC** | Recherche de personnes / comptes | Trouver des utilisateurs | `GET /api/square-cr1/search/people` | **Développée** | `PeopleSearchController@index` opérationnel. |
| **RC** | Recherche de hashtags | Explorer les mots-clés | `GET /api/square-cr1/search/hashtags/trending` | **Partiellement développée** | La route renvoie les hashtags tendances, mais pas de recherche plein texte sur tous les hashtags. |
| **RC** | Recherche de publications | Recherche textuelle dans le Square | *Aucune route dédiée* | **Absente** | Aucune route de recherche globale dans les légendes de posts. |
| **RC** | Recherche de biens immobiliers | Trouver des logements avec boost | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **RC** | Recherche d'agents immobiliers | Annuaire d'agents de quartier | *Aucune route dédiée* | **Absente** | Inexistant côté backend. |
| **RC** | Tendances locales | Top hashtags et musiques | `GET /api/square-cr1/search/hashtags/trending` & `/api/reels/sounds` | **Partiellement développée** | Les routes existent séparément, mais pas d'endpoint agrégé de tendances (`RC-11`). |

---

### 15. Module PR — Profil Utilisateur

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **PR** | Mon Profil | Données de son compte et compteurs | `GET /api/profile` | **Développée** | `ProfileController@show` opérationnel. |
| **PR** | Édition du profil | Modifier nom, bio, date de naissance, genre | `PUT /api/profile/*` (`identity`, `bio`, `gender`, `birthdate`, `location`) | **Développée** | Ensemble d'endpoints granulaires opérationnels. |
| **PR** | Mise à jour de l'avatar | Téléverser sa photo de profil | `PUT /api/profile/avatar` | **Développée** | `AvatarController` opérationnel. |
| **PR** | Profil d'un tiers | Consulter un profil public | `GET /api/profile/public/{identifier}` | **Développée** | `ProfileController@showPublic` gère le masquage et la confidentialité. |
| **PR** | Abonnés et Abonnements | Listes paginées des relations | `GET /api/profile/followers` & `/following` | **Développée** | `FollowController` opérationnel. |
| **PR** | Suivre / Ne plus suivre | Gérer ses abonnements | `POST` & `DELETE /api/profile/{id}/follow` | **Développée** | `FollowController` gère comptes publics et demandes sur comptes privés. |
| **PR** | Demandes d'abonnement reçues | Accepter / refuser les demandes privées | `GET /api/profile/follow-requests`, `/accept`, `/reject` | **Développée** | `FollowRequestController` opérationnel. |
| **PR** | Bloquer / Débloquer | Liste noire et blocage mutuel | `POST` & `DELETE /api/profile/{id}/block` | **Développée** | `BlockController` opérationnel. |
| **PR** | Partage de QR Code | Générer lien et résoudre QR Code | `MATCH /api/qr/resolve` | **Développée** | `QrController@resolve` opérationnel. |
| **PR** | Mes enregistrements | Lister ses favoris sauvegardés | *Aucune route agrégée* | **Partiellement développée** | `GET /api/reels/user/saved` existe pour les reels, mais aucun endpoint pour les posts Square sauvegardés ni les annonces. |

---

### 16. Module ST — Paramètres & Sécurité

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **ST** | Changer d'adresse e-mail | Procédure en 2 temps (code nouvelle adresse, alerte ancienne) | `POST /api/settings/email/request` & `/verify` | **Développée** | `EmailController` applique strictement la règle de sécurité. |
| **ST** | Modifier son mot de passe | Changement authentifié | `PUT /api/settings/password` | **Développée** | `PasswordController` opérationnel avec contrôle des 3 anciens mots de passe. |
| **ST** | Double Authentification (2FA) | Activer 2FA SMS ou TOTP | *Partiellement couverte* | **Partiellement développée** | La validation du 2FA à la connexion existe (`SecondFactorController`), mais aucun endpoint d'activation TOTP ni de génération de QR code secret partagé (`security.enableTwoFactor`). |
| **ST** | Codes de secours 2FA | Générer et régénérer 8 codes à usage unique | *Aucune route dédiée* | **Absente** | Aucun endpoint de gestion des codes de secours (`security.regenerateBackupCodes`). |
| **ST** | Sessions actives d'appareils | Lister terminaux, OS, IP, ville | `GET /api/settings/sessions` | **Développée** | `SecurityController@getSessions` opérationnel. |
| **ST** | Déconnexion à distance | Révocation ciblée d'un terminal | `DELETE /api/settings/sessions/{id}` | **Développée** | `SecurityController@revokeSession` opérationnel. |
| **ST** | Urgence « Sécuriser le compte » | Déconnecter tous les autres appareils et forcer nouveau mot de passe | `POST /api/settings/secure` | **Développée** | `SecurityController@secureAccount` opérationnel. |
| **ST** | Réglages de confidentialité | Options de visibilité globale | `GET` & `PUT /api/settings/privacy` | **Développée** | `PrivacyController` opérationnel. |
| **ST** | Pause du compte | Suspendre temporairement son compte | `POST /api/settings/account/pause` | **Développée** | `AccountController@pause` opérationnel. |
| **ST** | Suppression du compte | Suppression avec rétractation 30j | `DELETE /api/settings/account` | **Développée** | `AccountController@delete` opérationnel. |

---

### 17. Module PAY — Paiements Mobile Money

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **PAY** | Catalogue des prestations payantes | Montants et devises par pays | `GET /api/payments/catalog` | **Développée** | `PaymentController@catalog` opérationnel. |
| **PAY** | Devis certifié serveur | Établir un devis horodaté | `POST /api/payments/quote` | **Développée** | `PaymentController@quote` génère un devis certifié. |
| **PAY** | Initiation USSD Push Mobile Money | Déclencher le paiement sur le téléphone | `POST /api/payments/initiate` | **Développée** | `PaymentController@initiate` avec clé d'idempotence et dialogue Monetbil. |
| **PAY** | Polling statut de paiement | Vérifier en direct l'état de la transaction | `GET /api/payments/{id}/status` | **Développée** | `PaymentController@status` opérationnel. |
| **PAY** | Annulation transaction en attente | Annuler avant confirmation USSD | `POST /api/payments/{id}/cancel` | **Développée** | `PaymentController@cancel` opérationnel. |
| **PAY** | Historique des paiements | Relevé des transactions | `GET /api/payments/history` | **Développée** | `PaymentController@history` opérationnel. |
| **PAY** | Reçu vérifiable en ligne | Télécharger un PDF certifié | *Aucune route dédiée* | **Partiellement développée** | La référence et les données existent dans `PaymentTransaction`, mais aucun endpoint ne génère l'URL publique ni le PDF certifié (`payments.receipt`). |
| **PAY** | Moyens de paiement enregistrés | Mémoriser son numéro de téléphone | *Aucune route dédiée* | **Absente** | Aucun endpoint de gestion de numéros favoris (`PAY-18` à `PAY-20`). |
| **PAY** | Déclaration double débit | Réclamer un débit injustifié | *Route admin uniquement* | **Partiellement développée** | Le back-office dispose de `POST /api/admin/payments/double-charge-claims`, mais il n'existe pas d'endpoint public/client pour que l'utilisateur dépose lui-même sa réclamation. |

---

### 18. Module SUP — Support & Modération

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SUP** | Centre d'aide & FAQ | Lister et rechercher des articles | *Aucune route dédiée* | **Absente** | Le frontend embarque ses articles localement (`lib/help/fr.ts`). Aucun CMS d'assistance dans le backend. |
| **SUP** | Tickets de support | Créer, lister et suivre ses tickets | *Aucune route dédiée* | **Absente** | Aucun module de billetterie/tickets client (`SUP-04` à `SUP-09`). |
| **SUP** | Signalement de contenu | Signaler un post, annonce ou usager | `POST /api/stories/{id}/report` | **Partiellement développée** | Seul le signalement de Story existe. Les signalements de posts Square, de reels, d'annonces immobilières et de profils n'ont aucun endpoint. |
| **SUP** | Suivi de mes signalements | Connaître le verdict de la modération | *Aucune route dédiée* | **Absente** | Aucun endpoint pour consulter la liste de ses signalements passés (`SUP-13`). |
| **SUP** | Formulaire de Recours (Appels) | Contester une décision de sanction | *Aucune route dédiée* | **Absente** | Aucun endpoint pour déposer un recours contre une suspension ou un avertissement (`SUP-22`, `app/appeal.tsx`). |

---

### 19. Module SY — États Système & Résilience

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SY** | Vidage file hors-ligne | Synchroniser les actions différées | *Aucune route dédiée* | **Absente** | Le frontend attend un endpoint de batching idempotent `sync.flush({ actions })`. Les actions doivent être appelées une par une actuellement. |
| **SY** | Reprise d'upload par morceaux | Protocole Tus (HEAD / PATCH offset) | *Aucune route dédiée* | **Absente** | Le backend n'accepte que des uploads multipart complets (`POST /api/square-cr1/media/upload`, `/reels/media/upload`). Aucune reprise d'offset. |
| **SY** | Numéro d'incident serveur 5xx | Générer une référence d'erreur corrélée | *Aucune route dédiée* | **Absente** | Les exceptions 500 renvoient le format JSON Laravel standard, sans référence d'incident traçable (`SY-06`). |

---

### 20. Module EDGE — Cas Limites Transverses

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **EDGE** | Résolution de liens universels | Résoudre un lien court partagé `bango.app/r/...` | *Aucune route dédiée* | **Partiellement développée** | `POST /api/qr/resolve` existe pour les profils, mais aucun résolveur universel pour les publications ou annonces disparues (`content.resolveLink`). |
| **EDGE** | État d'un contenu supprimé/masqué | Savoir si un contenu tiers est suspendu | *Aucune route dédiée* | **Absente** | Les API renvoient 404, ne permettant pas de distinguer "supprimé par l'auteur", "gelé par modération" ou "compte banni". |

---

### 21. Module NOTIF — Notifications

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Écarts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **NOTIF** | Liste des notifications | Flux paginé des alertes de l'utilisateur | *Aucune route dédiée* | **Absente** | La table `notifications` existe en base de données, mais aucune route `GET /api/notifications` n'est déclarée dans `routes/api.php`. |
| **NOTIF** | Acquittement de lecture | Marquer une ou toutes les alertes comme lues | *Aucune route dédiée* | **Absente** | Aucune route de mise à jour d'état de notification. |
| **NOTIF** | Enregistrement Push Token | Sauvegarder le token APNs/FCM | *Aucune route dédiée* | **Absente** | Aucun endpoint `POST /api/notifications/device-token`. |
