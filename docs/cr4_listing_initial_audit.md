# Audit Initial — Module CR4 (Cœur Immobilier) & Module IM (Consultation & Recherche)

## 1. Contexte & Objectif
Le présent document formalise l'audit préliminaire fonctionnel et technique pour le développement du module **CR4 — Cœur Immobilier** et des API de consultation/recherche du module **IM** de l'application BANGO.

L'objectif est d'implémenter un socle immobilier robuste, transactionnel, sécurisé, hautement performant et fidèle aux spécifications et maquettes de l'application mobile React Native / Expo, tout en restant strictement cantonné au backend Laravel (PHP 8.4, Laravel 13, Pest 5).

---

## 2. Analyse de l'Existant (Backend BANGO)

### 2.1. Éléments Déjà Disponibles & Réutilisables
* **Authentification & Session** : Laravel Passport (`auth:api`), tokens Bearer, relations utilisateurs, protection contre les blocages réciproques (`user_blocks`) et les suspensions.
* **Système de Badges & Abonnements** :
  * Modèle `User` avec colonne `badge` (`none`, `landlord`, `agent`, `double`).
  * `BadgeResolutionService` résolvant les badges selon les souscriptions actives ou en période de grâce et la validation KYC (`is_verified`).
  * `Subscription` et `SubscriptionProduct` (types : `landlord`, `agent`).
* **Gestion du Stockage & Médias** :
  * `App\Services\Media\MediaStorageService` : gestionnaire centralisé gérant disques (`public`, `r2`, `s3`), dossiers structurés (`paths`), nettoyage et contrôle MIME.
  * `config/media.php` extensible pour les dossiers `listings_images`, `listings_videos`, `listings_temp`.
* **Référentiel Géographique** :
  * Modèles `City`, `Neighbourhood`, `Country`, `Currency`.
  * Support multi-pays (Sénégal, Cameroun, etc.) avec devises normalisées (XOF, XAF, EUR).
* **Intégrations Connexes** :
  * Modèle `Reel` disposant déjà d'une colonne `listing_id` (UUID nullable) dans `reels` pour associer un Reel de présentation à une annonce.

### 2.2. Lacunes Identifiées (À Développer)
* **Aucun modèle d'annonce** : Absence de la table `listings`, du modèle `Listing` et de ses enums associés.
* **Aucun modèle de média immobilier** : Absence de `ListingMedia` et de la table `listing_media`.
* **Aucune persistance des brouillons d'annonces** : Ni pour le formulaire par étapes (`POST /api/listings/form/draft`), ni pour la reprise de brouillon (`GET /api/listings/form/draft`).
* **Absence des routes de consultation et recherche IM** :
  * Feed immobilier vertical (`GET /api/listings`).
  * Fiche détaillée (`GET /api/listings/{id}`).
  * Recherche géographique & clustering (`GET /api/listings/map`).
  * Compteur dynamique synchronisé (`GET /api/listings/count`).
  * Favoris / Sauvegardes (`POST /api/listings/{id}/save`, `DELETE /api/listings/{id}/save`, `GET /api/listings/saved`).

---

## 3. Analyse Fonctionnelle & Contrats Frontend (Bango React Native)

### 3.1. Formulaire d'Annonce (CR-39 à CR-48)
* **Étapes du formulaire** (`ListingFormStep`) : `video`, `photos`, `features`, `price`, `place`, `preview`.
* **Champs requis pour publication** :
  * Vidéo guidée : obligatoire, portrait (hauteur > largeur), durée entre 30s et 180s (3 min).
  * Photos : entre 1 et 20 photos, avec qualification de pièce optionnelle (`living`, `bedroom`, `kitchen`, `shower`, `outside`).
  * Titre : max 70 caractères.
  * Description : max 4000 caractères.
  * Mode de contrat : `rent` (location), `sale` (vente), `flatshare` (colocation).
  * Type de bien (`kind`) : `studio`, `flat`, `villa`, `room`, `office`, `land`.
  * Caractéristiques physiques : `bedrooms` (0 pour studio, entier 0..100), `showers` (entier 0..100), `area` (m² > 0), `amenities` (liste d'IDs : `furnished`, `meter`, `borehole`, `parking`, etc.).
  * Informations financières :
    * `price` (montant nu, obligatoire).
    * `currency` (devise ISO ex: XOF, XAF).
    * `period` ('month' en location/colocation, null en vente).
    * `deposit_months` (caution en nombre de mois, 0..36).
    * `advance_months` (avance en nombre de mois, 0..36).
    * `visit_fee` (frais de réservation de visite, >= 0).
    * `charges` (charges d'immeuble mensuelles, >= 0).
    * `negotiable` (booléen).
    * `utilities_included` (booléen).
  * Localisation :
    * `city` (ville obligatoire).
    * `neighbourhood` (quartier obligatoire).
    * `landmark` (repère public indicatif, max 80 caractères, ex: "près du carrefour Ngor" — jamais l'adresse privée).
    * `lat` / `lng` (coordonnées GPS du repère ou du centre du quartier, validées).
* **Calcul des frais d'entrée (`moveInCosts`)** :
  * Vente (`sale`) : Montant d'achat (`price`), Frais de visite (`visitFee`), Total d'entrée = `price + visitFee`.
  * Location / Colocation (`rent` / `flatshare`) :
    * Caution (`deposit`) = `price * deposit_months`.
    * Avance (`advance`) = `price * advance_months`.
    * Frais de visite (`visit`) = `visit_fee`.
    * Charges mensuelles récurrentes (`charges`) = `charges` (marquées `perMonth: true`, exclues du total d'entrée).
    * Total d'entrée initial (`upfront`) = `(price * (deposit_months + advance_months)) + visit_fee`.
    * Loyer mensuel total (`monthly`) = `price + charges`.

### 3.2. Cycle de Vie et Statuts d'une Annonce
* `draft` : Brouillon privé en cours de rédaction, non visible publiquement.
* `review` : Soumis à vérification humaine/modération (CR-48) comparant la vidéo aux informations déclarées. Non visible dans le feed public.
* `published` : Validé et en ligne, diffusable dans le feed public et la carte.
* `reserved` : En ligne avec bandeau réservé (n'accepte plus de visites payantes).
* `taken` : Bien loué ou vendu (retiré du feed public actif, consultable via lien direct ou favoris avec statut `taken`).

### 3.3. Contrôle des Badges & Permissions (Règle Métier)
* **Brouillon** : Tout utilisateur authentifié peut enregistrer et reprendre ses brouillons (`owner_id = auth->id`).
* **Publication (`review` / `published`)** :
  * L'utilisateur doit posséder le rôle `admin` OU un badge certifié actif : `landlord`, `agent`, ou `double` (via `BadgeResolutionService`).
  * Les comptes suspendus (`suspended_from` / `locked_until`) ou avec vérification KYC échue (`verification_lapsed`) ne peuvent pas publier.
  * Un agent ne peut agir que sur les biens de son portefeuille de mandats ou ses propres annonces.

---

## 4. Décisions Techniques d'Architecture

1. **Identifiants UUID** : Utilisation d'identifiants UUID standardisés pour les tables `listings` et `listing_media`.
2. **Stockage Médias via `MediaStorageService`** :
   * Ajout de chemins configurables dans `config/media.php` : `listings_images`, `listings_videos`, `listings_temp`.
   * Méthodes d'upload transactionnelles, validation MIME, génération d'ordre et attribution de pièces (`photoRooms`).
3. **Formulaire par étapes & Brouillon persistant** :
   * Support de `submission_key` pour garantir l'idempotence et la reprise transparente du brouillon.
   * `POST /api/listings/form/draft` permet de sauvegarder un état même partiel.
4. **Recherche Géographique & Clustering** :
   * `GET /api/listings/map` accepte une bounding box (`north`, `south`, `east`, `west`) et un niveau de `zoom`.
   * Algorithme de clustering basé sur une grille adaptative selon le zoom pour regrouper les annonces proches sans surcharger le client.
5. **Gestion des Favoris (Idempotence & Intégrité)** :
   * Table `listing_saves` avec clé unique composite `(user_id, listing_id)`.
   * Endpoints `POST /api/listings/{id}/save` et `DELETE /api/listings/{id}/save` ou toggle.
   * Masquage automatique des annonces non accessibles ou privées dans la liste des favoris.
6. **Préparation des Espaces Bailleur, Agent, Reels & Messagerie** :
   * Rattachement clair `owner_id` (propriétaire légitime) et `agent_id` (gestionnaire délégué).
   * Relation `listing()` sur le modèle `Reel`.
   * Contrat documenté pour la citation d'une annonce dans un message sans duplication de données.

---

## 5. Fichiers Prévus à Créer / Modifier

### Modèles & Enums
* `app/Enums/Listing/ListingMode.php` (`rent`, `sale`, `flatshare`)
* `app/Enums/Listing/ListingStatus.php` (`draft`, `review`, `published`, `reserved`, `taken`, `rejected`)
* `app/Enums/Listing/ListingKind.php` (`studio`, `flat`, `villa`, `room`, `office`, `land`)
* `app/Enums/Listing/ListingPhotoRoom.php` (`living`, `bedroom`, `kitchen`, `shower`, `outside`)
* `app/Models/Listing/Listing.php`
* `app/Models/Listing/ListingMedia.php`
* `app/Models/Listing/ListingSave.php`
* `app/Models/Listing/ListingDraft.php` (ou gestion intégrée dans `Listing`)

### Migrations
* `database/migrations/2026_10_15_210000_create_listings_tables.php` (création de `listings`, `listing_media`, `listing_saves`)

### Services & Policies
* `app/Services/Listing/ListingPricingService.php` (calculs financiers et ventilation des frais)
* `app/Services/Listing/ListingSearchService.php` (recherche filtrée, géographique, clustering, compteurs)
* `app/Services/Listing/ListingService.php` (logique métier de création, mise à jour, brouillons, soumission)
* `app/Policies/ListingPolicy.php` (contrôle des droits, badges, propriété et délégation)

### Contrôleurs & Form Requests
* `app/Http/Controllers/Listing/ListingController.php` (feed, show, store, update, destroy, publication)
* `app/Http/Controllers/Listing/ListingDraftController.php` (brouillons)
* `app/Http/Controllers/Listing/ListingMapController.php` (recherche géographique & clustering)
* `app/Http/Controllers/Listing/ListingSaveController.php` (favoris)
* `app/Http/Requests/Listing/StoreListingRequest.php`
* `app/Http/Requests/Listing/UpdateListingRequest.php`
* `app/Http/Requests/Listing/SaveListingDraftRequest.php`
* `app/Http/Requests/Listing/UploadListingMediaRequest.php`
* `app/Http/Resources/Listing/ListingResource.php`
* `app/Http/Resources/Listing/ListingDetailResource.php`
* `app/Http/Resources/Listing/ListingMediaResource.php`

### Tests & Documentation
* `tests/Feature/Listing/ListingDraftAndCreationTest.php`
* `tests/Feature/Listing/ListingMediaTest.php`
* `tests/Feature/Listing/ListingConsultationAndDetailTest.php`
* `tests/Feature/Listing/ListingSearchAndMapTest.php`
* `tests/Feature/Listing/ListingSaveTest.php`
* `tests/Feature/Listing/ListingAuthorizationAndSecurityTest.php`
* `docs/cr4_listing_api_documentation.md`
* `docs/cr4_listing_messaging_contract.md`
* `docs/cr4_listing_landlord_agent_contract.md`
