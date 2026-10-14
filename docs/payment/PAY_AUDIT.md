# AUDIT TECHNIQUE PRÉALABLE À L'INTÉGRATION DU MODULE PAY (PAIEMENTS) — BANGO

**Date de réalisation :** 14 Octobre 2026  
**Auteur :** Antigravity AI Engineering  
**Application cible :** BANGO Backend (`bango_backend`)  
**Frontend associé :** BANGO Mobile Expo / React Native (`C:\Users\DEUGOUE Floriane\Documents\GitHub\Bango`)  
**Stack technique :** Laravel 12 (PHP 8.4), PostgreSQL 16+, Laravel Passport (`auth:api`), Pest PHP  
**Première passerelle ciblée :** Monetbil (Cameroun, zone CEMAC / Afrique centrale & UEMOA)

---

## SOMMAIRE EXÉCUTIF

1. [Contexte, Périmètre & Règles d'Or](#1-contexte-périmètre--règles-dor)
2. [État des Lieux du Backend BANGO (`SUB` & Dépendances)](#2-état-des-lieux-du-backend-bango-sub--dépendances)
3. [Analyse Détaillée des Contrats & Parcours Frontend Mobile](#3-analyse-détaillée-des-contrats--parcours-frontend-mobile)
4. [Spécifications Techniques de l'Intégration Monetbil](#4-spécifications-techniques-de-lintégration-monetbil)
5. [Architecture de Données Proposée (Schéma PostgreSQL PAY)](#5-architecture-de-données-proposée-schéma-postgresql-pay)
6. [Contrats d'Interface & Routes API](#6-contrats-dinterface--routes-api)
7. [Architecture Multi-Passerelles & Évolutivité (Flutterwave, Apple IAP, Google Play)](#7-architecture-multi-passerelles--évolutivité)
8. [Matrice des Risques, Sécurité & Décisions Métier à Arbitrer](#8-matrice-des-risques-sécurité--décisions-métier-à-arbitrer)
9. [Plan d'Implémentation Étape par Étape](#9-plan-dimplémentation-étape-par-étape)
10. [Conclusion de l'Audit : Ce qui est prêt, Ce qui manque & Feuille de Route](#10-conclusion-de-laudit)

---

## 1. CONTEXTE, PÉRIMÈTRE & RÈGLES D'OR

### 1.1. Contexte du Projet
BANGO est une plateforme sociale et immobilière panafricaine opérant dans 9 pays d'Afrique subsaharienne (Sénégal, Cameroun, Côte d'Ivoire, Gabon, Bénin, Togo, Ghana, Nigeria, Kenya). 
Le backend est architecturé en modules autonomes et découplés :
* **PR (Profil) :** Identités, conformité KYC, réseaux d'abonnés, réputation.
* **SUB (Abonnements & Badges) :** Catalogue commercial, gestion des droits, durées de validité, périodes de grâce et badges utilisateurs.
* **PAY (Paiements & Transactions) :** Encaissement multidevise, transactions financières, agrégateurs Mobile Money, portefeuilles de crédit et journalisation comptable.

### 1.2. Règles d'Or et Contraintes Strictes de cet Audit
* **Sécurité des identifiants :** Aucun secret Monetbil (`SERVICE_KEY`, `SERVICE_SECRET`) n'est affiché ni consigné dans la documentation ou le code source. Ils résident exclusivement dans l'environnement serveur non commité (`.env`).
* **Non-altération du code en phase d'audit :** Aucun fichier existant n'est modifié, aucune migration n'est exécutée lors de cet audit.
* **Indépendance de la preuve de paiement :** Un retour navigateur (`return_url`), une redirection d'application ou un simple re-rendu d'écran ne constituent **JAMAIS** une preuve de paiement. Seule une notification serveur à serveur (`notify_url`) signée cryptographiquement et validée par une contre-interrogation directe auprès de l'API Monetbil (`checkPayment`) autorise la confirmation d'une transaction.
* **Activation différée et garantie :** Aucun abonnement, aucun badge, aucun boost ni aucune certification ne sont activés avant la certitude absolue de la réussite du paiement.
* **Découplage strict PAY $\leftrightarrow$ SUB :** Le module PAY gère l'argent, les opérateurs télécoms et les passerelles. Le module SUB gère les privilèges, les dates d'échéance et les badges. Aucun ne s'immisce dans les structures internes de l'autre.

---

## 2. ÉTAT DES LIEUX DU BACKEND BANGO (`SUB` & DÉPENDANCES)

### 2.1. Ce qui est Déjà Développé et Testé dans `SUB`
Le module **SUB** est intégralement développé, fonctionnel et couvert par une suite de **110 tests Pest** :
* **Catalogue d'offres :**
  * `SubscriptionProduct` (`subscription_products`) : Définition des produits (`landlord`, `agent`, `page_pro`).
  * `SubscriptionPrice` (`subscription_prices`) : Grilles tarifaires en FCFA avec cadences (`monthly`, `yearly`, `one_off`).
* **Gestion des abonnements :**
  * `Subscription` (`subscriptions`) : Machine à états (`pending_payment`, `active`, `grace`, `failed`, `expired`, `canceled`, `suspended`).
  * `SubscriptionEvent` (`subscription_events`) : Traçabilité immuable de chaque événement d'abonnement.
* **Gestion des badges :**
  * `BadgeHistory` (`badge_histories`) : Journal d'attribution et de révocation des badges.
  * `BadgeResolutionService` : Résolution dynamique de l'anneau de profil (`users.badge` et codes couleur hexadécimaux officiels : Vert `#1E9E5A`, Orange `#E69900`, Rouge `#D80F0F`).
* **Middleware de contrôle d'accès :**
  * `EnsureActiveSubscription` : Interception des routes protégées par abonnement actif ou en période de grâce.
* **Contrôleurs et Routes API :**
  * Mobile : `GET /api/subscriptions`, `GET /api/subscriptions/plans`, `GET /api/subscriptions/status`, `POST /api/subscriptions/subscribe`, `POST /api/subscriptions/{id}/change-plan`, `POST /api/subscriptions/{id}/cancel`.
  * Back-office : `admin/catalog/products`, `admin/catalog/prices`, `admin/subscriptions/*`, `admin/badges/history`.

### 2.2. Le Pont Prévu entre `SUB` et `PAY`
Dans `app/Services/Subscription/SubscriptionService.php`, deux méthodes clés ont été spécifiquement conçues pour l'intégration de PAY :
1. **`prepareSubscription(User $user, SubscriptionPrice $price, array $payload): array`** :
   * Valide l'éligibilité de l'utilisateur (pas de doublon d'abonnement actif pour le même badge, offre effective).
   * Génère un objet `payment_intent` contenant :
     * `purpose` : `"subscription_landlord"` ou `"subscription_agent"`.
     * `amount` : Montant exact du tarif.
     * `currency` : Devise (ex: `"XAF"`).
     * `idempotency_key` : Jeton d'idempotence UUID.
2. **`activateSubscription(User $user, SubscriptionPrice $price, string $paymentReference, array $metadata = []): Subscription`** :
   * Méthode transactionnelle (`DB::transaction`) appelée par PAY une fois le paiement confirmé.
   * Calcule les dates (`current_period_start = now()`, `current_period_end = now() + duration_days`).
   * Réinitialise les compteurs de grâce et d'échecs.
   * Enregistre l'événement dans `subscription_events`.
   * Déclenche la mise à jour immédiate du badge via `BadgeResolutionService`.
3. **`enterGracePeriod(Subscription $subscription, ?string $reason = null)`** :
   * Bascule l'abonnement en statut `grace` pour 7 jours (`grace_until = now() + 7 days`).
   * Maintient le badge actif pour éviter la dépublication abrupte des annonces.
4. **`expireSubscription(Subscription $subscription)`** :
   * Clôture définitivement l'abonnement à $J+7$ sans régularisation et retire le badge.

### 2.3. Ce qui Manque Actuellement dans le Backend
* **Aucun schéma de données PAY :** Aucune table `transactions`, `payment_attempts`, `credit_wallets` ou `credit_movements` n'existe dans la base PostgreSQL.
* **Aucune route de paiement :** Aucune route pour initier une transaction, interroger le statut USSD, ni recevoir un webhook.
* **Aucun client d'intégration Monetbil :** Pas de classe de service, pas de gestionnaire d'appels HTTP, pas de calcul de signature HMAC/MD5.
* **Absence du portefeuille de Crédit BANGO :** La colonne `credit_balance_xaf` n'est pas encore présente sur la table `users`.

---

## 3. ANALYSE DÉTAILLÉE DES CONTRATS & PARCOURS FRONTEND MOBILE

L'audit exhaustif du code mobile (`C:\Users\DEUGOUE Floriane\Documents\GitHub\Bango\lib\api\payments.ts`, `app/payment*.tsx`, `docs/AUDIT_SUB_PAY_BACKEND.md`) révèle des exigences contractuelles extrêmement précises :

### 3.1. Les 4 Motifs de Paiement (`PaymentPurpose`)
Le frontend déclenche des paiements pour quatre contextes distincts :
1. **`plan` (Abonnement Badge) :**
   * Badges : `landlord` (Bailleur) ou `agent` (Agent immobilier).
   * Cadence : `monthly` (Mensuel : 7 500 / 3 500 FCFA) ou `yearly` (Annuel : 75 000 / 35 000 FCFA avec 2 mois offerts).
   * Action post-succès : activation ou renouvellement dans le module `SUB`.
2. **`certification` (Certification de Page Professionnelle) :**
   * Frais uniques d'examen de conformité légale (RCCM, CNI gérant) : **15 000 FCFA**.
   * Inclut contractuellement jusqu'à 2 corrections gratuites en cas de rejet initial.
3. **`boost` (Campagne publicitaire / Mise en avant) :**
   * Montant variable de **2 000 FCFA** à **50 000 FCFA** (pas de 500 FCFA), CPM indexé sur 2 180 FCFA pour 1 000 impressions.
4. **`visit` (Réservation de visite immobilière) :**
   * Frais de visite fixés par l'annonce (ex: 2 000 FCFA) bloquant le créneau.

### 3.2. Le Tunnel Utilisateur et les Écrans Mobile Associés
Le flux mobile est découpé en étapes strictes :
```
[PAY-01: payment.tsx] Récapitulatif commande & Choix d'imputer le Crédit BANGO
         │
         ▼
[PAY-02 / PAY-03: payment-method.tsx] Choix de l'opérateur selon le pays (MTN, Orange, Wave...)
         │
         ▼
[PAY-04 / PAY-05: payment-number.tsx] Saisie du numéro Mobile Money (Validation regex préfixe + longueur)
         │
         ▼
[PAY-06 / PAY-07: payment-pending.tsx] Envoi de l'ordre, Push USSD, Compte à rebours 120s
         │
         ├───────────────────────────────────┬───────────────────────────────────┐
         ▼                                   ▼                                   ▼
[PAY-08: payment-result]            [PAY-09/10/11: Échecs]             [EDGE-19: payment-unknown]
Succès : Reçu BNG-...,              Refus (402), Timeout (120s),       Coupure réseau pendant validation :
Référence opérateur (MP...),         Solde insuffisant (51),            Polling différé (5 essais max),
Activation immédiate.               Annulation manuelle (PAY-12).      Zéro re-débit non sollicité.
```

### 3.3. Règles Critiques du Frontend à Respecter Impérativement
1. **Le code secret (PIN) ne transite JAMAIS par l'application :**
   * L'utilisateur valide la transaction exclusivement via le push USSD ou l'application de son opérateur sur son propre terminal.
2. **Gestion de l'Idempotence (`EDGE-17`) :**
   * Le frontend envoie une clé `key` d'idempotence (`${kind}:${ref}:${amount}:${currency}:${phone}`).
   * Toute tentative identique soumise dans une fenêtre de **10 minutes** doit renvoyer la transaction existante sans réinitier un appel chez l'opérateur.
3. **Gestion des Pertes Réseau (`EDGE-19`, `EDGE-20`) :**
   * Si la connexion coupe pendant le compte à rebours, l'état devient `unknown`.
   * L'application interroge le backend jusqu'à 5 fois (intervalle de quelques secondes) sans jamais relancer un second paiement.
4. **Gestion du Double Débit (`EDGE-18`, `app/double-charge.tsx`) :**
   * Si un utilisateur est débité deux fois suite à un bug réseau opérateur, il saisit les 2 références SMS.
   * **Règle financière stricte :** Le remboursement n'est **JAMAIS renvoyé en Mobile Money**. Il est exclusivement crédité sur le compte sous forme de **Crédit BANGO** non retirable.
5. **Format des Références :**
   * Référence Reçu BANGO : `BNG-YYYY-MM-DD-XXXX` (affiché sur le reçu).
   * Référence Opérateur Télécom : code externe opérateur (ex: `MP260314.0942.C41773` pour MTN MoMo).

---

## 4. SPÉCIFICATIONS TECHNIQUES DE L'INTÉGRATION MONETBIL

Monetbil est la première passerelle d'encaissement Mobile Money déployée sur BANGO.

### 4.1. Architecture de l'API Monetbil
Monetbil offre deux modes d'intégration :
1. **Widget Hosted (`v2.1`) :** Redirection web vers une page hébergée par Monetbil. Inapproprié pour une UX mobile native fluide.
2. **Direct Payment API (`v1/placePayment`) :** Appel d'API server-to-server déclenchant directement le push USSD sur le téléphone du client. **C'est l'approche retenue pour BANGO.**

### 4.2. Paramètres Exacts de la Requête d'Initialisation (`placePayment`)
* **Endpoint :** `POST https://api.monetbil.com/payment/v1/placePayment`
* **Format :** `application/x-www-form-urlencoded` ou `multipart/form-data`
* **Paramètres transmis par le backend BANGO :**
  * `service` : La `SERVICE_KEY` Monetbil (fournie par le dashboard marchand).
  * `phonenumber` : Le numéro de téléphone du client (format international sans le `+` ou format local avec indicatif pays, ex: `237677112233`).
  * `amount` : Montant total à débiter en FCFA.
  * `currency` : `"XAF"` (ou devise locale supportée).
  * `country` : Code pays ISO à 2 lettres (`"CM"` pour le Cameroun).
  * `operator` : Code opérateur optionnel (ex: `"CM_ORANGEMONEY"`, `"CM_MTNMOBILEMONEY"`) permettant de court-circuiter la sélection d'opérateur.
  * `item_ref` : Référence interne BANGO (ex: `"SUB-LANDLORD-123"`).
  * `payment_ref` : Identifiant unique de transaction BANGO (ex: `"tx_01j9x..._bango"`).
  * `user` : Identifiant ou handle de l'utilisateur BANGO.
  * `notify_url` : URL absolue publique du webhook backend (`https://api.bango.africa/api/webhooks/payments/monetbil`).
  * `return_url` : URL de redirection web (non utilisée en pur mobile mais exigée par certains profils).
* **Réponse de Monetbil :**
  ```json
  {
    "status": "REQUEST_ACCEPTED",
    "paymentId": "mb_pay_8917234871239",
    "message": "Payment request has been initiated.",
    "channel_ussd": "*126#"
  }
  ```

### 4.3. Notification Serveur (Webhook IPN) & Vérification Cryptographique de Signature
Lorsqu'un paiement aboutit, échoue ou est annulé par le client, Monetbil envoie une requête HTTP `POST` sur `notify_url`.

* **Paramètres reçus par le webhook :**
  * `status` : `"success"` (ou `1`), `"failed"` (ou `0`), `"cancelled"` (ou `-1`).
  * `paymentId` : Identifiant unique Monetbil de la transaction.
  * `payment_ref` : La référence de transaction transmise lors de l'initiation.
  * `item_ref` : Référence de l'article/service.
  * `amount` : Montant prélevé.
  * `currency` : Devise.
  * `operator` : Nom ou code de l'opérateur (ex: `"orange"`, `"mtn"`).
  * `operator_transaction_id` : Référence transactionnelle de l'opérateur télécom.
  * `phone` : Numéro de téléphone débité.
  * `sign` : Empreinte de sécurité MD5 calculée par Monetbil.

* **Algorithme officiel de validation de la signature `sign` :**
  1. Extraire tous les paramètres de la requête entrante.
  2. Extraire la signature fournie dans `$params['sign']`.
  3. Supprimer le champ `'sign'` du tableau des paramètres.
  4. Trier les clés du tableau par ordre alphabétique strict : `ksort($params)`.
  5. Concaténer la clé secrète marchand `SERVICE_SECRET` suivie de toutes les valeurs des paramètres :
     ```php
     $stringToHash = $serviceSecret . implode('', $params);
     $calculatedSignature = md5($stringToHash);
     ```
  6. Comparer de manière sécurisée (timing-safe) :
     ```php
     if (!hash_equals($calculatedSignature, $incomingSign)) {
         // Signature falsifiée : rejeter immédiatement avec HTTP 403 Forbidden
     }
     ```

### 4.4. Contre-Vérification Systématique via `checkPayment`
Pour se prémunir contre les failles d'usurpation de webhook ou les pertes de paquets réseau, le backend BANGO appliquera le principe de **double vérification** :
* **Endpoint :** `POST https://api.monetbil.com/payment/v1/checkPayment`
* **Body :** `{"paymentId": "<paymentId>"}`
* **Règle absolue :** À la réception du webhook, ou lors d'un appel de polling mobile (`GET /api/payments/{id}/status`), le backend interroge directement Monetbil via `checkPayment` avant de basculer la transaction en `success` et d'invoquer `SubscriptionService::activateSubscription()`.

---

## 5. ARCHITECTURE DE DONNÉES PROPOSÉE (SCHÉMA POSTGRESQL PAY)

Le schéma ci-dessous est conçu selon les standards de l'architecture hexagonale et DDD. Il ne duplique aucune table de `SUB`, mais établit des liens solides d'audit et de traçabilité.

```mermaid
erDiagram
    USERS ||--o{ PAYMENT_TRANSACTIONS : "effectue"
    USERS ||--o{ CREDIT_WALLETS : "possede"
    USERS ||--o{ DOUBLE_CHARGE_CLAIMS : "depose"
    
    PAYMENT_TRANSACTIONS ||--o{ PAYMENT_ATTEMPTS : "comporte"
    PAYMENT_TRANSACTIONS ||--o{ PAYMENT_EVENTS : "genere"
    PAYMENT_TRANSACTIONS ||--o{ CREDIT_MOVEMENTS : "impute"
    PAYMENT_TRANSACTIONS ||--o{ DOUBLE_CHARGE_CLAIMS : "concerne"

    SUBSCRIPTIONS ||--o{ PAYMENT_TRANSACTIONS : "reglee_par"

    PAYMENT_TRANSACTIONS {
        uuid id PK "Identifiant interne BANGO"
        string reference UK "Reference recu (ex: BNG-2026-10-14-1234)"
        uuid user_id FK
        string idempotency_key UK "Garantie anti-doublon"
        string purpose_type "subscription, certification, boost, visit"
        string purpose_id "ID de l'objet achete ou code"
        decimal amount_total "Montant total en devise locale"
        decimal credit_used "Partie reglee via credit BANGO"
        decimal amount_charged "Partie transferee a l'operateur"
        string currency "XAF, XOF, GHS, etc."
        decimal exchange_rate "Taux vers XAF comptable"
        decimal base_amount_xaf "Montant de reference comptable XAF"
        string status "pending, success, refused, insufficient, timeout, cancelled, duplicate, unknown"
        string gateway "monetbil, flutterwave, apple_iap, google_play, credit_only"
        string gateway_transaction_id "ID Monetbil (paymentId)"
        string operator "mtn, orange, wave, etc."
        string operator_code "Code retour operateur (ex: MTN-402)"
        string operator_reference "Reference telecom (ex: MP260314...)"
        string phone_number "Numero MSISDN normalise"
        jsonb metadata "Payload contextuel et options"
        timestamp paid_at "Date de confirmation reelle"
        timestamp failed_at
        timestamp created_at
        timestamp updated_at
    }

    PAYMENT_ATTEMPTS {
        uuid id PK
        uuid transaction_id FK
        string gateway
        string request_type "initiate, status_check, webhook_received"
        jsonb request_payload
        jsonb response_payload
        int http_status
        decimal latency_ms
        timestamp created_at
    }

    PAYMENT_EVENTS {
        uuid id PK
        uuid transaction_id FK
        string event_type "created, prompt_sent, webhook_received, verified, effect_applied, refunded"
        string from_status
        string to_status
        jsonb payload
        timestamp created_at
    }

    CREDIT_WALLETS {
        uuid id PK
        uuid user_id FK UK
        decimal balance_xaf "Solde disponible (non retirable)"
        timestamp updated_at
    }

    CREDIT_MOVEMENTS {
        uuid id PK
        uuid wallet_id FK
        uuid user_id FK
        uuid transaction_id FK "Lien avec un achat ou double debit"
        decimal amount "Positif = credit, Negatif = debit"
        string direction "credit, debit"
        string reason "purchase_deduction, double_charge_refund, goodwill, campaign_stop"
        timestamp created_at
    }

    DOUBLE_CHARGE_CLAIMS {
        uuid id PK
        string ticket_number UK "TICKET-DISPUTE-2026-XXXX"
        uuid user_id FK
        uuid primary_transaction_id FK
        uuid duplicate_transaction_id FK
        string operator_sms_reference
        text user_comment
        string proof_image_path
        string status "pending, approved_credited, rejected"
        timestamp reviewed_at
        uuid reviewer_id FK
        timestamp created_at
    }
```

### 5.1. DDL des Tables Proposées (PostgreSQL)

```sql
-- 1. Table principale des transactions financières
CREATE TABLE payment_transactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    reference VARCHAR(64) UNIQUE NOT NULL, -- Format: BNG-2026-10-14-1234
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    idempotency_key VARCHAR(128) UNIQUE NOT NULL,
    purpose_type VARCHAR(32) NOT NULL, -- subscription, certification, boost, visit
    purpose_id VARCHAR(64) NOT NULL, -- ID entité (ex: price_id, page_id)
    amount_total DECIMAL(12, 2) NOT NULL,
    credit_used DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    amount_charged DECIMAL(12, 2) NOT NULL,
    currency VARCHAR(4) NOT NULL DEFAULT 'XAF',
    exchange_rate DECIMAL(10, 4) NOT NULL DEFAULT 1.0000,
    base_amount_xaf DECIMAL(12, 2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending, success, refused, insufficient, timeout, cancelled, duplicate, unknown
    gateway VARCHAR(32) NOT NULL DEFAULT 'monetbil', -- monetbil, flutterwave, apple_iap, google_play, credit_only
    gateway_transaction_id VARCHAR(128) NULL, -- paymentId Monetbil
    operator VARCHAR(32) NULL, -- mtn, orange, wave
    operator_code VARCHAR(32) NULL, -- MTN-402, MTN-51
    operator_reference VARCHAR(128) NULL, -- MP260314.0942.C41773
    phone_number VARCHAR(32) NULL,
    metadata JSONB NULL,
    paid_at TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payment_tx_user_status ON payment_transactions(user_id, status);
CREATE INDEX idx_payment_tx_gateway_ref ON payment_transactions(gateway, gateway_transaction_id);
CREATE INDEX idx_payment_tx_created_at ON payment_transactions(created_at DESC);

-- 2. Journal technique des appels passerelle (Traçabilité & Débogage)
CREATE TABLE payment_attempts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_id UUID NOT NULL REFERENCES payment_transactions(id) ON DELETE CASCADE,
    gateway VARCHAR(32) NOT NULL,
    request_type VARCHAR(32) NOT NULL, -- initiate, check_payment, webhook
    request_payload JSONB NULL,
    response_payload JSONB NULL,
    http_status INT NULL,
    latency_ms DECIMAL(8, 2) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 3. Journal des événements d'audit immuables
CREATE TABLE payment_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_id UUID NOT NULL REFERENCES payment_transactions(id) ON DELETE CASCADE,
    event_type VARCHAR(64) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NULL,
    payload JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. Portefeuille Crédit BANGO (Avoirs et compensations non retirables)
CREATE TABLE credit_wallets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id BIGINT UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    balance_xaf DECIMAL(12, 2) NOT NULL DEFAULT 0.00 CHECK (balance_xaf >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 5. Mouvements du portefeuille Crédit
CREATE TABLE credit_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    wallet_id UUID NOT NULL REFERENCES credit_wallets(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    transaction_id UUID NULL REFERENCES payment_transactions(id) ON DELETE SET NULL,
    amount DECIMAL(12, 2) NOT NULL,
    direction VARCHAR(8) NOT NULL, -- credit, debit
    reason VARCHAR(64) NOT NULL, -- purchase_deduction, double_charge_refund, goodwill, campaign_stop
    notes TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 6. Signalements de doubles débits (EDGE-18)
CREATE TABLE double_charge_claims (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_number VARCHAR(64) UNIQUE NOT NULL,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    primary_transaction_id UUID NOT NULL REFERENCES payment_transactions(id) ON DELETE RESTRICT,
    duplicate_transaction_id UUID NULL REFERENCES payment_transactions(id) ON DELETE RESTRICT,
    operator_sms_reference VARCHAR(128) NOT NULL,
    user_comment TEXT NULL,
    proof_image_path VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending, approved_credited, rejected
    reviewed_at TIMESTAMPTZ NULL,
    reviewer_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## 6. CONTRATS D'INTERFACE & ROUTES API

### 6.1. Routes Mobile Authentifiées (`middleware: auth:api`)

| Méthode | Route | Description & Rôle |
| :--- | :--- | :--- |
| `POST` | `/api/payments/initiate` | **PAY-06** : Déclenche le paiement. Vérifie l'idempotence, applique le solde Crédit BANGO si demandé, appelle Monetbil (`placePayment`), initialise la transaction en `pending` et renvoie la référence + timeout 120s. |
| `GET` | `/api/payments/{id}/status` | **PAY-07 / EDGE-19** : Polling de statut pendant le compte à rebours de 120s ou en reprise de coupure réseau. Exécute un `checkPayment` auprès de Monetbil si la transaction est toujours en attente. |
| `POST` | `/api/payments/{id}/cancel` | **PAY-12** : Annulation manuelle par l'utilisateur avant confirmation opérateur. |
| `GET` | `/api/payments/history` | **PAY-21** : Historique complet des transactions paginé et groupé par mois (`YYYY-MM`), avec filtres (`all`, `plan`, `boost`, `credit`). |
| `GET` | `/api/payments/{id}` | **PAY-22 / PAY-23** : Détail d'une transaction avec reçu, références BANGO et opérateur télécom. |
| `GET` | `/api/payments/credit` | **PAY-16 / PAY-17** : Consultation du solde Crédit BANGO et de l'historique des mouvements d'avoirs. |
| `POST` | `/api/payments/disputes/double-charge` | **EDGE-18** : Soumission d'une réclamation pour double débit avec ouverture de ticket support. |
| `GET` | `/api/payments/methods` | **PAY-18 à PAY-20** : Liste des portefeuilles et numéros Mobile Money enregistrés du client. |
| `POST` | `/api/payments/methods` | Enregistrement d'un nouveau moyen de paiement favori. |
| `DELETE` | `/api/payments/methods/{id}` | Suppression d'un moyen de paiement enregistré. |

### 6.2. Route Webhook Publique Sécurisée (`notify_url`)

| Méthode | Route | Sécurité & Contrôle |
| :--- | :--- | :--- |
| `POST` | `/api/webhooks/payments/monetbil` | **Public mais protégé** : Contrôle de signature MD5 timing-safe avec `SERVICE_SECRET`. Interdiction de traitement sans signature valide. Répond `200 OK` avec `"status": "success"` ou `"status": "received"`. Déclenche en arrière-plan la vérification directe (`checkPayment`) et l'activation du produit dans `SUB`. |

### 6.3. Routes Back-office Administration (`middleware: ['auth:api', 'admin']`)

| Méthode | Route | Description |
| :--- | :--- | :--- |
| `GET` | `/api/admin/payments/transactions` | Recherche multi-critères, filtres par passerelle, statut, période, client ou référence opérateur. |
| `GET` | `/api/admin/payments/disputes` | File d'attente de modération des réclamations de doubles débits. |
| `POST` | `/api/admin/payments/disputes/{id}/resolve` | Validation du double débit avec crédit automatique du portefeuille BANGO sous 72h max. |
| `POST` | `/api/admin/payments/credit/adjust` | Ajustement manuel du solde Crédit BANGO d'un utilisateur avec motif obligatoire. |

---

## 7. ARCHITECTURE MULTI-PASSERELLES & ÉVOLUTIVITÉ

L'application doit pouvoir migrer de Monetbil vers **Flutterwave** (ou un autre agrégateur comme Paystack ou Wave Direct) sans altérer le reste du système, tout en préservant l'historique financier et en préparant les flux in-app d'Apple et Google.

### 7.1. Le Pattern Gateway Driver (Architecture Hexagonale)
Une interface générique `PaymentGatewayInterface` découple complètement la logique financière des particularités techniques de chaque fournisseur :

```
┌────────────────────────────────────────────────────────┐
│               Service Métier : PaymentService          │
└──────────────────────────┬─────────────────────────────┘
                           │ Utilise
                           ▼
        ┌──────────────────────────────────────┐
        │       PaymentGatewayInterface        │
        └──────────────────┬───────────────────┘
                           │
       ┌───────────────────┼───────────────────┐
       ▼                   ▼                   ▼
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│MonetbilDriver│    │Flutterwave...│    │ AppleStoreKit│
└──────────────┘    └──────────────┘    └──────────────┘
```

#### Contrat de l'interface `PaymentGatewayInterface` :
```php
namespace App\Services\Payment\Contracts;

use App\Models\Payment\PaymentTransaction;

interface PaymentGatewayInterface
{
    /**
     * Initialise la demande de prélèvement auprès de la passerelle.
     */
    public function initiatePayment(PaymentTransaction $transaction, array $params): GatewayInitiateResult;

    /**
     * Interroge le statut réel auprès de la passerelle (contre-vérification).
     */
    public function checkStatus(PaymentTransaction $transaction): GatewayStatusResult;

    /**
     * Valide et authentifie la signature d'un webhook entrant.
     */
    public function verifyWebhookSignature(array $payload, array $headers): bool;

    /**
     * Traduit le statut spécifique de la passerelle vers l'état unifié BANGO.
     */
    public function mapGatewayStatus(string|int $status): string;
}
```

### 7.2. Règle d'Unicité de l'Agrégateur Mobile Money Actif
Pour éviter toute ambiguïté opérationnelle, **un seul agrégateur Mobile Money est actif à la fois** pour une devise ou une région donnée :
* Configuré via `config/payment.php` et `.env` :
  ```env
  PAYMENT_DEFAULT_GATEWAY=monetbil
  ```
* Si BANGO bascule ultérieurement sur Flutterwave :
  1. Modifier `PAYMENT_DEFAULT_GATEWAY=flutterwave` dans `.env`.
  2. Les nouvelles transactions seront créées avec `gateway = 'flutterwave'`.
  3. Les anciennes transactions conservent `gateway = 'monetbil'`, garantissant la validité des audits et réclamations historiques.

### 7.3. Intégrations Futures : Apple In-App Purchase & Google Play Billing
Les stores mobiles imposent leurs propres rails d'achat pour les biens et privilèges numériques consommés dans l'app (notamment les badges) :
* **Parcours distinct du Mobile Money :**
  * Pas de saisie de numéro de téléphone.
  * L'achat est déclenché côté client par la bibliothèque native StoreKit / Google Billing Client.
  * L'application reçoit un jeton d'achat cryptographique (`purchase_token` ou `signed_transaction_jws`).
* **Validation Backend sécurisée :**
  * Route dédiée : `POST /api/payments/in-app/verify`.
  * Le backend valide le token directement auprès des serveurs Apple (`App Store Server API`) ou Google (`Google Play Developer API`).
  * Enregistrement dans `payment_transactions` avec `gateway = 'apple_iap'` ou `gateway = 'google_play'`.
  * Gestion des notifications de renouvellement ou d'annulation via des webhooks dédiés (`App Store Server Notifications v2` et `Google Cloud Pub/Sub RTDN`).
* **Architecture prête :** Aucune modification du schéma `payment_transactions` ne sera requise grâce aux colonnes `gateway`, `gateway_transaction_id` et `metadata`.

---

## 8. MATRICE DES RISQUES, SÉCURITÉ & DÉCISIONS MÉTIER À ARBITRER

### 8.1. Matrice des Risques Techniques

| Risque Identifié | Impact | Probabilité | Mesure d'Atténuation Technique |
| :--- | :---: | :---: | :--- |
| **Usurpation / Faux Webhook** | Critique (Perte financière) | Moyenne | Validation cryptographique stricte de `sign` MD5 + appel systématique de vérification `checkPayment` avant d'accorder le produit dans `SUB`. |
| **Double Débit Concurrent** | Majeur (Plainte client) | Élevée | Verrou exclusif pessimiste (`lockForUpdate` sur `payment_transactions`), contrainte d'unicité sur `idempotency_key`, fenêtre de rejet de 10 min. |
| **Coupure Réseau Mobile** | Moyen (Frustration client) | Très élevée | Transition vers statut `unknown` sans rejet, mécanisme de 5 essais de polling espacés sans relance de débit. |
| **Latence USSD Opérateur (> 120s)** | Moyen | Moyenne | Le webhook peut encore arriver après les 120s : le backend doit accepter le webhook tardif et régulariser la transaction de `timeout` vers `success`, puis activer l'abonnement. |

### 8.2. Décisions Métier à Arbitrer par la Direction BANGO
1. **Prise en charge des frais d'opérateur Mobile Money :**
   * *Option A :* BANGO absorbe les frais de transaction télécoms (l'utilisateur paie exactement 7 500 FCFA).
   * *Option B :* Les frais (ex: 1,5 % à 2 % ou 200 FCFA fixes) sont ajoutés au total débité sur le compte Mobile Money (`charged = amount + operatorFee`).
   * *Recommandation :* Option A pour les abonnements Badges (offre transparente "zéro surprise"), Option B pour les boosts d'annonces.
2. **Reconduction Tacite en Mobile Money :**
   * En Afrique subsaharienne, le Mobile Money ne permet pas de débit automatique silencieux sans validation USSD/Push du client (sauf accords bancaires ou prélèvements récurrents spéciaux).
   * *Conséquence :* À l'échéance de l'abonnement ($J-0$), le backend doit initier une nouvelle demande push USSD vers le numéro enregistré de l'utilisateur et lui notifier sur l'app qu'une validation est attendue.
   * Si l'utilisateur ne valide pas : entrée automatique en période de grâce de 7 jours (`SUB_NEXT_INTEGRATION.md`).
3. **Politique d'attribution du Crédit BANGO :**
   * Confirmation définitive que le solde de crédit est strictement non convertible en espèces et ne peut servir qu'à déduire des paiements sur BANGO.

---

## 9. PLAN D'IMPLÉMENTATION ÉTAPE PAR ÉTAPE

Pour garantir un déploiement sécurisé sans régression sur les 110 tests existants, le développement du module PAY devra suivre rigoureusement cet ordre :

### Étape 1 : Fondations Base de Données & Modèles Eloquent (`PAY`)
* Création des migrations PostgreSQL :
  * `create_payment_transactions_table`
  * `create_payment_attempts_table`
  * `create_payment_events_table`
  * `create_credit_wallets_and_movements_table`
  * `create_double_charge_claims_table`
* Création des modèles Eloquent sous `app/Models/Payment/` :
  * `PaymentTransaction`, `PaymentAttempt`, `PaymentEvent`, `CreditWallet`, `CreditMovement`, `DoubleChargeClaim`.
* Relations avec `User` et `Subscription`.

### Étape 2 : Configuration & Driver Monetbil Sécurisé
* Création de `config/payment.php` avec support multi-passerelles.
* Déclaration des variables d'environnement dans `.env.example` (`MONETBIL_SERVICE_KEY`, `MONETBIL_SERVICE_SECRET`, `MONETBIL_API_URL`).
* Création du driver `MonetbilGateway` sous `app/Services/Payment/Gateways/` :
  * Implémentation de `initiatePayment()` (`placePayment`).
  * Implémentation de `checkStatus()` (`checkPayment`).
  * Implémentation de la validation de signature `verifyWebhookSignature()`.

### Étape 3 : Service Métier `PaymentService` & Intégration `SUB`
* Implémentation de la création de transaction avec contrôle d'idempotence (`idempotency_key`).
* Déduction et débit atomique du Crédit BANGO (`CreditWalletService`).
* Méthode de confirmation finale `confirmTransactionSuccess()` qui appelle :
  ```php
  app(SubscriptionService::class)->activateSubscription($user, $price, $transaction->reference, $metadata);
  ```

### Étape 4 : Routes & Contrôleurs API Mobile
* `PaymentController` :
  * `POST /api/payments/initiate` (Appelle `SubscriptionService::prepareSubscription` puis `PaymentService::initiate`).
  * `GET /api/payments/{id}/status` (Polling USSD & rafraîchissement).
  * `POST /api/payments/{id}/cancel` (Annulation client).
  * `GET /api/payments/history` (Historique groupé par mois).
* `CreditController` : consultation et historique du solde.
* `DisputeController` : signalement de double débit.

### Étape 5 : Contrôleur Webhook Public & Sécurisé
* `PaymentWebhookController` :
  * `POST /api/webhooks/payments/monetbil`.
  * Validation stricte de la signature MD5.
  * Traitement idempotent asynchrone (Job Queue Laravel recommandé pour ne pas bloquer l'appelant Monetbil).

### Étape 6 : Tests Automatisés Pest
* Tests unitaires sur le calcul de signature Monetbil.
* Tests Feature du flux nominal : `prepare` $\rightarrow$ `initiate` $\rightarrow$ `webhook/checkPayment` $\rightarrow$ `activateSubscription` vérifiant que l'utilisateur reçoit bien son badge et que `users.badge` passe à la bonne couleur.
* Tests des cas limites : doublons, expiration timeout 120s, rejet signature falsifiée, solde crédit insuffisant.

---

## 10. CONCLUSION DE L'AUDIT

### Ce qui est Prêt :
* L'architecture globale de BANGO est propre, modulaire et conforme aux meilleures pratiques Laravel 12 / PHP 8.4.
* Le module **SUB (Abonnements & Badges)** est complet, robuste, totalement testé (110 tests Pest au vert) et dispose déjà des méthodes d'accueil (`prepareSubscription`, `activateSubscription`, `enterGracePeriod`) prêtes pour PAY.
* Le contrat frontend mobile (`lib/api/payments.ts`) est exhaustif, documenté jusqu'aux codes d'erreur opérateurs, et compatible avec l'architecture proposée.
* Les spécifications de l'API Monetbil (endpoints, paramètres, algorithme de signature MD5, `checkPayment`) sont parfaitement maîtrisées.

### Ce qui Manque :
* Les tables PostgreSQL du module PAY (transactions, tentatives, événements, crédits, litiges).
* Le driver Monetbil et la mécanique de validation de signature.
* Les routes de paiement et les contrôleurs backend.
* L'arbitrage de la direction sur les frais d'opérateur (inclus vs facturés en sus).

### Prochaine Étape :
> **Aucun code de développement ne doit être produit sans la validation formelle de ce rapport d'audit et le choix de la politique de frais.** Dès validation, l'implémentation de l'**Étape 1 (Migrations et Modèles PAY)** pourra démarrer immédiatement.
