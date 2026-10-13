# BANGO — Architecture du Module SUB (Abonnements & Badges)

## 1. Vue d'Ensemble & Périmètre

Le module **SUB (Abonnements & Badges)** constitue le moteur de monétisation récurrente, de gestion des offres commerciales et de gouvernance des privilèges de visibilité sur BANGO.

Il est conçu selon les principes de l'architecture hexagonale et du Domain-Driven Design (DDD) au sein du framework Laravel 12+, en garantissant une étanchéité stricte entre :
- La **définition des droits** (SUB)
- L'**encaissement des fonds** (PAY)
- L'**exposition sociale et publique** (PR)
- La **vérification légale** (KYC)

```
┌────────────────────────────────────────────────────────────────────────┐
│                        CLIENTS APPLICATIFS                             │
│       Mobile App (Expo / React Native)   │   Back-Office Admin         │
└───────────────────┬────────────────────────────────────┬───────────────┘
                    │                                    │
                    ▼                                    ▼
┌───────────────────────────────────────┐┌───────────────────────────────┐
│     API MOBILE (/api/subscriptions)   ││   API BACK-OFFICE (/api/admin)│
│  - CatalogController                  ││  - SubscriptionCatalogCtrl   │
│  - SubscriptionController             ││  - SubscriptionManagementCtrl│
└───────────────────┬───────────────────┘└───────────────┬───────────────┘
                    │                                    │
                    └─────────────────┬──────────────────┘
                                      ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        SERVICES MÉTIER DU DOMAINE                      │
│                                                                        │
│  ┌─────────────────────────┐           ┌────────────────────────────┐  │
│  │   SubscriptionService   │           │   BadgeResolutionService   │  │
│  │  - prepareSubscription  │           │  - resolveUserBadges       │  │
│  │  - activateSubscription │◄─────────►│  - syncUserBadge           │  │
│  │  - changePlan (prorata) │           │  - Ring colors calculation │  │
│  │  - cancelSubscription   │           │  - Double badge resolution │  │
│  │  - enterGracePeriod     │           └─────────────┬──────────────┘  │
│  │  - expireSubscription   │                         │                 │
│  └───────────┬─────────────┘                         │                 │
└──────────────┼───────────────────────────────────────┼─────────────────┘
               │                                       ▼
               ▼                         ┌───────────────────────────────┐
┌──────────────────────────────────────┐ │      MODULE PROFIL (PR)       │
│      PERSISTANCE POSTGRESQL          │ │  - UserResource               │
│  - subscription_products             │ │  - PublicProfileResource      │
│  - subscription_prices               │ │  (Reflète passivement users.  │
│  - subscriptions                     │ │   badge sans logique de prix) │
│  - subscription_events               │ └───────────────────────────────┘
│  - badge_histories                   │
└──────────────────────────────────────┘
```

---

## 2. Découpage des Responsabilités

| Composant | Rôle & Responsabilité | Dépendances |
| :--- | :--- | :--- |
| **`SubscriptionProduct` & `SubscriptionPrice`** | Entités du catalogue commercial. Permettent de versionner les tarifs historiques sans impacter les abonnements déjà souscrits. | Aucune |
| **`Subscription`** | Entité centrale du contrat récurrent entre un utilisateur et un produit. Porte la cadence, l'état, les dates de période et le renouvellement automatique. | `User`, `SubscriptionProduct`, `SubscriptionPrice` |
| **`SubscriptionEvent`** | Journal d'audit append-only de toutes les transitions d'état d'un abonnement avec acteur (`user`, `admin`, `system`, `pay`). | `Subscription`, `User` |
| **`BadgeHistory`** | Traçabilité légale de l'attribution, suspension, réactivation ou révocation des badges utilisateur. | `User` |
| **`BadgeResolutionService`** | Moteur de calcul d'autorité déterminant le badge effectif et la couleur de l'anneau visuel selon la conjonction : Abonnement actif + KYC approuvé. | `Subscription`, `User`, `BadgeHistory` |
| **`SubscriptionService`** | Orchestrateur du cycle de vie des abonnements (souscription, prorata temporis, relances de grâce, résiliation, suspensions admin). | `BadgeResolutionService`, `Subscription`, `SubscriptionEvent` |
| **`EnsureActiveSubscription`** | Middleware de garde pour les routes métier des futurs modules (`BL` pour bailleurs, `AG` pour agents immobiliers). | `BadgeResolutionService`, `User` |

---

## 3. Séparation des Domaines : SUB vs PAY vs PR vs KYC

1. **SUB ne gère aucun paiement direct :**
   - SUB ne contient aucun code d'intégration Orange Money, MTN MoMo, Wave ou Stripe.
   - SUB reçoit des notifications de paiement confirmé provenant de PAY (ou via `SubscriptionService::activateSubscription()`) avec une référence de transaction unique.
2. **PR ne gère aucun abonnement ni tarif :**
   - PR expose l'état visuel du profil (`badge`, `badges`, `verified`, `ringColor`).
   - PR ne connaît ni les prix, ni les proratas, ni les dates de fin d'abonnement.
3. **Le paiement n'accorde pas automatiquement le badge (Règle KYC) :**
   - Un utilisateur ayant payé un forfait Bailleur conserve un statut de badge `none` tant que son identité légale (`is_verified`) n'est pas validée par l'équipe de conformité.
   - Si les documents KYC expirent en cours d'abonnement (`verification_lapsed = true`), les privilèges et l'anneau sont suspendus.

---

## 4. Sécurité & Authentification

- **Guard d'authentification :** `auth:api` (Laravel Passport / OAuth2 Bearer Tokens).
- **Isolation des données :** Un utilisateur ne peut accéder ou agir que sur ses propres abonnements (`user_id = auth()->id()`).
- **Contrôle d'accès Back-Office :** Toutes les routes d'administration du catalogue et de supervision sont protégées par le middleware `admin` (`CheckAdminRole`), limitant l'accès aux comptes dotés du rôle système `admin`.
