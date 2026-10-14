# API Back-Office du Module PAY — Spécification & Référence

Ce document détaille l'ensemble des endpoints administratifs de supervision, d'investigation, de gestion des litiges et de remboursement encadré pour le module de paiement **BANGO PAY**.

---

## 1. Sécurité et Contrôle d'Accès

Toutes les routes du Back-Office PAY sont isolées sous le préfixe `/api/admin/payments` et protégées par :
* **Authentification OAuth2** : `auth:api` (Laravel Passport).
* **Rôle Administrateur** : `CheckAdminRole` (`role_id` lié au rôle `admin`).
* **Principe du Moindre Privilège** : Les utilisateurs standards obtiennent une réponse HTTP `403 Forbidden`. Les appels non authentifiés reçoivent `401 Unauthorized`.
* **Protection anti-fuite de secrets** : Les clés secrètes d'agrégateur (`service_secret`, clés d'API, tokens, signatures d'IPN) sont automatiquement expurgées des représentations JSON renvoyées aux administrateurs.
* **Audit append-only** : Toute action sensible (remboursement, transition de statut d'un litige, note d'investigation) enregistre l'identifiant de l'administrateur, l'horodatage UTC et le motif obligatoire.

---

## 2. Synthèse des Endpoints

| Méthode | Endpoint | Description | Permission / Rôle |
|---|---|---|---|
| `GET` | `/api/admin/payments/transactions` | Recherche, filtrage et pagination des transactions | `admin` |
| `GET` | `/api/admin/payments/transactions/{id}` | Vue détaillée chronologique complète d'une transaction | `admin` |
| `GET` | `/api/admin/payments/transactions/{id}/events` | Historique immuable des événements financiers et techniques | `admin` |
| `POST` | `/api/admin/payments/transactions/{id}/refund` | Remboursement encadré avec preuve d'opération externe obligatoire | `admin` |
| `GET` | `/api/admin/payments/disputes` | Liste et recherche des dossiers de contestation et litiges | `admin` |
| `POST` | `/api/admin/payments/disputes` | Ouverture d'un nouveau dossier de litige | `admin` |
| `GET` | `/api/admin/payments/disputes/{id}` | Consultation détaillée d'un dossier de litige | `admin` |
| `POST` | `/api/admin/payments/disputes/{id}/status` | Changement de statut d'un litige avec décision motivée | `admin` |
| `POST` | `/api/admin/payments/disputes/{id}/notes` | Ajout d'une note d'investigation interne à un litige | `admin` |
| `GET` | `/api/admin/payments/double-charge-claims` | Recherche dédiée des signalements de doubles débits | `admin` |
| `POST` | `/api/admin/payments/double-charge-claims` | Enregistrement d'une réclamation de double débit | `admin` |

---

## 3. Détail des Requêtes et Réponses

### A. Liste et Recherche des Transactions
`GET /api/admin/payments/transactions`

#### Paramètres de recherche (Query Parameters) :
* `status` (string, optionnel) : `pending`, `processing`, `confirmed`, `failed`, `cancelled`, `refund_pending`, `refunded`.
* `user_id` (integer, optionnel) : Filtrer par identifiant d'utilisateur.
* `service_type` (string, optionnel) : `subscription`, `certification`, `boost`, `visit`.
* `provider` (string, optionnel) : `monetbil`, `flutterwave`, `apple`, `google`, `credit`.
* `channel` (string, optionnel) : `mobile_money`, `card`, `in_app_purchase`.
* `from` / `to` (date string `YYYY-MM-DD`, optionnel) : Fenêtre temporelle de création.
* `reference` (string, optionnel) : Référence publique (`BNG-TX-...`).
* `q` (string, optionnel) : Recherche textuelle sur la référence ou le nom de l'utilisateur.
* `per_page` (integer, optionnel, défaut : 20, max : 100) : Pagination serveur.

#### Exemple de Réponse (200 OK) :
```json
{
  "success": true,
  "data": [
    {
      "id": "1",
      "reference": "BNG-TX-20261014-9V41B662",
      "service_type": "subscription",
      "service_label": "Abonnement & Badge",
      "amount": 7500.00,
      "fee_amount": 0.00,
      "tax_amount": 0.00,
      "total_amount": 7500.00,
      "currency": "XAF",
      "provider": "monetbil",
      "provider_label": "Monetbil (Mobile Money)",
      "channel": "mobile_money",
      "status": "confirmed",
      "status_label": "Confirmé",
      "created_at": "2026-10-14T11:20:00+00:00",
      "updated_at": "2026-10-14T11:20:45+00:00",
      "user": {
        "id": "12",
        "name": "Jean Dupont",
        "email": "jean@example.com",
        "phone": "+237650000000"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

---

### B. Détail d'une Transaction
`GET /api/admin/payments/transactions/{id}`

Fournit une vision chronologique et complète de la transaction :
* Identité de l'acheteur.
* Snapshot tarifaire scellé lors du devis.
* Tentatives d'appels passerelle (`attempts`).
* Journal des événements audités (`events`).
* Dossiers de litiges ou réclamations de doubles débits associés (`disputes`).
* État de la souscription SUB liée, le cas échéant.

---

### C. Événements Immuables de la Transaction
`GET /api/admin/payments/transactions/{id}/events`

Retourne la liste chronologique ascendante des événements système, passerelle et administrateur. Les secrets et clés d'API sont automatiquement supprimés du champ `payload`.

---

### D. Remboursement Encadré
`POST /api/admin/payments/transactions/{id}/refund`

#### Règles métier strictes :
1. **Éligibilité** : Seule une transaction en statut `confirmed` peut faire l'objet d'un remboursement.
2. **Plafond** : Le montant du remboursement ne peut excéder le total initial de la transaction.
3. **Preuve d'opération externe obligatoire** : Comme Monetbil ne dispose pas d'API de payout automatique direct, le remboursement réel est effectué par l'opérateur (Mobile Money ou virement) et la référence externe officielle (`external_reference`) doit être renseignée.
4. **Motif obligatoire** : Un motif explicite (`reason`, min 5 caractères) est requis.

#### Corps de la requête (JSON) :
```json
{
  "reason": "Geste commercial suite à réclamation vérifiée",
  "external_reference": "MTN-REFUND-MOMO-789654",
  "amount": 7500.00
}
```

#### Réponse de succès (200 OK) :
```json
{
  "success": true,
  "data": {
    "id": "1",
    "reference": "BNG-TX-20261014-9V41B662",
    "status": "refunded",
    "status_label": "Remboursé",
    "refunded_at": "2026-10-14T11:30:00+00:00",
    "refund_details": {
      "refunded_amount": 7500.00,
      "external_reference": "MTN-REFUND-MOMO-789654",
      "reason": "Geste commercial suite à réclamation vérifiée",
      "admin_id": 2,
      "admin_name": "Admin Principal",
      "refunded_at": "2026-10-14T11:30:00+00:00"
    }
  },
  "message": "Remboursement enregistré et tracé avec succès."
}
```

---

### E. Gestion des Litiges et Contestations
`GET|POST /api/admin/payments/disputes`
`POST /api/admin/payments/disputes/{id}/status`
`POST /api/admin/payments/disputes/{id}/notes`

* **Génération automatique de référence** : Préfixe `BNG-DSP-...` (Litiges) ou `BNG-DCC-...` (Doubles débits).
* **Cycle de vie du statut** : `opened` ➔ `under_review` ➔ `resolved` / `rejected` / `closed`.
* **Exigence de décision** : Le passage à un état terminal ou sous revue requiert obligatoirement un motif décisionnel (`decision` ou `decision_reason`).

---

### F. Réclamations pour Doubles Débits
`GET|POST /api/admin/payments/double-charge-claims`

Permet d'instruire les réclamations où un utilisateur a reçu deux notifications de prélèvement Mobile Money pour la même tentative d'achat :
* Identification des références opérateur (`operator_reference`).
* Déduction automatique de l'utilisateur à partir de la transaction fournie.
* Évite formellement d'activer deux abonnements SUB ou de déformer l'historique financier de la transaction initiale.
