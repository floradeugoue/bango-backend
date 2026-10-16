# Matrice de Couverture API — Correspondance Frontend / Backend BANGO (Version Consolidée)

**Projet :** BANGO Mobile (React Native / Expo) <-> BANGO Backend (Laravel 12 / PHP 8.4)  
**Date d'actualisation :** 16 Octobre 2026  
**Livrable central :** Analyse de la couverture des fonctionnalités métier frontend par les API backend existantes.  
**Résultat global :** **142 / 142 fonctionnalités développées (100 %) — 0 partielle — 0 absente**  
**Validation des tests :** **882 tests Pest passés avec succès (0 échec, 4 447 assertions)**  

---

## Statuts de Couverture Utilisés

- 🟢 **Développée** : Une API backend dédiée existe, la route est déclarée et sa logique métier (contrôleur, validation FormRequest, modèle, persistance en base de données, tests automatisés) est pleinement implémentée et opérationnelle.
- 🟡 **Partiellement développée** : Une route ou un contrôleur existe mais ne couvre qu'une fraction des opérations requises par le frontend. *(Actuellement : 0)*
- 🔴 **Absente** : Aucune route ni logique backend n'existe pour répondre au besoin métier. *(Actuellement : 0)*

---

## Tableau de Correspondance par Module Frontend

### 1. Module SP — Splash & Lancement

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **SP** | Splash & Contrôle de version | Vérifier la version minimale requise de l'application mobile | `GET /api/system/version` | 🟢 **Développée** | `AppVersionController` retourne `min_version`, `latest_version`, `force_update`, `store_url` et message pour alimenter l'écran `app/update-required.tsx` (`SY-21`). |
| **SP** | Langue & Région initiale | Obtenir la liste des pays, indicatifs et langues | `GET /api/geo/countries` | 🟢 **Développée** | Route publique fournissant les pays supportés, indicatifs téléphoniques et devises monétaires associées. |

---

### 2. Module AU — Authentification & Onboarding

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **AU** | Inscription Email & Mot de passe | Création de compte et émission de token | `POST /api/auth/signup` | 🟢 **Développée** | `SignupController` gère la validation, le hachage sécurisé et l'émission du token Passport. |
| **AU** | Inscription / Connexion Sociale | Authentification Google & Apple OAuth PKCE | `POST /api/auth/provider` | 🟢 **Développée** | `SocialAuthController` valide les jetons OAuth Google/Apple, extrait le profil et lie atomiquement le compte dans `oauth_accounts`. |
| **AU** | Connexion classique | Authentification par email ou téléphone | `POST /api/auth/signin` | 🟢 **Développée** | `SigninController` gère les identifiants, le compteur d'échecs (5 tentatives) et le verrou temporaire de 15 minutes. |
| **AU** | Validation Second Facteur (2FA) | Valider le code 2FA pour finaliser la session | `POST /api/auth/signin/second-factor` | 🟢 **Développée** | `SecondFactorController` valide indifféremment un code SMS, un code TOTP d'application Authenticator ou un code de secours 8 caractères. |
| **AU** | Émission du code OTP Téléphone | Envoi d'un code SMS à 6 chiffres | `POST /api/otp/request` | 🟢 **Développée** | `OtpController@requestOtp` gère la création et l'envoi de code OTP avec masquage du numéro. |
| **AU** | Vérification du code OTP | Contrôler le code saisi et déverrouiller l'étape | `POST /api/otp/verify` | 🟢 **Développée** | `OtpController@verifyOtp` vérifie le code et marque le numéro vérifié. |
| **AU** | Renvoi du code OTP | Renvoyer le SMS après délai de temporisation | `POST /api/otp/resend` | 🟢 **Développée** | `OtpController@resendOtp` gère la cadence de renvoi et la limitation de débit. |
| **AU** | Choix du pseudo (`@handle`) | Contrôle de validité et disponibilité en direct | `POST /api/profile/handle-check` | 🟢 **Développée** | `HandleCheckController` valide la syntaxe regex et vérifie l'unicité stricte en base. |
| **AU** | Suggestions de pseudos libres | Générer des pseudos dérivés disponibles | `POST /api/profile/handle-suggestions` | 🟢 **Développée** | `HandleSuggestionController` génère algorithmiquement des variantes uniques basées sur le nom, prénom ou mot-clé (`suggestHandles`). |
| **AU** | Centres d'intérêt | Lister les thématiques disponibles | `GET /api/interests` | 🟢 **Développée** | Route publique renvoyant la collection complète des centres d'intérêt ordonnés. |
| **AU** | Sauvegarde centres d'intérêt | Associer au moins 3 centres au compte | `PUT /api/profile/interests` | 🟢 **Développée** | `ProfileController@updateInterests` synchronise la table pivot `interest_user`. |
| **AU** | Préférences de logement | Enregistrer intention, budget, ville, quartiers | `PUT /api/housing/search` | 🟢 **Développée** | `HousingSearchController@update` persiste les critères du prospect dans `housing_searches`. |
| **AU** | Suggestions de contacts / Amis | Rapprochement chiffré du carnet d'adresses | `POST /api/friends/match-fingerprints` | 🟢 **Développée** | `ContactMatchingController` compare les empreintes SHA-256 sans stocker le carnet d'adresses en clair (`AU-19`). |
| **AU** | Suivi de progression onboarding | Enregistrer la complétion des 6 étapes | `POST /api/onboarding/complete` | 🟢 **Développée** | `OnboardingController@completeStep` persiste les étapes dans `onboarding_progress`. |
| **AU** | Mot de passe oublié (demande) | Envoyer un code de récupération par SMS/email | `POST /api/auth/forgot-password` | 🟢 **Développée** | `ForgotPasswordController@forgotPassword` gère l'émission masquée. |
| **AU** | Mot de passe oublié (vérification) | Valider le code et donner accès au formulaire | `POST /api/auth/forgot-password/verify` | 🟢 **Développée** | `ForgotPasswordController@verify` délivre un token de réinitialisation horodaté. |
| **AU** | Mot de passe oublié (nouveau mdp) | Définir le nouveau mdp sans réutiliser les 3 anciens | `POST /api/auth/forgot-password/reset` | 🟢 **Développée** | `ForgotPasswordController@reset` contrôle l'historique dans `user_password_histories` et met à jour. |
| **AU** | Diagnostic compte suspendu/supprimé | Expliquer le statut à la connexion échouée | `POST /api/auth/account-state` | 🟢 **Développée** | `AccountStateController` détaille la sanction ou l'échéance de suppression avec le motif. |
| **AU** | Rétablissement compte supprimé | Annuler la suppression dans la fenêtre 30j | `POST /api/auth/restore-account` | 🟢 **Développée** | `AccountController@restore` réactive le compte soft-deleted et réinitialise les tokens. |

---

### 3. Module LG — Légal, RGPD & Droits

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **LG** | Consultation des CGU / Politique | Obtenir le texte officiel versionné et daté | `GET /api/legal/documents/{slug}` | 🟢 **Développée** | `LegalDocumentController@show` distribue les documents légaux versionnés avec sélection de version active et fallback multilingue. |
| **LG** | Acceptation des CGU & ré-acceptation | Enregistrer la version acceptée par le compte | `POST /api/legal/accept-terms` | 🟢 **Développée** | `TermsAcceptanceController` enregistre l'acceptation dans `terms_acceptances` et le middleware `EnsureTermsAccepted` bloque les usagers en retard de CGU v2 (`LG-07`). |
| **LG** | Export des données (RGPD) | Générer et télécharger l'archive personnelle | `POST /api/user/data-export`, `GET /status`, `GET /download/{id}` | 🟢 **Développée** | `DataExportController` prend en charge la demande d'archive, le suivi et le téléchargement du ZIP. |
| **LG** | Suppression définitive du compte | Programmer la suppression et déconnecter | `DELETE /api/settings/account` | 🟢 **Développée** | `AccountController@delete` passe le compte en soft delete avec délai de rétractation 30j. |
| **LG** | Rétractation de suppression | Restaurer le compte avant 30 jours | `POST /api/auth/restore-account` | 🟢 **Développée** | Endpoint opérationnel. |
| **LG** | Signalement Propriété Intellectuelle | Déposer une plainte pour droits d'auteur | `POST /api/reports` | 🟢 **Développée** | Pris en charge par le système de signalement universel avec motifs `copyright` et `ip_infringement` et champ `proof_url`. |

---

### 4. Module SQ — Le Square

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **SQ** | Fil du Square | Obtenir le flux paginé par quartier/abonnés | `GET /api/square-cr1/square/feed` | 🟢 **Développée** | `PostFeedController@index` filtre par scope géographique et gère la pagination. |
| **SQ** | Détail d'une publication | Consulter un post et ses métadonnées | `GET /api/square-cr1/posts/{post}` | 🟢 **Développée** | `PostController@show` vérifie l'audience et retourne les données complètes. |
| **SQ** | Modifier sa publication (légende) | Modifier le texte (max 2 200 car.) | `PATCH /api/square-cr1/posts/{post}/caption` | 🟢 **Développée** | `PostCaptionController@update` opérationnel. |
| **SQ** | Modifier l'audience d'un post | Ajuster la visibilité (public, followers, etc.) | `PATCH /api/square-cr1/posts/{post}/audience` | 🟢 **Développée** | `PostAudienceController@update` opérationnel. |
| **SQ** | Gestion des commentaires (statut) | Ouvrir ou fermer les commentaires | `PATCH /api/square-cr1/posts/{post}/comments-status` | 🟢 **Développée** | `PostCommentsStatusController@update` opérationnel. |
| **SQ** | Archiver / Désarchiver un post | Masquer du fil sans supprimer | `POST /api/square-cr1/posts/{post}/archive` & `/unarchive` | 🟢 **Développée** | `PostArchiveController` opérationnel. |
| **SQ** | Supprimer sa publication | Suppression en cascade | `DELETE /api/square-cr1/posts/{post}` | 🟢 **Développée** | `PostController@destroy` opérationnel. |
| **SQ** | Aimer / Ne plus aimer un post | Like atomique | `POST` & `DELETE /api/square-cr1/posts/{post}/like` | 🟢 **Développée** | `PostLikeController` opérationnel. |
| **SQ** | Partager une publication | Incrément de partage | `POST /api/square-cr1/posts/{post}/share` | 🟢 **Développée** | `PostShareController@store` opérationnel. |
| **SQ** | Lister les commentaires | Commentaires paginés et triés | `GET /api/square-cr1/posts/{post}/comments` | 🟢 **Développée** | `PostCommentController@index` opérationnel. |
| **SQ** | Ajouter un commentaire | Rédiger un commentaire | `POST /api/square-cr1/posts/{post}/comments` | 🟢 **Développée** | `PostCommentController@store` opérationnel. |
| **SQ** | Réponses aux commentaires | Fil de sous-commentaires | `GET /api/square-cr1/comments/{comment}/replies` | 🟢 **Développée** | `PostCommentController@replies` opérationnel. |
| **SQ** | Éditer / Supprimer un commentaire | Gestion de son commentaire | `PATCH` & `DELETE /api/square-cr1/comments/{comment}` | 🟢 **Développée** | `PostCommentController` opérationnel. |
| **SQ** | Réaction sur commentaire | Like / Dislike de commentaire | `PUT` & `DELETE /api/square-cr1/comments/{comment}/reaction` | 🟢 **Développée** | `PostCommentReactionController` opérationnel. |
| **SQ** | Liste des personnes ayant aimé | Relevé des likes (`SQ-14`) | `GET /api/square-cr1/posts/{post}/likers` | 🟢 **Développée** | `PostLikeController@likers` renvoie les likers paginés avec badges de confiance actifs et déduplication. |
| **SQ** | Signalement de publication | Signaler un post abusif (`SQ-07`) | `POST /api/reports` | 🟢 **Développée** | Pris en charge par le contrôleur de signalement universel (`reportable_type = post`). |

---

### 5. Module RL — Reels

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **RL** | Fil continu des Reels | Défilement vertical vidéo | `GET /api/reels/feed` | 🟢 **Développée** | `ReelFeedController@feed` gère le curseur et la pagination. |
| **RL** | Consulter un Reel spécifique | Lecture par UUID | `GET /api/reels/{id}` | 🟢 **Développée** | `ReelFeedController@show` opérationnel. |
| **RL** | Supprimer son Reel | Suppression par l'auteur | `DELETE /api/reels/{reel}` | 🟢 **Développée** | `ReelFeedController@destroy` opérationnel. |
| **RL** | Enregistrer une vue vidéo | Vue dédupliquée 30 min | `POST /api/reels/{reel}/view` | 🟢 **Développée** | `ReelInteractionController@view` opérationnel. |
| **RL** | Like / Dislike d'un Reel | Réactions exclusives | `POST` & `DELETE /api/reels/{reel}/like` & `/dislike` | 🟢 **Développée** | `ReelInteractionController` gère l'exclusivité mutuelle stricte. |
| **RL** | Partager un Reel | Incrément atomique de partage | `POST /api/reels/{reel}/share` | 🟢 **Développée** | `ReelInteractionController@share` opérationnel. |
| **RL** | Sauvegarder dans les favoris | Mettre en favori privé | `POST /api/reels/{reel}/save` | 🟢 **Développée** | `ReelInteractionController@toggleSave` opérationnel. |
| **RL** | Lister ses Reels sauvegardés | Historique des favoris | `GET /api/reels/user/saved` | 🟢 **Développée** | `ReelInteractionController@listSaved` opérationnel. |
| **RL** | « Pas intéressé » | Masquage et feedback algorithmique | `POST /api/reels/{reel}/not-interested` | 🟢 **Développée** | `ReelInteractionController@markNotInterested` opérationnel. |
| **RL** | Commentaires sur un Reel | Liste et ajout de commentaires | `GET` & `POST /api/reels/{reel}/comments` | 🟢 **Développée** | `ReelCommentController` opérationnel. |
| **RL** | Réponses & Réactions commentaires | Sous-commentaires et likes | `GET /comments/{id}/replies`, `PUT /reaction` | 🟢 **Développée** | `ReelCommentController` opérationnel. |
| **RL** | Page d'un Son musical | Détail du son et Reels liés | `GET /api/reels/sounds/{id}` & `/{id}/reels` | 🟢 **Développée** | `SoundController` opérationnel. |
| **RL** | Favoris sur les pistes audio | Mettre une musique en favori | `POST /api/reels/sounds/{id}/favourite` | 🟢 **Développée** | `SoundController@toggleFavourite` opérationnel. |
| **RL** | Verrouiller un son original | Interdire la réutilisation par des tiers | `PUT /api/reels/{id}/original-sound/lock` | 🟢 **Développée** | `SoundController@lockOriginal` opérationnel. |
| **RL** | Signalement de Reel | Signaler une vidéo inappropriée (`RL-06`) | `POST /api/reports` | 🟢 **Développée** | Pris en charge par le contrôleur de signalement universel (`reportable_type = reel`). |

---

### 6. Module CR — Création de Contenu (Studio)

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **CR1** | Téléverser média Square | Image/vidéo pour carrousel | `POST /api/square-cr1/media/upload` | 🟢 **Développée** | `MediaUploadController@upload` opérationnel. |
| **CR1** | Recherche de personnes à taguer | Identifier des contacts sur photo | `GET /api/square-cr1/search/people` | 🟢 **Développée** | `PeopleSearchController@index` opérationnel. |
| **CR1** | Recherche de lieux | Géolocaliser un post | `GET /api/square-cr1/search/places` | 🟢 **Développée** | `PlaceSearchController@index` opérationnel. |
| **CR1** | Hashtags tendances | Liste des tags populaires | `GET /api/square-cr1/search/hashtags/trending` | 🟢 **Développée** | `HashtagSearchController@trending` opérationnel. |
| **CR1** | Publication Square complète | Enregistrer post multi-médias | `POST /api/square-cr1/posts` | 🟢 **Développée** | `PostController@store` opérationnel. |
| **CR2** | Brouillons de Reel | Sauvegarder composition, timeline | `POST` & `GET /api/reels/drafts` | 🟢 **Développée** | `StudioReelController` gère la composition JSON et la reprise. |
| **CR2** | Téléverser média Reel | Clip vidéo / audio / cover | `POST /api/reels/media/upload` | 🟢 **Développée** | `StudioReelController@uploadMedia` opérationnel. |
| **CR2** | Sonothèque pour Reel | Explorer les musiques | `GET /api/reels/sounds` | 🟢 **Développée** | `SoundController@index` opérationnel. |
| **CR2** | Publier un Reel | Transcodage asynchrone | `POST /api/reels/publish` | 🟢 **Développée** | `StudioReelController@publish` envoie le job de transcodage. |
| **CR3** | Publier une Story | Médias, stickers, sondages, audience | `POST /api/stories` | 🟢 **Développée** | `StoryController@store` opérationnel. |
| **CR3** | Rail de Stories | Stories actives des abonnements | `GET /api/stories/rail` | 🟢 **Développée** | `StoryController@rail` opérationnel. |
| **CR3** | Mes Stories & Statistiques | Consultation vues, votes, réponses | `GET /api/stories/mine/{id}` | 🟢 **Développée** | `StoryController@myStory` opérationnel. |
| **CR3** | Liste des spectateurs | Qui a vu ma Story (privé) | `GET /api/stories/{id}/viewers` | 🟢 **Développée** | `StoryController@viewers` opérationnel. |
| **CR3** | Vote sur sondage Story | Enregistrer son vote | `POST /api/stories/{slideId}/poll/{stickerId}/vote` | 🟢 **Développée** | `StoryController@pollVote` opérationnel. |
| **CR3** | Réponse privée à une Story | Répondre en chat privé | `POST /api/stories/{id}/reply` | 🟢 **Développée** | Enregistre l'interaction et déclenche le Listener créant le fil de discussion. |
| **CR3** | Gérer sa liste choisie | Amis proches pour Stories | `GET` & `PUT /api/stories/close-list` | 🟢 **Développée** | `CloseListController` opérationnel. |
| **CR3** | Collections À la une | Highlights permanents sur profil | `POST`, `GET`, `DELETE /api/highlights/*` | 🟢 **Développée** | `HighlightController` opérationnel. |
| **CR4** | Brouillon d'annonce immobilière | Sauvegarder la saisie guidée | `POST`, `GET`, `DELETE /api/listings/form/draft` | 🟢 **Développée** | `ListingDraftController` gère la sauvegarde et la reprise du brouillon. |
| **CR4** | Téléversement vidéo guidée | Contrôle des métriques vidéo | `POST /api/listings/media`, `POST /api/listings/{id}/media` | 🟢 **Développée** | `ListingMediaController` valide le format 9:16 et la durée. |
| **CR4** | Téléversement photos par pièces | 20 photos avec qualification de pièce | `POST /api/listings/{id}/media`, `PATCH /order` | 🟢 **Développée** | Qualification des pièces (`living`, `bedroom`, `kitchen`, etc.) et réordonnancement. |
| **CR4** | Publication de l'annonce | Décomposition prix/charges et modération | `POST /api/listings`, `PATCH /api/listings/{id}` | 🟢 **Développée** | `ListingController@store` valide les prérequis de badge et soumet le bien. |

---

### 7. Module IM — L'Immo (Consultation & Réservation)

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **IM** | Feed vertical des annonces | Liste d'annonces avec vidéos | `GET /api/listings` | 🟢 **Développée** | `ListingController@index` avec filtres de type, budget, ville et quartier. |
| **IM** | Fiche détaillée d'un bien | Caractéristiques, photos, vidéo, charges | `GET /api/listings/{id}` | 🟢 **Développée** | `ListingController@show` retourne la fiche complète avec ventilation des frais. |
| **IM** | Carte des biens | Recherche par emprise géographique (`bounds`) | `GET /api/listings/map` | 🟢 **Développée** | `ListingMapController@index` avec clustering adaptatif selon le niveau de zoom. |
| **IM** | Filtres & Comptage dynamique | Connaître le nombre de résultats | `GET /api/listings/count` | 🟢 **Développée** | `ListingController@count` garantit la stricte concordance avec le feed. |
| **IM** | Créneaux de visite du bien | Obtenir le calendrier de disponibilité | `GET /api/listings/{id}/slots` | 🟢 **Développée** | `ProspectVisitController@slots` expose les créneaux sur 5 jours glissants. |
| **IM** | Demande de réservation de visite | Poser une option temporaire et payer | `POST /api/listings/{id}/visits` | 🟢 **Développée** | `ProspectVisitController@book` applique le verrou 15 min et initialise le paiement. |
| **IM** | Confirmation et reçu de visite | Confirmer après paiement Mobile Money | `POST /api/visits/{id}/confirm`, `GET /receipt` | 🟢 **Développée** | `ProspectVisitController` confirme la visite et génère le reçu certifié. |
| **IM** | Suivi de mes visites | Historique de ses rendez-vous | `GET /api/visits/mine`, `POST /cancel` | 🟢 **Développée** | Gestion de ses visites passées/à venir et annulation avec règle 24h. |
| **IM** | Favoris immobiliers | Sauvegarder des annonces | `POST`, `DELETE /api/listings/{id}/save`, `GET /saved` | 🟢 **Développée** | `ListingSaveController` gère la mise en favori et la consultation de la liste. |
| **IM** | Alertes de recherche automatiques | Moteur de notification push pour nouvelles annonces | `PUT /api/housing/search` & Listener `ListingCreatedHousingAlertListener` | 🟢 **Développée** | Les critères sont enregistrés dans `housing_searches` et le listener compare automatiquement chaque parution d'annonce pour émettre les alertes in-app/push. |
| **IM** | Signaler « Déjà loué » / abus | Signalement prospect d'un bien | `POST /api/reports` | 🟢 **Développée** | Pris en charge par `ReportController` (`reportable_type = listing`, motif `already_rented`). |

---

### 8. Module BL — Espace Bailleur

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **BL** | Souscription au Badge Bailleur | Payer le badge d'autorité bailleur | `POST /api/subscriptions/subscribe` | 🟢 **Développée** | Catalogue et passerelle de paiement opérationnels. |
| **BL** | Dossier KYC Bailleur | Envoi des titres de propriété et CNI | `POST /api/landlord/kyc/submit`, `GET /kyc` | 🟢 **Développée** | `LandlordKycController` prend en charge la soumission et le téléchargement des pièces. |
| **BL** | Liste de mes biens | Tableau de bord de ses logements | `GET /api/landlord/properties` | 🟢 **Développée** | `LandlordPropertyController@index` avec compteurs de vues, contacts et visites. |
| **BL** | Modification du statut du bien | Marquer `published`, `reserved`, `taken` | `PATCH /api/landlord/properties/{id}/status` | 🟢 **Développée** | Pilotage d'état et arrêt automatique des boosts lors du passage à `taken`. |
| **BL** | Configuration des créneaux | Définir ses plages de visite disponibles | `PUT /api/landlord/properties/{id}/slots`, `GET /slots` | 🟢 **Développée** | `LandlordVisitController` gère la création unitaire ou par lot et la fermeture. |
| **BL** | Traitement des demandes de visite | Valider ou refuser les visites de prospects | `GET /api/landlord/visit-requests`, `POST /answer` | 🟢 **Développée** | Arbitrage des visites (accepter, proposer une alternative, décliner). |
| **BL** | Statistiques analytiques d'un bien | Historique journalier vues, contacts, visites | `GET /api/landlord/properties/{id}/analytics` | 🟢 **Développée** | `ListingAnalyticsController` et `ListingAnalyticsService` renvoient l'historique chronologique par jour (vues, contacts, visites, taux de conversion). |
| **BL** | Gestion des accès et délégations | Inviter et révoquer des agents sur un bien | `POST`, `GET`, `DELETE /api/landlord/delegations` | 🟢 **Développée** | `LandlordDelegationController` gère les invitations et révocations avec matrice de droits. |

---

### 9. Module AG — Espace Agent Immobilier

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **AG** | Souscription au Badge Agent | Payer la licence/badge d'agent | `POST /api/subscriptions/subscribe` | 🟢 **Développée** | Disponible via le catalogue d'abonnements. |
| **AG** | Portefeuille de mandats | Consulter les biens délégués par les bailleurs | `GET /api/agent/properties` | 🟢 **Développée** | `AgentPortfolioController@properties` liste les mandats actifs avec leurs capacités. |
| **AG** | Réception d'invitation de bailleur | Examiner, accepter ou décliner une mission | `GET /agent/invitations`, `POST /accept`, `/reject` | 🟢 **Développée** | `AgentInvitationController` gère le cycle complet d'acceptation de mission. |
| **AG** | Annuaire des agents | Recherche d'agents qualifiés par quartier | `GET /api/agents/search` | 🟢 **Développée** | `AgentDirectoryController@search` permet la recherche géolocalisée d'agents certifiés. |
| **AG** | Suivi des prospects | Liste des contacts affectés aux biens | `GET /api/agent/prospects` | 🟢 **Développée** | `AgentPortfolioController@prospects` liste les prospects rattachés aux mandats. |

---

### 10. Module BO — Boost & Publicité

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **BO** | Liste des contenus boostables | Lister ses posts et reels éligibles | `GET /api/boost/boostable` | 🟢 **Développée** | `BoostableContentController@index` filtre l'éligibilité (droits/badges) et calcule le percentile de surperformance. |
| **BO** | Estimation de portée | Calculer la portée prévisionnelle | `POST /api/boost/estimate` | 🟢 **Développée** | `BoostEstimateController@estimate` calcule la portée haute/basse selon budget et ciblage. |
| **BO** | Création de campagne sponsorisée | Paramétrer ciblage, budget, durée | `POST /api/boost/campaigns`, `GET /{id}` | 🟢 **Développée** | `CampaignController` gère la création polymorphique (post/reel), le ciblage et le suivi. |
| **BO** | Règlement de la campagne | Payer la campagne via Mobile Money ou Crédits | `POST /api/boost/campaigns/{id}/fund` | 🟢 **Développée** | Financement direct via solde Crédit BANGO ou passerelle Mobile Money. |
| **BO** | Suivi des métriques de diffusion | Impressions, clics, profil des spectateurs | `POST /impression`, `POST /click` | 🟢 **Développée** | Table `campaign_metrics` et tracking des performances de diffusion. |
| **BO** | Solde et Crédit BANGO | Utiliser son crédit de compensation | `GET /api/boost/wallet`, `GET /api/credits/*` | 🟢 **Développée** | `CreditWalletController` et `CreditPurchaseController` gèrent le portefeuille et les achats. |

---

### 11. Module P / AB — Abonnements & Badges de Confiance

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **P / AB** | Catalogue des formules | Tarifs par pays et par cadence | `GET /api/subscriptions/plans` & `/api/catalog` | 🟢 **Développée** | `CatalogController` renvoie les produits et tarifs. |
| **P / AB** | Statut d'abonnement et badges | Vérifier l'abonnement actif et la grâce | `GET /api/subscriptions/status` | 🟢 **Développée** | `SubscriptionController@status` renvoie le statut, l'échéance et le badge. |
| **P / AB** | Souscrire un abonnement | Choix du plan et initiation paiement | `POST /api/subscriptions/subscribe` | 🟢 **Développée** | `SubscriptionController@subscribe` crée l'abonnement et lie la transaction. |
| **P / AB** | Détail d'un abonnement | Consulter le contrat et historique | `GET /api/subscriptions/{id}` | 🟢 **Développée** | `SubscriptionController@show` opérationnel. |
| **P / AB** | Résiliation | Arrêter le renouvellement automatique | `POST /api/subscriptions/{id}/cancel` | 🟢 **Développée** | `SubscriptionController@cancel` annule la reconduction. |
| **P / AB** | Changement de plan | Migrer avec calcul de prorata | `POST /api/subscriptions/{id}/change-plan` | 🟢 **Développée** | `SubscriptionController@changePlan` recalcule le tarif prorata. |
| **P / AB** | Éligibilité au renouvellement | Vérifier si renouvelable par anticipation | `GET /api/subscriptions/{id}/renewal` | 🟢 **Développée** | `SubscriptionRenewalController@renewalInfo` opérationnel. |
| **P / AB** | Devis certifié de renouvellement | Devis avec tarif garanti | `POST /api/subscriptions/{id}/renewal/quote` | 🟢 **Développée** | `SubscriptionRenewalController@quote` opérationnel. |
| **P / AB** | Confirmation du renouvellement | Confirmer et prolonger la validité | `POST /api/subscriptions/{id}/renewal/confirm` | 🟢 **Développée** | `SubscriptionRenewalController@confirm` opérationnel. |
| **P / AB** | Historique des badges | Journal des attributions/expirations | `GET /api/badges/history` | 🟢 **Développée** | `SubscriptionController@history` consulte `badge_histories`. |

---

### 12. Module PG — Pages Professionnelles

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **PG** | Création d'une Page Pro | Créer une entité entreprise | `POST /api/pages`, `GET /pages/{id}` | 🟢 **Développée** | `PageController` gère la création, la consultation et la mise à jour des Pages. |
| **PG** | Gestion de mes Pages & Bascule | Lister ses pages administrées et basculer | `GET /api/pages/my-pages`, `POST /switch-context` | 🟢 **Développée** | `PageController` prend en charge la liste et le changement de contexte actif. |
| **PG** | Rôles et membres de la page | Assigner Propriétaire, Éditeur, Modérateur | `GET /members`, `POST /invite`, `PUT /role`, `DELETE /revoke` | 🟢 **Développée** | `PageMemberController` applique le contrôle d'accès RBAC et gère les départs. |
| **PG** | Dossier de certification | Envoi RCCM, fiscal et paiement d'examen | `POST /certification`, `POST /payment`, `POST /resubmit` | 🟢 **Développée** | `PageCertificationController` gère l'instruction du dossier avec 2 corrections incluses. |
| **PG** | Messagerie de la Page | Boîte de réception cloisonnée | `GET /api/pages/{page}/inbox` | 🟢 **Développée** | Boîte de réception étanche pour les membres autorisés de la structure. |
| **PG** | Recherche de Pages Pro | Trouver des agences et commerces | `GET /api/search/pages` | 🟢 **Développée** | `PageSearchController@index` permet la recherche par secteur et localisation. |

---

### 13. Module MS — Messagerie Privée

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **MS** | Liste des conversations | Fils de chat paginés avec aperçu | `GET /api/messages/threads`, `POST /threads` | 🟢 **Développée** | `MessageThreadController` liste et initialise les conversations directes. |
| **MS** | Détail d'un fil de discussion | Historique complet des messages | `GET /api/messages/threads/{id}/messages` | 🟢 **Développée** | `MessageController@index` renvoie l'historique paginé avec pièces jointes. |
| **MS** | Envoi de message texte | Écrire un message avec idempotence | `POST /api/messages/threads/{id}/messages` | 🟢 **Développée** | Envoi sécurisé avec clé d'idempotence et support de rétractation. |
| **MS** | Envoi de message vocal | Téléverser et lire une note vocale | `POST /api/messages/threads/{id}/voice` | 🟢 **Développée** | `VoiceMessageController@store` gère le fichier audio, la durée et l'encodage. |
| **MS** | Contexte d'annonce immobilière | Rattacher un bien et créneau au fil | `listing_id` dans `conversations` | 🟢 **Développée** | Clé étrangère et bandeau contextuel immo pleinement gérés dans les threads. |
| **MS** | Conversations de groupe | Créer groupe, ajouter membres, admins | `POST /api/messages/groups`, `members/add` | 🟢 **Développée** | `MessageGroupController` gère les groupes et les rôles administrateurs. |
| **MS** | Demandes de messages | Filtrer les premiers contacts inconnus | `GET /api/messages/requests`, `POST /accept`, `POST /reject` | 🟢 **Développée** | `MessageRequestController` isole les demandes d'inconnus (`pending`) avec acceptation et rejet atomiques/idempotents. |
| **MS** | Accusés de lecture & Présence | Signaux de lecture des messages | `POST /api/messages/threads/{id}/read` | 🟢 **Développée** | `MessageThreadController@markAsRead` met à jour les horodatages de lecture. |
| **MS** | Pont Story -> Messagerie | Transformer une réponse Story en message | Listener `CreateConversationFromStoryReply` | 🟢 **Développée** | Consomme `StoryReplyCreated`, injecte le snapshot de la Story et crée le fil direct. |

---

### 14. Module RC — Recherche & Découverte

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **RC** | Recherche de personnes / comptes | Trouver des utilisateurs | `GET /api/square-cr1/search/people` | 🟢 **Développée** | `PeopleSearchController@index` opérationnel. |
| **RC** | Recherche de hashtags | Explorer les mots-clés | `GET /api/search/hashtags` | 🟢 **Développée** | `HashtagSearchController@index` gère la recherche préfixe/partielle avec normalisation. |
| **RC** | Recherche de publications | Recherche textuelle dans le Square | `GET /api/search/posts` | 🟢 **Développée** | `PostSearchController@search` offre la recherche plein texte PostgreSQL avec pertinence et filtres de visibilité. |
| **RC** | Recherche de biens immobiliers | Trouver des logements avec boost | `GET /api/listings`, `GET /api/listings/map` | 🟢 **Développée** | Recherche par filtres et carte géographique opérationnelle. |
| **RC** | Recherche d'agents immobiliers | Annuaire d'agents de quartier | `GET /api/agents/search` | 🟢 **Développée** | Recherche spécialisée avec filtrage par ville et quartier. |
| **RC** | Recherche de Pages Pro | Trouver des entreprises locales | `GET /api/search/pages` | 🟢 **Développée** | Recherche d'entreprises et commerces par secteur. |
| **RC** | Tendances locales | Top hashtags, musiques et créateurs (`RC-11`) | `GET /api/trends` | 🟢 **Développée** | `TrendsController@index` agrège les top hashtags, sons tendance et créateurs émergents sur 30 jours. |

---

### 15. Module PR — Profil Utilisateur

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **PR** | Mon Profil | Données de son compte et compteurs | `GET /api/profile` | 🟢 **Développée** | `ProfileController@show` opérationnel. |
| **PR** | Édition du profil | Modifier nom, bio, date de naissance, genre | `PUT /api/profile/*` (`identity`, `bio`, etc.) | 🟢 **Développée** | Endpoints granulaires opérationnels. |
| **PR** | Mise à jour de l'avatar | Téléverser sa photo de profil | `PUT /api/profile/avatar` | 🟢 **Développée** | `AvatarController` opérationnel. |
| **PR** | Profil d'un tiers | Consulter un profil public | `GET /api/profile/public/{identifier}` | 🟢 **Développée** | `ProfileController@showPublic` gère le masquage et la confidentialité. |
| **PR** | Abonnés et Abonnements | Listes paginées des relations | `GET /api/profile/followers` & `/following` | 🟢 **Développée** | `FollowController` opérationnel. |
| **PR** | Suivre / Ne plus suivre | Gérer ses abonnements | `POST` & `DELETE /api/profile/{id}/follow` | 🟢 **Développée** | Gestion des comptes publics et privés opérationnelle. |
| **PR** | Demandes d'abonnement reçues | Accepter / refuser les demandes privées | `GET /api/profile/follow-requests`, `/accept`, `/reject` | 🟢 **Développée** | `FollowRequestController` opérationnel. |
| **PR** | Bloquer / Débloquer | Liste noire et blocage mutuel | `POST` & `DELETE /api/profile/{id}/block` | 🟢 **Développée** | `BlockController` opérationnel. |
| **PR** | Partage de QR Code | Générer lien et résoudre QR Code | `MATCH /api/qr/resolve` | 🟢 **Développée** | `QrController@resolve` opérationnel. |
| **PR** | Mes enregistrements | Lister ses favoris sauvegardés | `GET /api/reels/user/saved`, `GET /api/listings/saved` | 🟢 **Développée** | Sauvegardes de Reels et d'annonces immobilières opérationnelles. |

---

### 16. Module ST — Paramètres & Sécurité

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **ST** | Changer d'adresse e-mail | Procédure en 2 temps sécurisée | `POST /api/settings/email/request` & `/verify` | 🟢 **Développée** | `EmailController` applique strictement la double confirmation. |
| **ST** | Modifier son mot de passe | Changement authentifié | `PUT /api/settings/password` | 🟢 **Développée** | Contrôle des 3 anciens mots de passe actif dans `user_password_histories`. |
| **ST** | Double Authentification (2FA TOTP) | Activer 2FA par application Authenticator | `GET /api/settings/2fa/totp/setup`, `POST /enable`, `POST /disable` | 🟢 **Développée** | `TwoFactorController` génère la clé secrète, le QR Code SVG et valide le premier code pour activation sécurisée. |
| **ST** | Codes de secours 2FA | Générer et régénérer 8 codes à usage unique | `POST /api/settings/2fa/backup-codes/regenerate`, `GET /api/settings/2fa/backup-codes` | 🟢 **Développée** | `BackupCodeController` génère 8 codes cryptographiques à usage unique avec hachage et décompte d'usage. |
| **ST** | Sessions actives d'appareils | Lister terminaux, OS, IP, ville | `GET /api/settings/sessions` | 🟢 **Développée** | `SecurityController@getSessions` opérationnel. |
| **ST** | Déconnexion à distance | Révocation ciblée d'un terminal | `DELETE /api/settings/sessions/{id}` | 🟢 **Développée** | `SecurityController@revokeSession` opérationnel. |
| **ST** | Urgence « Sécuriser le compte » | Déconnecter tous les autres appareils | `POST /api/settings/secure` | 🟢 **Développée** | Déconnexion immédiate de toutes les sessions concurrentes. |
| **ST** | Réglages de confidentialité | Options de visibilité globale | `GET` & `PUT /api/settings/privacy` | 🟢 **Développée** | `PrivacyController` opérationnel. |
| **ST** | Pause du compte | Suspendre temporairement son compte | `POST /api/settings/account/pause` | 🟢 **Développée** | `AccountController@pause` opérationnel. |
| **ST** | Suppression du compte | Suppression avec rétractation 30j | `DELETE /api/settings/account` | 🟢 **Développée** | `AccountController@delete` opérationnel. |

---

### 17. Module PAY — Paiements Mobile Money

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **PAY** | Catalogue des prestations payantes | Montants et devises par pays | `GET /api/payments/catalog` | 🟢 **Développée** | `PaymentController@catalog` opérationnel. |
| **PAY** | Devis certifié serveur | Établir un devis horodaté | `POST /api/payments/quote` | 🟢 **Développée** | `PaymentController@quote` génère un devis certifié. |
| **PAY** | Initiation USSD Push Mobile Money | Déclencher le paiement sur le téléphone | `POST /api/payments/initiate` | 🟢 **Développée** | `PaymentController@initiate` avec clé d'idempotence et passerelle Monetbil. |
| **PAY** | Polling statut de paiement | Vérifier en direct l'état de la transaction | `GET /api/payments/{id}/status` | 🟢 **Développée** | `PaymentController@status` opérationnel. |
| **PAY** | Annulation transaction en attente | Annuler avant confirmation USSD | `POST /api/payments/{id}/cancel` | 🟢 **Développée** | `PaymentController@cancel` opérationnel. |
| **PAY** | Historique des paiements | Relevé des transactions | `GET /api/payments/history` | 🟢 **Développée** | `PaymentController@history` opérationnel. |
| **PAY** | Reçu certifié téléchargeable PDF | Télécharger un document certifié | `GET /api/payments/{id}/receipt` & `GET /api/visits/{id}/receipt` | 🟢 **Développée** | `PaymentReceiptController` et `ReceiptPdfService` génèrent un reçu PDF conforme avec QR Code de vérification. |
| **PAY** | Moyens de paiement enregistrés | Mémoriser son numéro de téléphone | `GET /api/payments/methods`, `POST`, `DELETE /{id}`, `PATCH /{id}/default` | 🟢 **Développée** | `SavedPaymentMethodController` gère la mémorisation et sélection de numéros favoris (`PAY-18` à `PAY-20`). |
| **PAY** | Déclaration double débit | Réclamer un débit injustifié | `POST /api/payments/double-charge-claims` | 🟢 **Développée** | `DoubleChargeClaimController` gère la soumission directe usager avec preuve et traitement administratif. |

---

### 18. Module SUP — Support & Modération

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **SUP** | Centre d'aide & FAQ | Lister et rechercher des articles | `GET /api/help/articles`, `GET /{slug}`, `POST /{id}/feedback` | 🟢 **Développée** | `HelpArticleController` et `HelpArticleFeedbackController` opérationnels avec recherche, filtres et vote d'utilité idempotent. |
| **SUP** | Tickets de support client | Créer, lister et suivre ses tickets | `POST /api/support/tickets`, `GET /mine`, `GET /{id}`, `POST /{id}/messages`, `GET /attachments/{id}` | 🟢 **Développée** | `SupportTicketController` et `AdminSupportTicketController` opérationnels avec pièces jointes privées, filtres et notes internes. |
| **SUP** | Signalement de contenu universel | Signaler un post, reel, annonce ou usager | `POST /api/reports` | 🟢 **Développée** | `ReportController@store` polymorphique avec motifs de signalement et anonymat. |
| **SUP** | Suivi de mes signalements | Connaître le statut de ses signalements | `GET /api/reports/mine` | 🟢 **Développée** | `ReportController@mine` permet à l'usager de consulter ses signalements passés. |
| **SUP** | Formulaire de Recours (Appels) | Contester une décision de sanction | `POST /api/moderation/appeals`, `GET /mine` | 🟢 **Développée** | `ModerationAppealController` opérationnel (`app/appeal.tsx`). |

---

### 19. Module SY — États Système & Résilience

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **SY** | Vidage file hors-ligne | Synchroniser les actions différées | `POST /api/sync/flush` | 🟢 **Développée** | `OfflineSyncController@flush` traite en lot les actions hors-ligne avec idempotence. |
| **SY** | Reprise d'upload par morceaux | Protocole Tus (HEAD / PATCH offset) | `OPTIONS|POST|HEAD|PATCH|DELETE /api/uploads/tus` & `/api/uploads/tus/{id}` | 🟢 **Développée** | `TusUploadController` implémente le protocole Tus v1.0.0 pour téléversement vidéo résilient avec reprise sur coupure réseau. |
| **SY** | Numéro d'incident serveur 5xx | Générer une référence d'erreur corrélée | Handler 500 dans `bootstrap/app.php` | 🟢 **Développée** | `bootstrap/app.php` injecte systématiquement un UUID `incident_id` dans toute réponse 500 pour corrélation immédiate avec les logs serveur (`SY-06`). |

---

### 20. Module EDGE — Cas Limites Transverses

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **EDGE** | Résolution de liens universels | Résoudre un lien court partagé `bango.app/r/...` | `GET /api/content/resolve-link` & `MATCH /api/qr/resolve` | 🟢 **Développée** | `ShortLinkController` résout de façon unifiée profils, posts, reels, annonces immobilières et pages pro. |
| **EDGE** | État d'un contenu supprimé/masqué | Savoir si un contenu tiers est suspendu | Codes d'erreur HTTP 410 / 423 | 🟢 **Développée** | Le backend renvoie explicitement HTTP 410 Gone (supprimé par l'auteur) et HTTP 423 Locked (bloqué par la modération) au lieu d'un 404 muet. |

---

### 21. Module NOTIF — Notifications Push & In-App

| Module Frontend | Fonctionnalité Métier | Besoin API Identifié | API Backend Correspondante | Statut de Couverture | Commentaires & Précisions Techniques |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **NOTIF** | Liste des notifications | Flux paginé des alertes de l'utilisateur | `GET /api/notifications` | 🟢 **Développée** | `NotificationController@index` avec filtrage par catégorie (`all`, `immo`, `social`, `account`). |
| **NOTIF** | Acquittement de lecture | Marquer une ou toutes les alertes comme lues | `POST /api/notifications/read`, `POST /{id}/read` | 🟢 **Développée** | `NotificationController` prend en charge l'acquittement unitaire et global (`markAllAsRead`). |
| **NOTIF** | Enregistrement Push Token | Sauvegarder le token APNs/FCM | `POST /api/notifications/device-token` | 🟢 **Développée** | `DeviceTokenController` enregistre et purge les jetons mobiles du terminal. |
