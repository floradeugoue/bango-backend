# BANGO — Documentation Technique : Étape 5 (BO — Boost & Publicité & Crédit BANGO)

## 1. Vue d'ensemble de l'Architecture

Le module **BO (Boost & Publicité)** de BANGO permet aux utilisateurs et professionnels d'accroître la visibilité de leurs publications Square et Reels vidéo à travers des campagnes publicitaires ciblées, financées par le **Crédit BANGO** ou via les passerelles de paiement externes existantes (PAY / SUB).

### Principes directeurs et frontières architecturales :
1. **Zéro duplication de contenus (Polymorphisme sécurisé)** :
   - Les campagnes référencent les contenus réels du Square (`App\Models\Square\Post`) et des Reels (`App\Models\Reel\Reel`) via les colonnes polymorphes `boostable_type` et `boostable_id`.
   - Une liste blanche stricte (`Campaign::ALLOWED_BOOSTABLE_MAP`) empêche qu'une campagne puisse référencer arbitrairement un modèle Eloquent externe.
   - Les annonces (`Listing`) sont préparées architecturalement sans modification prématurée du cycle de vie immobilier.
2. **Grand livre immuable de Crédit BANGO (Wallets & Ledger)** :
   - Portefeuille utilisateur (`credit_wallets`) avec solde strict non-négatif garanti en base et au niveau applicatif.
   - Grand livre comptable append-only (`credit_transactions`) consignant chaque mouvement (dépôt, débit campagne, remboursement reliquat, ajustement).
   - Verrous pessimistes PostgreSQL (`lockForUpdate`) et transactions ACID pour éliminer tout risque de double débit lors de requêtes concurrentes.
   - Clés d'idempotence uniques pour sécuriser les rejeux réseau et webhooks.
3. **Raccordement transparent à PAY & SUB (Aucune duplication financière)** :
   - Réutilisation du produit de catalogue `listing_boost` (cadence CPM : 2 180 XAF pour 1 000 impressions ciblées) et des devis certifiés (`POST /api/payments/quote`).
   - Hook de fulfillment automatique dans `PaymentEngineService` : lors de la confirmation d'une transaction de paiement externe liée à une campagne, la campagne passe automatiquement au statut `active` sans second débit.
4. **Calcul estimatif de portée transparent** :
   - Calcul de portée basé sur la baseline CPM du catalogue avec fourchette prévisionnelle (`low = (budget / CPM) * 1000`, `high = low * 1.29`).
   - Mentions claires et documentées de non-garantie de résultats.
5. **Préparation pour l'étape 6** :
   - Les mécanismes de collecte et d'agrégation de métriques quotidiennes (`campaign_metrics`) sont prêts. L'injection complète dans les algorithmes de feed et le moteur de matching contextuel seront branchés à l'étape 6.

---

## 2. Spécification des Endpoints API

Toutes les routes sont préfixées par `/api/boost` et protégées par le middleware d'authentification Passport `auth:api`.

### 2.1 Estimation de portée

#### `POST /api/boost/estimate`
Calcule une projection prévisionnelle de portée et de dépense quotidienne en fonction du budget, de la durée, et des critères de ciblage.

- **Headers requis** :
  - `Authorization: Bearer <access_token>`
  - `Content-Type: application/json`
  - `Accept: application/json`
- **Body JSON** :
```json
{
  "budget": 5000,
  "currency": "XAF",
  "duration_days": 7,
  "boostable_type": "post",
  "boostable_id": "42",
  "targeting": {
    "city": "Douala",
    "neighbourhoods": ["Akwa", "Bonanjo"],
    "radius_km": 10,
    "age_min": 18,
    "age_max": 45,
    "interests": ["immo", "student"]
  }
}
```
- **Règles de validation** :
  - `budget` : obligatoire, numérique, minimum 100.
  - `currency` : optionnel, code ISO 3 caractères (défaut : XAF).
  - `duration_days` : optionnel, entier entre 1 et 30 (défaut : 7).
  - `boostable_type` : optionnel, `in:post,posts,reel,reels,listing,listings`.
  - `boostable_id` : optionnel, identifiant du contenu.
- **Réponse HTTP 200 OK** :
```json
{
  "data": {
    "budget": 5000,
    "currency": "XAF",
    "duration_days": 7,
    "daily_budget": 714.29,
    "reach": {
      "low": 2294,
      "high": 2959,
      "daily_low": 328,
      "daily_high": 423
    },
    "cpm": 2180,
    "estimated_audience_pool": 63900,
    "is_guaranteed": false,
    "disclaimer": "Cette estimation est purement indicative et ne constitue en aucun cas une garantie contractuelle de performance. La portée réelle dépend de l'inventaire publicitaire disponible, des enchères concurrentes et des interactions des utilisateurs.",
    "assumptions": {
      "cpm_baseline": "2 180,00 XAF pour 1 000 impressions ciblées",
      "high_bound_factor": "+29% selon la viralité du contenu et l'engagement initial",
      "pacing": "Diffusion répartie uniformément sur la durée sélectionnée"
    }
  }
}
```

---

### 2.2 Gestion des Campagnes Publicitaires

#### `POST /api/boost/campaigns`
Crée une nouvelle campagne publicitaire, vérifie la propriété du contenu ciblé, et initie le règlement par Crédit BANGO ou paiement externe.

- **Body JSON** :
```json
{
  "boostable_type": "post",
  "boostable_id": "42",
  "objective": "reach",
  "budget": 10000,
  "currency": "XAF",
  "duration_days": 5,
  "payment_method": "credit",
  "idempotency_key": "camp_req_998877",
  "targeting": {
    "city": "Douala",
    "neighbourhoods": ["Bonapriso"],
    "radius_km": 5,
    "age_min": 25,
    "age_max": 50,
    "interests": ["immo"]
  }
}
```
- **Règles de validation & Sécurité** :
  - `boostable_type` : obligatoire, liste blanche (`post`, `reel`, `listing`).
  - `boostable_id` : obligatoire, le contenu doit exister et appartenir à l'utilisateur authentifié (HTTP 403 si contenu tiers).
  - `objective` : optionnel (`reach`, `profile_visits`, `messages`, `engagement`).
  - `budget` : obligatoire, numeric >= 100.
  - `payment_method` : `credit` ou `external`.
    - Si `credit` : débit atomique immédiat du solde portefeuille. Statut passe directement à `active`.
    - Si `external` : statut initialisé à `pending` en attente du webhook de confirmation.
- **Réponse HTTP 201 Created** :
```json
{
  "data": {
    "id": "01a14492-94cb-7300-9bb7-b04d7ce08950",
    "user_id": 1,
    "kind": "post",
    "boostable_type": "App\\Models\\Square\\Post",
    "boostable_id": "42",
    "content_label": "Superbe duplex avec piscine à louer...",
    "objective": {
      "value": "reach",
      "label": "Portée maximale"
    },
    "budget": 10000.0,
    "currency": "XAF",
    "duration_days": 5,
    "start_at": "2026-10-16T12:00:00.000000Z",
    "end_at": "2026-10-21T12:00:00.000000Z",
    "status": {
      "value": "active",
      "label": "En cours de diffusion"
    },
    "payment_method": "credit",
    "spent": 0.0,
    "unspent": 10000.0,
    "impressions_count": 0,
    "clicks_count": 0,
    "reach_count": 0
  }
}
```

#### `GET /api/boost/campaigns`
Lister de manière paginée les campagnes de l'utilisateur connecté.

- **Réponse HTTP 200 OK** : Collection paginée de `CampaignResource`.

#### `GET /api/boost/campaigns/{id}`
Détail complet d'une campagne avec historique de métriques.
- **Autorisation** : `CampaignPolicy@view` protège contre l'IDOR (HTTP 403 pour un utilisateur tiers).

#### `POST /api/boost/campaigns/{id}/pause`
Mise en pause d'une campagne active (`active -> paused`).

#### `POST /api/boost/campaigns/{id}/resume`
Reprise d'une campagne en pause (`paused -> active`).

#### `POST /api/boost/campaigns/{id}/cancel`
Interruption anticipée d'une campagne.
- **Remboursement automatique** : Le reliquat non consommé (`budget - spent`) est automatiquement reversé en **Crédit BANGO** sur le portefeuille de l'utilisateur avec enregistrement dans le grand livre (idempotent, aucun double remboursement possible).

#### `POST /api/boost/campaigns/{id}/fund`
Financer une campagne en statut `pending` à l'aide du solde de Crédit BANGO.

---

### 2.3 Portefeuille de Crédit BANGO

#### `GET /api/boost/wallet`
Consulter le solde disponible et les 10 dernières transactions.

- **Réponse HTTP 200 OK** :
```json
{
  "data": {
    "id": 1,
    "user_id": 10,
    "balance": 25000.0,
    "currency": "XAF",
    "recent_transactions": [
      {
        "id": 12,
        "type": {
          "value": "campaign_debit",
          "label": "Financement de campagne publicitaire",
          "is_credit": false
        },
        "amount": -5000.0,
        "balance_before": 30000.0,
        "balance_after": 25000.0,
        "description": "Financement campagne boost #01a14492",
        "created_at": "2026-10-16T12:30:00.000000Z"
      }
    ],
    "updated_at": "2026-10-16T12:30:00.000000Z"
  }
}
```

#### `GET /api/boost/wallet/transactions`
Historique complet paginé des écritures comptables du grand livre.

#### `POST /api/boost/wallet/deposit`
Recharge de crédits BANGO avec idempotence stricte (`idempotency_key`).

---

## 3. Matrice du Cycle de Vie des Campagnes

| Statut Initial | Statut Cible | Action / Événement | Acteur | Préconditions | Opération Financière |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `draft` | `pending` | Soumission pour paiement externe | Propriétaire | Contenu valide | Génération devis / référence externe |
| `draft` | `active` | Financement direct par crédits | Propriétaire | Contenu valide + solde suffisant | Débit atomique du portefeuille |
| `pending` | `active` | Confirmation paiement externe | Système (PAY Webhook) | Transaction confirmée | Attribution `payment_reference` |
| `pending` | `active` | Financement par crédits | Propriétaire | Solde suffisant | Débit atomique du portefeuille |
| `active` | `paused` | Mise en pause manuelle | Propriétaire | Campagne en cours | Arrêt temporaire de diffusion |
| `paused` | `active` | Reprise de la diffusion | Propriétaire | Campagne en pause | Reprise de diffusion |
| `active` / `paused` | `completed` | Terme de la campagne | Système | Date fin atteinte ou budget épuisé | Archivage des métriques |
| `active` / `paused` | `cancelled` | Annulation manuelle ou modération | Propriétaire / Modération | Campagne non terminée | Reversion du reliquat non consommé en Crédit BANGO |
