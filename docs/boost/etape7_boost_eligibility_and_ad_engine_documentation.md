# BANGO — ÉTAPE 7 : DOCUMENTATION TECHNIQUE & CONTRATS D'API
## DURCISSEMENT DE L'ÉLIGIBILITÉ AU BOOST, RACCORDEMENT SUB + PG ET MOTEUR PUBLICITAIRE

---

### 1. Vue d'ensemble & Architecture

L'étape 7 consolide l'infrastructure publicitaire de BANGO en raccordant les modules **SUB (Abonnements)**, **BADGE (Badges Professionnels)**, **PG (Pages Professionnelles)**, **BO (Boost & Sponsoring)**, **CREDIT BANGO (Portefeuille virtuel)**, **SQ (Square Feeds)**, **RL (Reels Feeds)**, **LG (Consentement RGPD)**, **SUP (Modération)**, **IM / BL (Immobilier)** et **NOTIF (Notifications)**.

```
+-----------------------------------------------------------------------------------+
|                              REQUÊTE CLIENT (APP MOBILE)                          |
+-----------------------------------------------------------------------------------+
                                          |
                                          v
+-----------------------------------------------------------------------------------+
|                     AUTHENTIFICATION PASSPORT & GATE 'boost.start'                |
|                    - CampaignEligibilityService::checkEligibility()               |
|                    - Vérification Persona (Bailleur/Agent + KYC + SUB active)     |
|                    - OU Vérification Page Pro (Active + Certifiée + Rôle valide)  |
+-----------------------------------------------------------------------------------+
                                          |
                                          v
+-----------------------------------------------------------------------------------+
|                        CRÉATION / ACTIVATION DE CAMPAGNE                          |
|                    - Validation contenu (Post / Reel / Listing)                   |
|                    - Débit atomique Crédit BANGO avec verrouillage pessimiste     |
|                    - Statut Active / Scheduled selon dates                        |
|                    - Notification CampaignActivatedNotification                   |
+-----------------------------------------------------------------------------------+
                                          |
                                          v
+-----------------------------------------------------------------------------------+
|                       MOTEUR DE DIFFUSION (AdDeliveryService)                     |
|                    - Feeds Square (SQ) & Reels (RL)                               |
|                    - Filtres : statut Active, start_at <= now() < end_at          |
|                    - Vérification solde budget restant (spent_amount < budget)    |
|                    - Ciblage géographique (ville)                                 |
|                    - Consentement LG (ads_personalization)                        |
|                    - Déduplication stricte & injection avec mention Sponsorisé    |
+-----------------------------------------------------------------------------------+
                                          |
                                          v
+-----------------------------------------------------------------------------------+
|                     MÉTRIQUES, NOTIFICATIONS & GARDE-FOUS EDGE                    |
|                    - Impression & Clic unitaires (/impression, /click)            |
|                    - Suspension auto si bien loué/taken ou contenu modéré         |
|                    - Notifications de suspension & fin de campagne                |
+-----------------------------------------------------------------------------------+
```

---

### 2. Axe A : Raccordement SUB + BADGE & Règle Unique d'Éligibilité

L'autorisation centrale est enregistrée via la Gate Laravel `boost.start` dans `AppServiceProvider` et pilotée par `App\Services\Boost\CampaignEligibilityService`.

#### Règles Métier Personnelles :
1. **Utilisateur vérifié** : `user->is_verified === true` et `user->verification_lapsed === false`.
2. **Badge professionnel requis** : L'utilisateur doit posséder le badge `landlord` (Bailleur certifié) ou `agent` (Agent immobilier). Un compte standard sans badge est strictement refusé (`403 Forbidden`).
3. **Abonnement SUB actif ou en grâce** : L'utilisateur doit posséder une souscription dans la table `subscriptions` avec un état `active` ou `grace`, liée à un produit catalogue correspondant à son rôle (`landlord` ou `agent`). Un abonnement `expired`, `suspended` ou `cancelled` bloque immédiatement le lancement.
4. **Appartenance du contenu** : L'utilisateur doit être le propriétaire direct du Post, Reel ou de l'annonce immobilière ciblée (`content->user_id === user->id`).

---

### 3. Axe B : Sponsoring par une Page Professionnelle (PG)

Une campagne peut être sponsorisée au nom d'une Page Professionnelle (`page_id` fourni ou déduit du contenu rattaché).

#### Règles Métier Page Pro :
1. **Statut de la Page** : La Page doit être active (`status === 'active'`). Une page suspendue, en attente ou fermée entraîne un rejet immédiat.
2. **Certification obligatoire** : La Page doit impérativement avoir une certification approuvée (`certification_status === 'approved'`). Une Page non certifiée (`none`, `pending`, `rejected`) ne peut pas sponsoriser.
3. **RBAC des Membres** :
   - Seuls les membres avec le rôle **Owner** ou **Editor** (`canUserPublish = true`) ont le droit de lancer et gérer les campagnes publicitaires de la Page.
   - Les modérateurs (`moderator`), membres révoqués (`status = 'revoked'`) ou invités non validés (`status = 'invited'`) reçoivent une erreur `403 Forbidden`.
4. **Intégrité du contenu** : Le contenu boosté doit appartenir à la Page (`content->page_id === page->id`). Un membre ne peut pas sponsoriser son post personnel au nom de la Page sans que le post ne soit lié à la Page.
5. **Attribution & Branding** : Le contrat API renvoie `sponsor_type: "page"`, `page_id`, l'objet `page` (logo, handle, nom, certification) et `sponsored_by: "Sponsorisé par [Nom de la Page]"`.

---

### 4. Axe C : Cycle de Vie des Campagnes & Cohérence Financière

#### Statuts de Campagne (`CampaignStatus`) :
- `draft` : Brouillon initial.
- `pending` : Campagne enregistrée en attente de validation ou de règlement externe.
- `active` : Campagne active et en cours de diffusion.
- `paused` : Campagne mise en pause par le promoteur (peut être reprise).
- `suspended` : Campagne suspendue côté système (sanction modération, bien loué, perte d'éligibilité).
- `completed` : Campagne terminée (dates échues ou budget épuisé).
- `cancelled` : Campagne annulée manuellement par le promoteur.

#### Matrice de Transition :
| De \ Vers | Draft | Pending | Active | Paused | Suspended | Completed | Cancelled |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Draft** | — | ✓ | ✓ | ✗ | ✗ | ✗ | ✓ |
| **Pending** | ✗ | — | ✓ | ✗ | ✗ | ✗ | ✓ |
| **Active** | ✗ | ✗ | — | ✓ | ✓ | ✓ | ✓ |
| **Paused** | ✗ | ✗ | ✓ | — | ✓ | ✓ | ✓ |
| **Suspended**| ✗ | ✗ | ✓ | ✗ | — | ✓ | ✓ |
| **Completed**| ✗ | ✗ | ✗ | ✗ | ✗ | — | ✗ |
| **Cancelled**| ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | — |

#### Débit Atomique du Portefeuille Crédit BANGO :
- Lors de la création d'une campagne avec `payment_method = 'credit'`, le portefeuille de l'utilisateur est débité immédiatement de manière atomique sous verrouillage pessimiste (`lockForUpdate`).
- **Idempotence stricte** : Clé d'idempotence basée sur `campaign_debit_{idempotency_key}` ou `campaign_debit_{campaign_id}` pour empêcher tout double débit lors des retries réseau.
- **Règle immuable** : Aucun remboursement automatique de crédits n'est autorisé lors de l'annulation d'une campagne.

---

### 5. Axe D : Moteur de Diffusion & Feeds Square & Reels

Le service central `App\Services\Boost\AdDeliveryService` gère l'injection et la déduplication dans les flux :

1. **Critères d'éligibilité de diffusion (`canBeServed()`)** :
   - `status === 'active'`
   - `start_at <= now() && end_at > now()`
   - `spent_amount < budget_amount`
   - Le contenu sous-jacent existe toujours et n'est pas modéré/supprimé.
   - Si le contenu est une annonce immobilière (`Listing`), son statut ne doit être ni `taken`, ni `archived`, ni `draft`.
2. **Ciblage Géographique** :
   - Si la campagne définit un ciblage par ville (`targeting.city`), seuls les utilisateurs de cette ville reçoivent la publicité.
3. **Consentement LG (RGPD)** :
   - Vérification du flag `ads_personalization` dans les paramètres de confidentialité de l'utilisateur spectateur (`user->privacy_settings['ads_personalization']`).
   - Si désactivé, les ciblages comportementaux/profilés sont exclus.
4. **Déduplication & Marquage** :
   - **Déduplication** : Si le post ou le reel est déjà présent dans la sélection organique de la page, il est enrichi in-place avec les attributs sponsorisés sans être dupliqué.
   - S'il n'est pas présent, il est injecté en tête de liste sans doublon.
   - Attributs injectés :
     - `is_sponsored: true`
     - `campaign_id: string (UUID)`
     - `sponsored_by: string`
     - `sponsor_branding: { type: 'user'|'page', name: string, avatar_url: string, is_certified: bool }`

---

### 6. Contrats d'API

#### A. Estimation du Boost
- **Endpoint** : `POST /api/boost/estimate`
- **Headers** : `Authorization: Bearer <token>`, `Accept: application/json`
- **Body** :
```json
{
  "boostable_type": "post",
  "boostable_id": "99",
  "page_id": 1,
  "budget": 10000,
  "currency": "XAF",
  "duration_days": 7,
  "targeting": {
    "city": "Douala",
    "neighbourhoods": ["Akwa", "Bonanjo"],
    "radius_km": 5,
    "age_min": 25,
    "age_max": 50,
    "interests": ["immo"]
  }
}
```
- **Réponse Succès (200 OK)** :
```json
{
  "data": {
    "budget": 10000,
    "currency": "XAF",
    "duration_days": 7,
    "cpm_baseline": 350,
    "estimated_impressions_min": 22800,
    "estimated_impressions_max": 34200,
    "estimated_reach_min": 17100,
    "estimated_reach_max": 25600,
    "estimated_clicks_min": 450,
    "estimated_clicks_max": 1020,
    "targeting_multiplier": 1.2
  }
}
```
- **Erreurs** :
  - `401 Unauthorized` : Utilisateur non connecté.
  - `403 Forbidden` : Utilisateur non éligible (pas de badge pro, pas de souscription active, ou permissions Page insuffisantes).
  - `422 Unprocessable Entity` : Données de ciblage ou budget invalides (budget < 100 XAF).

---

#### B. Création & Lancement d'une Campagne
- **Endpoint** : `POST /api/boost/campaigns`
- **Headers** : `Authorization: Bearer <token>`, `Accept: application/json`
- **Body** :
```json
{
  "boostable_type": "post",
  "boostable_id": "99",
  "page_id": 1,
  "objective": "reach",
  "budget": 15000,
  "currency": "XAF",
  "duration_days": 5,
  "payment_method": "credit",
  "idempotency_key": "c4ca4238a0b923820dcc509a6f75849b",
  "targeting": {
    "city": "Douala"
  }
}
```
- **Réponse Succès (201 Created)** :
```json
{
  "data": {
    "id": "9d90181b-5e76-47b7-b08e-599e03d4a67e",
    "user_id": 42,
    "page_id": 1,
    "page": {
      "id": 1,
      "name": "Agence Immobilière Prestige",
      "handle": "agence_prestige",
      "logo_url": "https://bango.cm/storage/pages/logo.jpg",
      "is_certified": true
    },
    "sponsor_type": "page",
    "sponsored_by": "Sponsorisé par Agence Immobilière Prestige",
    "kind": "post",
    "boostable_type": "App\\Models\\Square\\Post",
    "boostable_id": "99",
    "objective": {
      "value": "reach",
      "label": "Notoriété & Portée"
    },
    "budget": 15000.0,
    "currency": "XAF",
    "duration_days": 5,
    "start_at": "2026-10-16T15:00:00Z",
    "end_at": "2026-10-21T15:00:00Z",
    "status": {
      "value": "active",
      "label": "Active"
    },
    "metrics": {
      "impressions": 0,
      "clicks": 0,
      "ctr": 0.0,
      "spent_amount": 0.0,
      "budget_consumed_percentage": 0.0
    }
  }
}
```

---

#### C. Suivi des Métriques (Impressions & Clics)
- **Impression** : `POST /api/boost/campaigns/{id}/impression`
- **Clic** : `POST /api/boost/campaigns/{id}/click`
- **Réponse Succès (200 OK)** :
```json
{
  "success": true,
  "impressions": 142,
  "clicks": 12,
  "spent_amount": 49.7
}
```

---

#### D. Feeds Square & Reels avec Injection Sponsorisée
- **Square** : `GET /api/square/feed` (ou `/api/square-cr1/square/feed`)
- **Reels** : `GET /api/reels/feed`
- **Contrat Élément Sponsorisé dans le Feed** :
```json
{
  "id": "99",
  "author_name": "Agence Immobilière Prestige",
  "body": "Découvrez notre nouvelle résidence à Bonapriso",
  "is_sponsored": true,
  "campaign_id": "9d90181b-5e76-47b7-b08e-599e03d4a67e",
  "sponsored_by": "Agence Immobilière Prestige",
  "sponsor_branding": {
    "type": "page",
    "name": "Agence Immobilière Prestige",
    "avatar_url": "https://bango.cm/storage/pages/logo.jpg",
    "is_certified": true
  }
}
```

---

### 7. Recommandations & Intégration Frontend Mobile

1. **Badge & Pastille « Sponsorisé »** :
   - Vérifier le booléen `item.is_sponsored === true`.
   - Afficher un badge distinctif « Sponsorisé » au-dessus ou sous le nom de l'auteur.
   - Si `sponsor_branding.type === 'page'`, cliquer sur l'en-tête doit rediriger vers la Page Professionnelle (`/pages/{handle}`).
2. **Gestion des Erreurs d'Éligibilité (`403 Forbidden`)** :
   - Si l'utilisateur tente d'accéder au formulaire de boost sans badge ou sans abonnement actif, le backend renvoie un message explicite (ex: `Un abonnement actif de type Bailleur ou Agent est requis`).
   - Rediriger l'utilisateur vers l'écran de souscription (`/subscriptions/plans`) ou de demande de badge KYC.
3. **Sélection du Commanditaire** :
   - L'écran de création de campagne doit proposer le choix entre :
     - Compte personnel (si badge et SUB actifs).
     - Page Professionnelle (si l'utilisateur est `owner` ou `editor` d'une Page certifiée).
4. **Collecte des Métriques** :
   - Appeler `POST /api/boost/campaigns/{id}/impression` une seule fois lorsque l'élément sponsorisé reste visible à plus de 50% à l'écran pendant au moins 1 seconde.
   - Appeler `POST /api/boost/campaigns/{id}/click` lors du tap sur le contenu ou sur le CTA du Reel/Post sponsorisé.
