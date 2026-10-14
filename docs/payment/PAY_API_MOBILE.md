# BANGO — Spécification Complète des Contrats d'API Mobile PAY (Phase 4)

Ce document formalise les endpoints de paiement mis à disposition de l'application mobile BANGO (React Native / Expo), sécurisés par **Laravel Passport** (`auth:api`).

---

## 1. Vue d'Ensemble des Endpoints

| Méthode | Route | Description & Rôle Mobile | Écran / Composant Mobile |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/payments/catalog` | Présentation des offres d'abonnements, tarifs et prestations disponibles. | `BL-01`, `P-01`, `P-02` |
| `POST` | `/api/payments/quote` | Calcul certifié serveur du devis (frais inclus) avant engagement. | `PAY-01`, `PAY-02` |
| `POST` | `/api/payments/initiate` | Déclenchement de l'intention de paiement et push USSD (idempotent). | `PAY-06` $\rightarrow$ `PAY-08` |
| `GET` | `/api/payments/{id}/status` | Polling du statut en direct (120s) ou reprise après coupure réseau. | `PAY-07`, `EDGE-19` |
| `POST` | `/api/payments/{id}/cancel` | Annulation manuelle par l'utilisateur avant validation PIN. | `PAY-12` |
| `GET` | `/api/payments/history` | Historique paginé et filtrable des transactions personnelles. | `PAY-21` |
| `POST` | `/api/webhooks/payments/monetbil` | Webhook IPN Monetbil (public, vérification cryptographique MD5). | Serveur Monetbil |

---

## 2. Format Normalisé des Réponses d'Erreurs

Toutes les erreurs retournées par le module PAY respectent une structure JSON stable et prévisible pour React Native :

```json
{
  "success": false,
  "error": {
    "code": "PAYMENT_ALREADY_PENDING",
    "message": "Une demande de paiement est déjà en cours de validation.",
    "details": {},
    "retryable": false
  },
  "meta": {
    "request_id": "a5c7f8e1-4567-4890-a123-bcdef0123456",
    "timestamp": "2026-10-14T12:00:00Z"
  }
}
```

### Table des Codes d'Erreur Stables

| Code | HTTP | Description & Contexte | Action Frontend Recommandée |
| :--- | :---: | :--- | :--- |
| `UNAUTHENTICATED` | 401 | Jeton Passport manquant ou expiré. | Rediriger vers l'écran de connexion (`AU-01`). |
| `FORBIDDEN` | 403 | Accès interdit à une ressource d'un tiers. | Afficher une alerte de sécurité. |
| `INVALID_INPUT` | 422 | Données de requête invalides (validation échouée). | Afficher les erreurs sous les champs du formulaire. |
| `OFFER_NOT_FOUND` | 404 | Produit ou tarif d'abonnement introuvable ou inactif. | Recharger le catalogue (`GET /catalog`). |
| `SERVICE_NOT_ELIGIBLE` | 422 | Utilisateur déjà titulaire d'un abonnement actif pour ce badge. | Informer l'utilisateur que son badge est déjà actif. |
| `SERVICE_NOT_AVAILABLE` | 422 | Prestation non disponible à l'achat direct (Certification, Boost). | Afficher l'état "Bientôt disponible". |
| `PRICE_MODIFIED` | 409 | Le tarif en base a changé depuis le devis initial. | Demander confirmation explicite du nouveau devis. |
| `QUOTE_EXPIRED` | 409 | La validité du devis (15 min) a expiré. | Régénérer un devis (`POST /quote`). |
| `IDEMPOTENCY_CONFLICT` | 409 | Clé d'idempotence réutilisée avec des paramètres différents (`EDGE-17`). | Générer une nouvelle clé d'intention. |
| `TRANSACTION_NOT_FOUND` | 404 | Transaction introuvable ou n'appartenant pas à l'utilisateur. | Revenir à l'historique (`PAY-21`). |
| `INVALID_STATE` | 422 | Opération interdite par la machine à états (ex: annuler une transaction confirmée). | Rafraîchir l'écran de statut. |
| `PAYMENT_REFUSED` | 400 | Rejet opérateur (solde insuffisant, code PIN erroné). | Afficher l'écran d'échec opérateur (`PAY-09` / `PAY-11`). |
| `GATEWAY_UNAVAILABLE` | 504 | Timeout ou indisponibilité passerelle (`EDGE-19`). | Proposer à l'utilisateur de réessayer (`retryable: true`). |

---

## 3. Spécification Détaillée des Routes

### 3.1. `GET /api/payments/catalog`
Présente les offres actives, la tarification catalogue, les frais de passerelle estimés et le statut de disponibilité commerciale.

* **Réponse de Succès (`200 OK`) :**
  ```json
  {
    "success": true,
    "message": "Catalogue des prestations payantes récupéré avec succès.",
    "data": {
      "currency": "XAF",
      "gateway": "monetbil",
      "offers": [
        {
          "product_id": 1,
          "code": "landlord",
          "name": "Badge Bailleur Certifié",
          "service_type": "subscription",
          "badge_type": "landlord",
          "is_purchasable": true,
          "availability_status": "available",
          "prices": [
            {
              "price_id": 1,
              "cadence": "monthly",
              "duration_days": 30,
              "base_amount": 7500.0,
              "fee_amount": 150.0,
              "tax_amount": 0.0,
              "total_amount": 7650.0,
              "currency": "XAF",
              "savings_description": null
            },
            {
              "price_id": 2,
              "cadence": "yearly",
              "duration_days": 365,
              "base_amount": 75000.0,
              "fee_amount": 1500.0,
              "tax_amount": 0.0,
              "total_amount": 76500.0,
              "currency": "XAF",
              "savings_description": "2 mois offerts"
            }
          ]
        },
        {
          "product_id": 3,
          "code": "page_pro",
          "name": "Certification Page Pro",
          "service_type": "certification",
          "is_purchasable": false,
          "availability_status": "coming_soon",
          "prices": [...]
        }
      ],
      "services": [
        {
          "service_type": "visit",
          "code": "visit",
          "name": "Frais de Visite",
          "pricing_model": "custom",
          "is_purchasable": true,
          "min_amount": 500.0,
          "max_amount": 50000.0
        }
      ]
    }
  }
  ```

---

### 3.2. `POST /api/payments/quote`
Établit un devis officiel certifié côté serveur, incluant la décomposition immuable du prix de base, des frais d'opérateur facturés en sus au client et des taxes.

* **Body JSON :**
  ```json
  {
    "service_type": "subscription",
    "price_id": 1
  }
  ```
  *(Pour une visite : `{"service_type": "visit", "custom_amount": 5000}`)*

* **Réponse de Succès (`200 OK`) :**
  ```json
  {
    "success": true,
    "message": "Devis certifié calculé avec succès.",
    "data": {
      "service_type": "subscription",
      "product": {
        "id": 1,
        "code": "landlord",
        "name": "Badge Bailleur Certifié",
        "badge_type": "landlord"
      },
      "price_id": 1,
      "cadence": "monthly",
      "currency": "XAF",
      "base_amount": 7500.0,
      "fee_amount": 150.0,
      "tax_amount": 0.0,
      "total_amount": 7650.0,
      "gateway": "monetbil",
      "expires_at": "2026-10-14T12:15:00Z",
      "quote_token": "a1b2c3d4...",
      "purchasable": true,
      "breakdown": {
        "base_price": 7500.0,
        "gateway_fee": 150.0,
        "tax": 0.0,
        "total": 7650.0,
        "currency": "XAF",
        "billed_to_customer": true
      }
    }
  }
  ```

---

### 3.3. `POST /api/payments/initiate`
Initialise l'intention de paiement, vérifie l'éligibilité SUB et KYC, et déclenche le push USSD sur le téléphone du client.

* **Body JSON :**
  ```json
  {
    "service_type": "subscription",
    "price_id": 1,
    "phone": "699001122",
    "operator": "orange",
    "idempotency_key": "unique_intent_uuid_123"
  }
  ```

* **Réponse de Succès (`201 Created`) :**
  ```json
  {
    "success": true,
    "message": "Demande de paiement envoyée sur le mobile.",
    "data": {
      "id": "12",
      "reference": "BNG-TX-20261014-9K3L8A7Z",
      "kind": "plan",
      "service_type": "subscription",
      "label": "Badge Bailleur Certifié",
      "amount": 7500.0,
      "fee": 150.0,
      "tax": 0.0,
      "totalAmount": 7650.0,
      "creditUsed": 0.0,
      "charged": 7650.0,
      "currency": "XAF",
      "status": "processing",
      "method": {
        "operator": "CM_ORANGEMONEY",
        "label": "Mobile Money (Orange / MTN)",
        "phone": "2376***22"
      },
      "operatorReference": "mb_pay_8917234871239",
      "at": "2026-10-14T12:00:00Z"
    },
    "prompt": {
      "ussd": "#150*50#",
      "message": "#150*50#",
      "timeout_seconds": 120,
      "expires_at": "2026-10-14T12:02:00Z"
    }
  }
  ```

---

### 3.4. `GET /api/payments/{id}/status`
Consultation en temps réel de l'état d'un paiement. Si la transaction est en cours, le serveur interroge automatiquement Monetbil (`checkPayment`).

* **Cas de Confirmation (`200 OK`) :**
  Si le paiement est confirmé chez l'opérateur, le statut passe à `confirmed` et l'abonnement SUB est activé de manière atomique.
  ```json
  {
    "success": true,
    "data": {
      "id": "12",
      "reference": "BNG-TX-20261014-9K3L8A7Z",
      "status": "confirmed",
      "charged": 7650.0,
      "operatorReference": "MP260314.0942.C41773",
      "confirmedAt": "2026-10-14T12:00:45Z",
      "subscription_id": 4
    }
  }
  ```

---

### 3.5. `POST /api/payments/{id}/cancel`
Annule une transaction en attente (`processing`) à l'initiative de l'utilisateur (`PAY-12`). Une transaction confirmée ne peut pas être annulée.

---

### 3.6. `GET /api/payments/history`
Historique paginé et filtrable des transactions personnelles :
* `status` : `pending`, `confirmed`, `failed`, `cancelled`
* `service_type` : `subscription`, `certification`, `boost`, `visit`
* `filter` : `all`, `plan`, `boost`, `credit` (conforme `PAY-21`)
* `from` / `to` : bornes temporelles ISO
* `per_page` : nombre d'éléments par page (défaut 15, min 1, max 50)
