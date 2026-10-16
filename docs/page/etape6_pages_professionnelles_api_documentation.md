# BANGO — Module 12 : PG — Pages Professionnelles
## Documentation Technique d'Architecture & Référence API Backend (Laravel 13 & PostgreSQL)

---

### 1. Périmètre Fonctionnel de PG
Le module **PG (Pages Professionnelles)** fournit aux entreprises, commerces, artisans, agences immobilières et prestataires de services une identité publique distincte de leur compte personnel sur BANGO.

Ses piliers fonctionnels sont :
1. **Identité publique de marque** : nom public, identifiant unique (`handle`), logo, couverture, description, coordonnées de contact, ville/quartier et secteur d'activité (`real_estate_agency`, `craftsman`, `commerce`, `services`, `other`).
2. **Cloisonnement & RBAC (Role-Based Access Control)** : séparation stricte de l'identité personnelle et de l'identité de Page, équipe collaborative avec 3 rôles hiérarchisés :
   - `owner` (Propriétaire) : gestion globale, finances, certification, invitations/rôles, transfert de propriété.
   - `editor` (Éditeur) : publication Square, Reels, gestion de catalogue.
   - `moderator` (Modérateur) : accès en lecture et réponse à l'inbox professionnelle, modération des interactions.
3. **Workflow de Certification Entreprise (PG-05 / PG-07)** :
   - Dépôt de dossier administratif officiel (RCCM, NIF, représentant légal, pièces d'identité, justificatifs téléversés en stockage sécurisé non-public).
   - Règlement des frais d'instruction via le module existant **PAY** (Mobile Money / Monetbil).
   - Règle métier **PG-07** : deux corrections/resoumissions gratuites incluses en cas de demande de pièces complémentaires.
   - Décision administrative (`approved`, `rejected`, `supplement_requested`, `suspended`) prise exclusivement par les administrateurs plateforme.
4. **Cloisonnement de l'Inbox Professionnelle (MS-19)** : boîte de réception dédiée à la Page, accessible uniquement aux membres habilités de l'équipe, isolée des messages privés du compte personnel.
5. **Vitrine Immobilière d'Agence (IM)** : exposition des annonces certifiées rattachées à la Page.
6. **Publication & Branding Transversal (SQ & RL)** : publication de posts Square et de Reels au nom de la Page avec attribution traçable côté serveur de l'auteur membre.
7. **Sponsoring & Boost (BO / CB)** : lancement de campagnes publicitaires sponsorisées « Sponsorisé par [Nom de la Page] » financées via le portefeuille Crédit BANGO de l'initiateur habilité.
8. **Gouvernance & Sécurité EDGE (EDGE-01 à EDGE-06)** : protection du dernier propriétaire, révocation immédiate sans délai de grâce, suspension d'agrément sans suppression destructrice.

---

### 2. Modèle de Données & Tables PostgreSQL

#### 2.1 Table `pages`
```sql
CREATE TABLE pages (
    id BIGSERIAL PRIMARY KEY,
    owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    name VARCHAR(255) NOT NULL,
    handle VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    sector VARCHAR(64) NOT NULL DEFAULT 'other',
    logo_url VARCHAR(255) NULL,
    cover_url VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(32) NULL,
    website VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(255) NULL,
    neighbourhood VARCHAR(255) NULL,
    certification_status VARCHAR(32) NOT NULL DEFAULT 'none',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    metadata JSONB NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL
);
CREATE INDEX pages_status_cert_idx ON pages (status, certification_status);
CREATE INDEX pages_sector_status_idx ON pages (sector, status);
CREATE INDEX pages_city_neighbourhood_idx ON pages (city, neighbourhood);
```

#### 2.2 Table `page_members`
```sql
CREATE TABLE page_members (
    id BIGSERIAL PRIMARY KEY,
    page_id BIGINT NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(32) NOT NULL DEFAULT 'editor',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    invited_by_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    invited_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT page_members_unique_association UNIQUE (page_id, user_id)
);
CREATE INDEX page_members_user_status_idx ON page_members (user_id, status);
CREATE INDEX page_members_role_status_idx ON page_members (page_id, role, status);
```

#### 2.3 Table `page_certifications`
```sql
CREATE TABLE page_certifications (
    id UUID PRIMARY KEY,
    page_id BIGINT NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    submitted_by_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    rccm_number VARCHAR(128) NULL,
    nif_number VARCHAR(128) NULL,
    legal_representative_name VARCHAR(255) NULL,
    legal_representative_id_type VARCHAR(32) NULL,
    legal_representative_id_number VARCHAR(64) NULL,
    documents JSONB NULL,
    rejection_reason TEXT NULL,
    supplement_request_reason TEXT NULL,
    corrections_count INTEGER NOT NULL DEFAULT 0,
    payment_transaction_id BIGINT NULL REFERENCES payment_transactions(id) ON DELETE SET NULL,
    decided_by_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    submitted_at TIMESTAMP NULL,
    decided_at TIMESTAMP NULL,
    history JSONB NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL
);
CREATE INDEX page_certifications_page_status_idx ON page_certifications (page_id, status);
CREATE INDEX page_certifications_status_submitted_idx ON page_certifications (status, submitted_at);
```

#### 2.4 Clés étrangères rattachées dans les modules existants
- `reels.page_id` : relation optionnelle vers `pages.id` (ON DELETE SET NULL).
- `conversations.page_id` : relation optionnelle vers `pages.id` (ON DELETE SET NULL).
- `campaigns.page_id` : relation optionnelle vers `pages.id` (ON DELETE SET NULL).
- `listings.page_id` : relation optionnelle vers `pages.id` (ON DELETE SET NULL).
- `posts.page_id` : relation optionnelle vers `pages.id` (déjà existante dans Square).

---

### 3. Matrice des Rôles & Permissions RBAC (Côté Serveur)

| Action / Fonctionnalité | Propriétaire (`owner`) | Éditeur (`editor`) | Modérateur (`moderator`) | Utilisateur Extérieur | Admin Plateforme |
|---|:---:|:---:|:---:|:---:|:---:|
| **Consulter le profil public** | Oui | Oui | Oui | Oui | Oui |
| **Modifier nom, bio, logo, couverture** | Oui | Non (403) | Non (403) | Non (403) | Oui |
| **Inviter / Rétrograder / Révoquer un membre** | Oui | Non (403) | Non (403) | Non (403) | Oui |
| **Supprimer / Transférer la Page** | Oui | Non (403) | Non (403) | Non (403) | Oui |
| **Soumettre / Payer la certification** | Oui | Non (403) | Non (403) | Non (403) | Oui |
| **Publier un Post Square au nom de la Page** | Oui | Oui | Non (422) | Non (422) | Oui |
| **Publier un Reel au nom de la Page** | Oui | Oui | Non (403) | Non (403) | Oui |
| **Créer une Campagne Boost au nom de la Page**| Oui | Oui | Non (403) | Non (403) | Oui |
| **Accéder à l'Inbox professionnelle (MS-19)** | Oui | Oui | Oui | Non (403) | Oui |
| **Approuver / Rejeter une certification** | Non (403) | Non (403) | Non (403) | Non (403) | Oui |
| **Suspendre opérationnellement une Page** | Non (403) | Non (403) | Non (403) | Non (403) | Oui |

---

### 4. Workflow de Certification Entreprise & Règle PG-07

```
                     ┌─────────────────────────────┐
                     │   Formulaire de Dossier     │
                     │  (RCCM, NIF, Pièces, Repr.) │
                     └──────────────┬──────────────┘
                                    │ Soumission
                                    ▼
                     ┌─────────────────────────────┐
                     │   Statut: Pending           │
                     │   (En attente de paiement)  │
                     └──────────────┬──────────────┘
                                    │ POST /certification/payment
                                    ▼
                     ┌─────────────────────────────┐
                     │   Module PAY                │
                     │   Transaction MM Initiée    │
                     └──────────────┬──────────────┘
                                    │ Webhook PAY (confirmé)
                                    ▼
                     ┌─────────────────────────────┐
                     │   Frais Réglés (Réf PAY)    │
                     │   Dossier examiné par Admin │
                     └──────────────┬──────────────┘
                                    │
           ┌────────────────────────┼────────────────────────┐
           │ Approbation            │ Demande pièces         │ Rejet définitif
           ▼                        ▼                        ▼
┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐
│  Statut: Approved    │  │ SupplementRequested  │  │   Statut: Rejected   │
│  Badge: page_cert... │  │ (Corrections <= 2)   │  │   Motif d'exclusion  │
└──────────────────────┘  └──────────┬───────────┘  └──────────────────────┘
                                     │ Resoumission
                                     ▼
                          ┌──────────────────────┐
                          │ corrections_count++  │
                          │ Si > 2 : Blocage 422 │
                          └──────────────────────┘
```

#### Règle PG-07 : Limite de 2 corrections gratuites incluses
- Chaque demande de compléments administratifs par le back-office passe le dossier en statut `supplement_requested`.
- Le propriétaire soumet les pièces rectifiées via `POST /api/pages/certifications/{certification}/resubmit`.
- Chaque resoumission incrémente `corrections_count`.
- À partir de la **3e tentative** (`corrections_count >= 2`), l'API rejette immédiatement la requête avec code **HTTP 422** et message :
  `"Le quota de 2 corrections incluses dans le processus de certification a été atteint."`

---

### 5. Règles EDGE et Gouvernance

- **EDGE-01 : Protection du dernier propriétaire**
  - Impossible de révoquer le dernier propriétaire actif d'une Page. L'opération renvoie `422 Unprocessable Entity` avec l'erreur `{"errors": {"member": ["Impossible de révoquer le dernier propriétaire de la Page."]}}`.
  - Le dernier propriétaire ne peut pas quitter l'équipe de son plein gré (`POST /api/pages/{page}/leave`) sans successeur désigné préalable (renvoie `422 Unprocessable Entity`).
  - Le transfert de propriété (`POST /api/pages/{page}/transfer`) s'effectue dans une transaction atomique : le nouveau propriétaire est promu `owner`, et l'ancien est rétrogradé en `editor`.
- **EDGE-02 : Révocation immédiate sans délai de grâce**
  - Dès qu'un membre est révoqué (`status = revoked`), ses requêtes ultérieures sont bloquées immédiatement.
  - L'accès à l'inbox professionnelle (`GET /api/pages/{page}/inbox`) et l'envoi de messages dans les conversations de Page (`POST /api/messages/threads/{id}/messages`) renvoient immédiatement **HTTP 403 Forbidden**.
  - La publication de posts Square (`as_page_id`) et de Reels au nom de la Page est instantanément rejetée.
- **EDGE-03 : Suspension de certification**
  - Si l'administrateur suspend la certification (`POST /api/admin/pages/{page}/suspend-certification`), le statut devient `suspended`.
  - Le badge public `page_certified` disparaît immédiatement de la vitrine et des contenus.
  - La Page reste active et n'est pas détruite.
- **EDGE-04 : Suspension opérationnelle de la Page**
  - En cas de fraude ou de signalement grave, l'administrateur peut suspendre la Page (`POST /api/admin/pages/{page}/suspend`).
  - Statut basculé en `suspended` : toute modification, publication Square ou Reels au nom de la Page est bloquée.

---

### 6. Répertoire Exhaustif des Endpoints API

#### 6.1 Endpoints Publics (Sans Authentification)
- **`GET /api/search/pages`** : Recherche et filtrage multi-critères des Pages (`q`, `sector`, `city`, `neighbourhood`, `certified=1`).
- **`GET /api/pages/{identifier}`** : Consultation du profil public d'une Page par son ID numérique ou son `handle`.
- **`GET /api/pages/{page}/listings`** : Vitrine des annonces immobilières publiées de l'agence.

#### 6.2 Endpoints Utilisateur Authentifié (`auth:api`)
- **`GET /api/me/pages`** et **`GET /api/pages/my-pages`** : Liste des Pages dont l'utilisateur connecté est membre actif ou propriétaire.
- **`POST /api/pages`** : Création d'une nouvelle Page Professionnelle.
- **`PUT /api/pages/{page}`** : Mise à jour des informations, logo et couverture (`owner` uniquement).
- **`DELETE /api/pages/{page}`** : Suppression logique soft-delete de la Page (`owner` uniquement).
- **`POST /api/pages/{page}/transfer`** : Transfert de propriété transactionnel vers un autre utilisateur (`owner` uniquement).
- **`POST /api/pages/{page}/switch-context`** : Bascule du contexte de navigation frontend vers l'identité de la Page.
- **`GET /api/pages/{page}/inbox`** : Boîte de réception dédiée de la Page Professionnelle (`owner`, `editor`, `moderator`).

#### 6.3 Gestion des Membres & Invitations (`auth:api`)
- **`GET /api/pages/{page}/members`** : Liste des membres et invitations de la Page.
- **`POST /api/pages/{page}/members/invite`** : Invitation d'un utilisateur par identifiant (`user_id`, `role`).
- **`PUT /api/pages/{page}/members/{member}/role`** : Modification du rôle attribué à un membre (`owner` uniquement).
- **`DELETE /api/pages/{page}/members/{member}`** : Révocation immédiate d'un membre (`owner` uniquement).
- **`POST /api/pages/{page}/leave`** : Sortie volontaire de l'équipe de la Page (contrôle EDGE-01).
- **`POST /api/page-members/{member}/accept`** : Acceptation d'une invitation par l'utilisateur invité.
- **`POST /api/page-members/{member}/decline`** : Refus d'une invitation par l'utilisateur invité.

#### 6.4 Dossiers de Certification & Paiement PAY (`auth:api`)
- **`GET /api/pages/{page}/certification`** : Consultation de l'état du dossier de certification.
- **`GET /api/pages/{page}/certification/history`** : Historique des décisions et événements d'instruction.
- **`POST /api/pages/{page}/certification`** : Dépôt initial du dossier de certification avec téléversement sécurisé de justificatifs.
- **`POST /api/pages/{page}/certification/payment`** : Initiation du règlement des frais de dossier via PAY (Mobile Money).
- **`POST /api/pages/certifications/{certification}/resubmit`** : Resoumission d'un dossier suite à une demande de pièces complémentaires (PG-07).
- **`GET /api/pages/certifications/{certification}/documents/{key}`** : Téléchargement sécurisé et vérifié d'une pièce administrative stockée en zone privée.

#### 6.5 Administration Plateforme (`auth:api` + `admin`)
- **`GET /api/admin/pages/certifications`** : Liste et filtres des dossiers de certification en attente d'instruction.
- **`POST /api/admin/pages/certifications/{certification}/decide`** : Décision administrative (`approved`, `rejected`, `supplement_requested`).
- **`POST /api/admin/pages/{page}/suspend-certification`** : Suspension conservatoire d'une certification.
- **`POST /api/admin/pages/{page}/suspend`** : Suspension opérationnelle complète de la Page.
- **`POST /api/admin/pages/{page}/unsuspend`** : Levée de suspension opérationnelle.

---

### 7. Intégrations Intermodules Validées

1. **MS (Messagerie)** :
   - Table `conversations` enrichie avec `page_id`.
   - Les conversations de type `page` sont réservées aux membres actifs autorisés de la Page (`canUserAccessInbox`).
   - Tout membre révoqué perd instantanément l'accès en lecture et l'autorisation d'envoi de messages.
2. **PAY (Paiement)** :
   - Intégration stricte sans doublon de passerelle via `PricingService` (produit `page_pro`) et `PaymentEngineService::createOrRetrieveTransaction()`.
   - Fulfiller idempotent rattachant `payment_transaction_id` à la certification sans jamais accorder automatiquement le statut `approved`.
3. **SQ (Square) & RL (Reels)** :
   - Prise en charge du paramètre `as_page_id`.
   - Vérification côté serveur que le membre dispose des droits de publication (`canUserPublish`) et que la Page n'est pas suspendue.
   - Branding professionnel affiché : `is_page_post = true`, `author_name = [Nom Page]`, `badge = page_certified`.
4. **BO (Boost & Sponsoring)** :
   - Prise en charge des campagnes Boost créées au nom d'une Page (`page_id`).
   - Affichage de la mention « Sponsorisé par [Nom de la Page] » dans les métadonnées de campagne.
5. **IM (Immobilier)** :
   - Table `listings` enrichie avec `page_id`.
   - Exposition de la vitrine d'agence (`GET /api/pages/{page}/listings`).
6. **RC (Recherche & Découverte)** :
   - Recherche dédiée `GET /api/search/pages` avec filtres par secteur, ville, quartier et certification.
7. **NOTIF (Notifications)** :
   - 9 notifications distinctes : invitations, acceptations, refus, révocations, soumissions de certification, demandes de compléments, décisions et suspensions.
8. **LG (Légal & KYC)** :
   - Justificatifs administratifs stockés sur disque `local` non exposé publiquement (`page_certifications/{page_id}/`).
   - Accès restreint au propriétaire et aux administrateurs via URL de téléchargement protégée par Gate (`downloadDocument`).
