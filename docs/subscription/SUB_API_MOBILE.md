# BANGO — Spécification des API Mobiles Module SUB

Ce document détaille l'intégralité des contrats d'API du module **SUB** consommés par l'application mobile React Native / Expo (`app/subscriptions.tsx`, `app/badges.tsx`, `app/plan-change.tsx`, `app/cancel-subscription.tsx`).

Toutes les requêtes utilisent les en-têtes standards :
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <passport_access_token>
```

---

## 1. Catalogue Public des Offres

### `GET /api/catalog` (ou `/api/subscriptions/plans`)
- **Accès :** Public / Authentifié
- **Description :** Liste toutes les offres actives du catalogue avec leurs tarifs valides.
- **Réponse Succès (`200 OK`) :**
```json
{
  "data": [
    {
      "id": 1,
      "code": "landlord",
      "name": "Badge Bailleur Certifié",
      "description": "Diffusez vos biens en direct sans intermédiaire et certifiez votre statut de bailleur.",
      "badgeType": "landlord",
      "targetRole": "landlord",
      "features": [
        "Anneau vert officiel Bailleur sur votre profil",
        "Publication illimitée de vos annonces de biens",
        "Réception directe des contacts locataires qualifiés",
        "Badge de confiance vérifié"
      ],
      "isActive": true,
      "displayOrder": 1,
      "prices": [
        {
          "id": 1,
          "productId": 1,
          "cadence": "monthly",
          "priceAmount": 7500,
          "priceXaf": 7500,
          "currency": "XAF",
          "durationDays": 30,
          "savingsDescription": null,
          "isActive": true
        },
        {
          "id": 2,
          "productId": 1,
          "cadence": "yearly",
          "priceAmount": 75000,
          "priceXaf": 75000,
          "currency": "XAF",
          "durationDays": 365,
          "savingsDescription": "Économisez 15 000 FCFA (2 mois offerts)",
          "isActive": true
        }
      ]
    }
  ]
}
```

### `GET /api/catalog/{code}`
- **Accès :** Public
- **Description :** Détail d'une offre par son code (`landlord`, `agent`, `page_pro`) ou son ID.
- **Réponse Succès (`200 OK`) :** Objet produit unique identique à la structure ci-dessus.
- **Erreur (`404 Not Found`) :**
```json
{
  "kind": "not-found",
  "message": "Cette offre n'existe pas dans le catalogue."
}
```

---

## 2. Abonnements de l'Utilisateur Connecté

### `GET /api/subscriptions`
- **Accès :** Authentifié (`auth:api`)
- **Description :** Retourne la liste de tous les abonnements actuels et passés de l'utilisateur.
- **Réponse Succès (`200 OK`) :**
```json
{
  "data": [
    {
      "id": 1,
      "badge": "landlord",
      "badgeType": "landlord",
      "state": "active",
      "cadence": "monthly",
      "amount": 7500,
      "priceXaf": 7500,
      "currency": "XAF",
      "currentPeriodStart": "2026-10-01T12:00:00Z",
      "currentPeriodEnd": "2026-10-31T12:00:00Z",
      "graceUntil": null,
      "failedAttempts": 0,
      "autoRenew": true,
      "nextRenewal": "2026-10-31T12:00:00Z",
      "canceledAt": null,
      "cancelReason": null,
      "isActive": true,
      "isInGrace": false,
      "isUsable": true,
      "isExpired": false
    }
  ]
}
```

### `GET /api/subscriptions/{id}`
- **Accès :** Authentifié (`auth:api`)
- **Description :** Détail d'un abonnement spécifique de l'utilisateur.
- **Erreurs :** `404 Not Found` si l'abonnement n'appartient pas à l'utilisateur.

---

## 3. Statut des Badges & Anneaux de Profil

### `GET /api/subscriptions/status` (ou `/api/badges/status`)
- **Accès :** Authentifié (`auth:api`)
- **Description :** Fournit le statut d'attribution des badges, la couleur de l'anneau d'avatar et l'éligibilité KYC.
- **Réponse Succès (`200 OK`) :**
```json
{
  "status": {
    "badge": "double",
    "badges": ["Bailleur", "Agent"],
    "ringColor": "#D80F0F",
    "isVerified": true,
    "verificationLapsed": false,
    "hasLandlord": true,
    "hasAgent": true,
    "inGrace": false
  },
  "subscriptions": [
    {
      "id": 1,
      "badge": "landlord",
      "state": "active"
    },
    {
      "id": 2,
      "badge": "agent",
      "state": "active"
    }
  ]
}
```

---

## 4. Tunnel de Souscription (Préparation pour PAY)

### `POST /api/subscriptions/subscribe`
- **Accès :** Authentifié (`auth:api`)
- **Description :** Prépare une demande de souscription, valide l'éligibilité et génère l'intention de paiement transmise à PAY.
- **Corps de la Requête (Payload) :**
```json
{
  "price_id": 2,
  "idempotency_key": "uuid-v4-unique-sub-request"
}
```
*(Ou alternativement : `{"code": "landlord", "cadence": "yearly"}`)*
- **Réponse Succès (`200 OK`) :**
```json
{
  "success": true,
  "data": {
    "product": {
      "id": 1,
      "code": "landlord",
      "name": "Badge Bailleur Certifié",
      "badge_type": "landlord"
    },
    "price": {
      "id": 2,
      "cadence": "yearly",
      "amount": 75000,
      "currency": "XAF",
      "duration_days": 365
    },
    "payment_intent": {
      "purpose": "subscription_landlord",
      "amount": 75000,
      "currency": "XAF",
      "idempotency_key": "uuid-v4-unique-sub-request"
    },
    "kyc_status": {
      "is_verified": true,
      "verification_lapsed": false,
      "warning": null
    }
  }
}
```
- **Erreurs Possibles :**
  - `422 Unprocessable Content` : Si l'utilisateur possède déjà un abonnement actif pour ce badge.
  - `401 Unauthorized` : Si aucun Bearer token valide n'est fourni.

---

## 5. Changement de Cadence (Mensuel $\leftrightarrow$ Annuel)

### `POST /api/subscriptions/{id}/change-plan`
- **Accès :** Authentifié (`auth:api`)
- **Corps de la Requête :**
```json
{
  "new_cadence": "yearly"
}
```
- **Réponse Succès (`200 OK`) :**
```json
{
  "success": true,
  "data": {
    "current_subscription_id": 1,
    "current_cadence": "monthly",
    "new_cadence": "yearly",
    "current_amount": 7500,
    "new_amount": 75000,
    "remaining_days": 18,
    "prorata_credit_xaf": 4500,
    "amount_to_pay_xaf": 70500,
    "currency": "XAF"
  }
}
```

---

## 6. Résiliation d'Abonnement (Churn & Rétention)

### `POST /api/subscriptions/{id}/cancel`
- **Accès :** Authentifié (`auth:api`)
- **Corps de la Requête :**
```json
{
  "reason": "Logement loué",
  "feedback": "Très satisfait, je reviendrai lors de la prochaine mise en location."
}
```
- **Réponse Succès (`200 OK`) :**
```json
{
  "message": "Abonnement résilié avec succès. Vos avantages restent valables jusqu'au 31/10/2026.",
  "auto_renew": false,
  "expires_at": "2026-10-31T12:00:00Z"
}
```

---

## 7. Historique des Badges de l'Utilisateur

### `GET /api/badges/history`
- **Accès :** Authentifié (`auth:api`)
- **Description :** Historique chronologique des attributions, suspensions et réactivations de badges pour l'utilisateur connecté.
- **Réponse Succès (`200 OK`) :**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 42,
      "badge": "landlord",
      "event": "activated",
      "source": "subscription",
      "reason": "Activation de l'abonnement Badge Bailleur Certifié",
      "created_at": "2026-10-01T12:00:00Z"
    }
  ]
}
```
