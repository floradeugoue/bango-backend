# BANGO — Guide d'Intégration Future : SUB (Badges & Abonnements) & PAY (Paiements)

Ce document prépare l'intégration technique et fonctionnelle entre le module **PR (Profil)**, le futur module **SUB (Abonnements & Badges)** et le module **PAY (Paiements Mobile Money)** sans implémenter prématurément le code de facturation.

---

## 1. Principes Fondamentaux d'Architecture

1. **Source Unique d'Autorité (Single Source of Truth) :**
   - Le backend est la seule autorité sur l'état d'un badge ou d'un abonnement.
   - Le frontend ne peut en aucun cas forcer l'affichage ou l'activation d'un badge via une modification de son état local ou de ses paramètres de stockage (`AsyncStorage`).

2. **Séparation Stricte des Responsabilités :**
   - **PR (Profil) :** Stocke et expose l'identité utilisateur, ses relations sociales et reflète passivement le badge actif dans les DTO/Resources (`UserResource`, `PublicProfileResource`). PR ne gère ni les prix, ni les factures, ni les renouvellements.
   - **SUB (Abonnements & Badges) :** Possède le cycle de vie des souscriptions (actif, suspendu, période de grâce, expiré), la gestion des forfaits/tarifs et la vérification de l'éligibilité documentaire (KYC).
   - **PAY (Paiements) :** Orchestre les transactions Mobile Money (Orange Money, MTN MoMo), les webhooks partenaires, l'idempotence et les reçus de paiement. PAY notifie SUB du succès d'une transaction via des événements de domaine ou un service dédié.

---

## 2. Ce que PR fournit à SUB

Pour que SUB fonctionne, PR met à disposition les éléments suivants :

1. **Identité et Statut Utilisateur :**
   - `User::id` (UUID / Clé primaire).
   - Statut du compte (`status = active, paused, pending`).
   - Nom complet légal (`first_name`, `last_name`) et téléphone (`phone`) nécessaires pour la souscription et la facturation.
2. **Données de Vérification et KYC :**
   - L'état de validation de l'identité légale (`is_verified`, `verification_lapsed`).
   - Le quartier ou la localisation de référence de l'utilisateur (`location`, `commune`).
3. **Point d'injection du badge actif :**
   - La colonne `users.badge` et l'accesseur `User::getActiveBadgesAttribute()` que PR sérialise dans `badges: string[]` pour l'affichage profil (`PublicProfileResource` et `UserResource`).

---

## 3. Ce que PR attend de SUB

1. **Résolution du Badge en temps réel :**
   - SUB exposera un contrat de service (ex: `SubscriptionServiceInterface` ou `BadgeResolutionService`) qui fournira la liste des badges valides pour un utilisateur donné :
     ```php
     interface BadgeServiceInterface
     {
         /**
          * Retourne la liste des badges actifs pour l'utilisateur.
          * Prend en compte la validité de l'abonnement, la période de grâce et le KYC.
          *
          * @return array<string> Ex: ['landlord'], ['agent'], ['landlord', 'agent'], ['pro_verified']
          */
         public function getActiveBadgesForUser(User $user): array;
     }
     ```
2. **Événements de cycle de vie :**
   - Quand un abonnement change d'état (`SubscriptionActivated`, `SubscriptionExpired`, `GracePeriodEntered`, `SubscriptionCancelled`), SUB mettra à jour la colonne cache `users.badge` ou déclenchera un event écouté par PR pour rafraîchir l'état du profil.
3. **Vérification d'expiration de KYC :**
   - Si la pièce d'identité requise pour un rôle (Bailleur ou Agent) expire, SUB bascule l'état en `verification_lapsed = true` et suspend temporairement les privilèges associés.

---

## 4. Affichage des Badges sur les Profils (Spécifications Frontend BANGO)

D'après l'analyse des composants frontend (`lib/badges.ts`, `app/badges.tsx`, `app/account.tsx`, `app/(tabs)/profile.tsx`) :

### 4.1. Catalogue des Badges et Règles Métier

| Type de Badge | Code Identifiant | Tarification Frontend | Période de Grâce | Règle d'anneau visuel |
| :--- | :--- | :--- | :--- | :--- |
| **Bailleur** | `landlord` (ou `bailleur`) | 7 500 FCFA/mois ou 75 000 FCFA/an (2 mois offerts) | 7 jours après échec de paiement | Anneau simple de couleur Bailleur |
| **Agent** | `agent` | 3 500 FCFA/mois ou 35 000 FCFA/an | 7 jours après échec de paiement | Anneau simple de couleur Agent |
| **Double Badge** | `landlord` + `agent` | Cumul des abonnements respectifs | Selon chaque souscription | **Anneau rouge signature BANGO** |
| **Certification Page Pro** | `pro_verified` (ou `certified`) | 15 000 FCFA (frais uniques d'examen documentaire) | N/A (validité permanente ou annuelle liée au KYC) | Coche de certification officielle |

### 4.2. Intégration dans `PublicProfileResource` et `UserResource`

Le module PR est déjà configuré pour formater les badges comme attendu par l'application :
```json
{
  "badges": ["landlord", "agent"],
  "badge": "landlord",
  "verificationLapsed": false
}
```
Quand un profil possède à la fois `landlord` et `agent`, le frontend déclenche automatiquement le rendu de l'anneau rouge BANGO sur l'avatar.

---

## 5. Communication entre PAY et SUB (Sans Duplication)

Le flux de paiement suit une architecture événementielle unidirectionnelle :

```
[Mobile App / Web]
       │
       ▼
1. Initier Paiement (SUB demande à PAY)
       │
       ▼
[Module PAY] ──(API Orange / MTN)──► [Opérateur Mobile Money]
       │                                     │
       │◄──────── Webhook / Callback ────────┘
       ▼
2. Validation Transaction (PAY met à jour Transaction::status = SUCCESS)
       │
       ▼
3. Dispatch Event: PaymentSucceeded(userId, reference, amount, metadata: { subscription_plan: 'landlord_annual' })
       │
       ▼
[Listener SUB]
       │
       ▼
4. Activation / Renouvellement de l'Abonnement dans SUB
       │
       ▼
5. Mise à jour de users.badge et notification utilisateur
```

### Règles d'or :
- **PAY ne connaît pas les règles d'abonnements :** PAY enregistre des paiements avec un montant, un numéro de téléphone, un opérateur et un champ `metadata`.
- **SUB ne gère pas les protocoles bancaires ou Telco :** SUB crée une intention de paiement via PAY et attend la confirmation.
- **Idempotence :** Toutes les notifications webhook de PAY vers SUB doivent inclure la référence de transaction unique pour éviter les doubles crédits de souscription.

---

## 6. Vérification des Droits pour BL (Bailleur) et AG (Agent)

Les modules métier futurs (BL pour la gestion locative / bailleurs, AG pour les agents immobiliers, RL pour les annonces) doivent sécuriser leurs actions en vérifiant les droits :

### 6.1. Middleware & Policies d'Autorisation

Créer des middlewares et policies réutilisables dans Laravel :

1. **`EnsureActiveSubscription:landlord` :**
   - Vérifie que `$user->hasActiveSubscription('landlord')`.
   - Autorise l'accès si l'utilisateur est en période de grâce (`in_grace_period = true`), mais bloque si `verification_lapsed = true`.
2. **`EnsureActiveSubscription:agent` :**
   - Vérifie que `$user->hasActiveSubscription('agent')`.
   - Contrôle la validité de la carte professionnelle ou du document KYC d'agent.

### 6.2. Suspension des Actions Soumises au KYC

Si un document KYC expire :
- Le badge reste visible avec un statut avertissement ou est masqué selon la configuration.
- Les actions sensibles (publication d'annonces certifiées, signature de baux numériques, encaissement de loyers) renvoient immédiatement une réponse HTTP `403 Forbidden` :
  ```json
  {
    "code": "KYC_LAPSED",
    "message": "Votre document de vérification a expiré. Veuillez mettre à jour votre pièce d'identité pour réactiver vos actions bailleur."
  }
  ```

---

## 7. Contrats et Endpoints à Créer Ultérieurement

### Module SUB :
- `GET /api/subscriptions/plans` : Liste des forfaits disponibles avec leurs tarifs mensuels et annuels en FCFA.
- `GET /api/subscriptions/current` : Détails de la souscription actuelle de l'utilisateur (date de début, date d'échéance, renouvellement auto, période de grâce).
- `POST /api/subscriptions/subscribe` : Choix d'un forfait et initialisation du paiement.
- `POST /api/subscriptions/cancel` : Résiliation du renouvellement automatique.
- `POST /api/subscriptions/pro-certification` : Dépôt du dossier de certification Page Pro (15 000 FCFA).

### Module PAY :
- `POST /api/payments/momo/initiate` : Lancement du débit USSD / push Orange Money ou MTN MoMo.
- `GET /api/payments/{reference}/status` : Polling du statut de la transaction côté frontend.
- `POST /api/webhooks/payments/{gateway}` : Endpoint sécurisé pour les webhooks opérateurs.

---

## 8. Décisions et Dépendances Futures

1. **Choix de la Passerelle Mobile Money :**
   - Définir l'agrégateur (ex: CinetPay, Notch Pay, Campay, Flutterwave, ou intégration directe API Orange/MTN Cameroun).
2. **Stockage des Justificatifs KYC :**
   - Définir un bucket privé (S3 / R2 sécurisé) pour les pièces d'identité et cartes professionnelles (ne jamais utiliser le disque public des avatars).
3. **Gestion des Relances d'Expiration :**
   - Mettre en place un cron Laravel (`schedule:run`) quotidien pour identifier les abonnements arrivant à échéance à J-7, J-3, J0 et gérer la bascule en période de grâce de 7 jours.
