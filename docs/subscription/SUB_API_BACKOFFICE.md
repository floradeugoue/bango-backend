# BANGO — Spécification des API Back-Office Module SUB

Ce document détaille les points de terminaison REST réservés au panneau d'administration de BANGO (Back-Office Laravel).

Toutes les routes de ce périmètre exigent une double protection :
```http
Authorization: Bearer <admin_passport_token>
```
Validée par les middlewares : `auth:api` et `admin` (`CheckAdminRole`).

---

## 1. Administration du Catalogue

### `GET /api/admin/catalog/products`
- **Description :** Liste exhaustive de tous les produits (actifs et inactifs) avec l'ensemble de leurs tarifs.
- **Réponse (`200 OK`) :** Tableau JSON de `SubscriptionProductResource`.

### `POST /api/admin/catalog/products`
- **Description :** Création d'une nouvelle offre de produit.
- **Payload :**
```json
{
  "code": "landlord_vip",
  "name": "Badge Bailleur VIP",
  "description": "Offre avec conciergerie incluse",
  "badge_type": "landlord",
  "target_role": "landlord",
  "features": ["Visibilité maximale", "Accompagnement juridique"],
  "is_active": true,
  "display_order": 5
}
```
- **Réponse (`201 Created`) :** Produit créé.

### `GET /api/admin/catalog/products/{id}`
- **Description :** Consultation du produit avec son historique complet de prix.

### `PUT /api/admin/catalog/products/{id}`
- **Description :** Mise à jour des informations d'une offre (nom, description, activation/désactivation, ordre d'affichage).

### `POST /api/admin/catalog/products/{id}/prices`
- **Description :** Ajout d'un nouveau palier tarifaire pour un produit.
- **Payload :**
```json
{
  "cadence": "yearly",
  "price_amount": 90000,
  "duration_days": 365,
  "currency": "XAF",
  "savings_description": "2 mois offerts",
  "is_active": true,
  "effective_from": "2026-11-01T00:00:00Z"
}
```

### `PUT /api/admin/catalog/prices/{priceId}`
- **Description :** Modification d'un tarif existant (ajustement de montant, désactivation, date de fin d'effet `effective_until`).

---

## 2. Supervision & Gestion des Abonnements

### `GET /api/admin/subscriptions`
- **Description :** Liste paginée des abonnements avec recherche multi-critères.
- **Paramètres Query :**
  - `?badge_type=landlord` : Filtre par badge (`landlord`, `agent`)
  - `?state=active` : Filtre par état (`active`, `grace`, `failed`, `expired`, `suspended`)
  - `?cadence=monthly` : Filtre par cadence (`monthly`, `yearly`)
  - `?user_id=12` : Filtre par utilisateur
  - `?q=dupont` : Recherche textuelle sur le nom, email, téléphone ou @handle
  - `?per_page=20` : Pagination
- **Réponse (`200 OK`) :**
```json
{
  "data": [
    {
      "id": 1,
      "badge": "landlord",
      "state": "active",
      "cadence": "monthly",
      "priceXaf": 7500,
      "currentPeriodEnd": "2026-10-31T12:00:00Z",
      "user": {
        "id": 12,
        "handle": "m.dupont",
        "name": "Marc Dupont",
        "email": "m.dupont@example.com",
        "phone": "+237699112233",
        "isVerified": true
      }
    }
  ]
}
```

### `GET /api/admin/subscriptions/{id}`
- **Description :** Détail complet de l'abonnement incluant l'historique chronologique de tous ses événements (`events`).

---

## 3. Actions Administratives Sécurisées

Toutes les actions d'administration sensible exigent obligatoirement un motif textuel (`reason`) qui est persisté dans le journal d'audit `subscription_events` avec l'ID de l'administrateur connecté.

### `POST /api/admin/subscriptions/{id}/suspend`
- **Description :** Suspension administrative immédiate d'un abonnement (ex: fraude ou fausse annonce détectée).
- **Payload :**
```json
{
  "reason": "Signalement d'annonce frauduleuse en cours d'enquête"
}
```
- **Effets :**
  - `state` passe à `suspended`
  - Le badge de l'utilisateur est immédiatement révoqué via `BadgeResolutionService`
  - Un événement d'audit `suspended` est enregistré

### `POST /api/admin/subscriptions/{id}/reactivate`
- **Description :** Rétablissement d'un abonnement suspendu suite à vérification concluante.
- **Payload :**
```json
{
  "reason": "Enquête terminée, conformité vérifiée"
}
```

### `POST /api/admin/subscriptions/{id}/extend`
- **Description :** Prolongation de la période en cours (geste commercial ou compensation).
- **Payload :**
```json
{
  "days": 15,
  "reason": "Geste commercial suite à indisponibilité technique temporaire"
}
```

### `POST /api/admin/subscriptions/{id}/revoke`
- **Description :** Révocation définitive de l'abonnement et du badge associé.
- **Payload :**
```json
{
  "reason": "Révocation définitive pour récidive de manquement aux CGU"
}
```

---

## 4. Journal d'Audit des Badges

### `GET /api/admin/badges/history`
- **Description :** Consultation du journal global de tous les événements de badges de la plateforme.
- **Paramètres Query :** `?badge=`, `?event=`, `?user_id=`, `?per_page=`.
- **Réponse (`200 OK`) :** Liste paginée avec détails de l'utilisateur et de l'acteur responsable.
