# BANGO — Module Crédit BANGO (CB) & Intégration PAY, BO, IM

## 1. Vue d'ensemble & Architecture

Le module **Crédit BANGO (CB)** constitue la monnaie virtuelle interne de l'application BANGO. Il permet aux utilisateurs de financer leurs opérations dans l'application, spécifiquement :
* **BO — Boost & Publicité** : Financement des campagnes de visibilité pour les publications Square, les Reels vidéo et les profils.
* **IM — Visites Immobilières** : Règlement des frais de réservation de visites immobilières.

### Règle d'or immuable : 1 FCFA = 1 Crédit BANGO
* **Conversion fixe et garantie** : $1\text{ FCFA} = 1\text{ Crédit BANGO}$.
* Exemple : Un utilisateur paye **2 500 FCFA** par Mobile Money (Orange Money / MTN MoMo via Monetbil). Dès confirmation définitive du paiement par le module PAY, le système crédite exactement **2 500 crédits** dans son portefeuille virtuel.
* **Précision monétaire entière** : Les crédits et les montants d'opérations sont stockés sous forme d'entiers strictement positifs (`unsignedBigInteger` dans PostgreSQL), excluant toute imprécision liée aux nombres à virgule flottante.

---

## 2. Invariants Métier & Sécurité Financière

1. **Crédit après confirmation uniquement** :
   Le portefeuille n'est **JAMAIS** crédité lors de l'initiation d'un paiement ou sur simple présentation d'un reçu client. Seul le webhook IPN officiel vérifié cryptographiquement (ou la validation serveur `PaymentEngineService`) déclenche l'écriture de crédit.
2. **Opération strictement unidirectionnelle (Aucune restitution / Aucun remboursement)** :
   Un achat de crédits est définitif.
   * Aucun remboursement monétaire des crédits n'est autorisé.
   * Aucun retrait d'argent ni conversion inverse vers le FCFA.
   * **Consommation définitive** : Lorsqu'une opération est facturée en crédits (campagne boost ou réservation de visite), le montant débité est définitivement consommé.
   * L'annulation ou l'interruption d'une campagne publicitaire ne donne lieu à aucun recrédit automatique ou remboursement de crédits non consommés.
   * L'annulation ou le refus d'une visite n'engendre aucun recrédit automatique de crédits virtuels.
   * La clôture ou suppression de compte n'entraîne aucune restitution monétaire.
3. **Solde non négatif garanti** :
   Une opération ne peut jamais consommer plus de crédits que le solde disponible. PostgreSQL applique un verrou pessimiste `lockForUpdate()` durant les écritures de débit, garantissant l'absence de dépassement même en cas de requêtes concurrentes simultanées.
4. **Idempotence stricte et protection contre les rejeux** :
   Chaque achat est protégé par la référence de la transaction PAY et une clé d'idempotence unique. Un webhook reçu plusieurs fois ne génère qu'une seule écriture comptable.
   De même, chaque opération métier (Boost, Visite) ne peut produire qu'un seul débit pour le couple `[source, reference]`.
5. **Isolation totale des utilisateurs** :
   Chaque utilisateur ne peut consulter ou utiliser que son propre portefeuille. L'accès ou le débit du portefeuille d'un tiers est strictement interdit (401 / 403 / 404).

---

## 3. Schéma de Données PostgreSQL

### Table `credit_wallets`
| Colonne | Type | Contrainte | Description |
| :--- | :--- | :--- | :--- |
| `id` | `bigint` | Primary Key | Identifiant unique du portefeuille |
| `user_id` | `bigint` | Foreign Key (`users.id`), Unique, RestrictOnDelete | Utilisateur propriétaire |
| `balance` | `unsignedBigInteger` | Default 0 | Solde disponible en nombre entier de crédits |
| `currency` | `string(3)` | Default `'XAF'` | Devise d'achat |
| `created_at` / `updated_at` | `timestamps` | Nullable | Horodatages standard |

### Table `credit_transactions` (Grand Livre Immuable)
| Colonne | Type | Contrainte | Description |
| :--- | :--- | :--- | :--- |
| `id` | `bigint` | Primary Key | Identifiant unique de l'écriture |
| `wallet_id` | `bigint` | Foreign Key (`credit_wallets.id`), RestrictOnDelete | Portefeuille concerné |
| `user_id` | `bigint` | Foreign Key (`users.id`), RestrictOnDelete | Utilisateur propriétaire |
| `type` | `string(50)` | Index | `purchase`, `debit` (ou types rétrocompatibles) |
| `amount` | `unsignedBigInteger` | Strictement positif | Nombre entier de crédits mouvementés |
| `balance_before` | `unsignedBigInteger` | Non null | Solde avant l'opération |
| `balance_after` | `unsignedBigInteger` | Non null | Solde après l'opération |
| `source` | `string(50)` | Index | `pay`, `boost`, `visit` |
| `reference` | `string(100)` | Index | Référence unique de l'opération source |
| `payment_transaction_id` | `bigint` | Foreign Key (`payment_transactions.id`), Nullable, Unique | Lien avec PAY lors d'un achat |
| `idempotency_key` | `string(128)` | Unique, Nullable | Clé d'idempotence anti-rejeu |
| `description` | `string` | Nullable | Libellé lisible de l'opération |
| `metadata` | `jsonb` | Nullable | Contexte JSON (opérateur, canal, campagne, etc.) |
| `created_at` | `timestamp` | Default `now()` | Horodatage immuable de l'écriture |

*Contrainte d'unicité* : `UNIQUE (source, reference)` et `UNIQUE (payment_transaction_id)` pour bloquer tout doublon au niveau moteur SQL.

---

## 4. API Endpoints

Préfixe : `/api/credits`  
Middleware obligatoire : `auth:api`, `active.user`

### A. Consulter le portefeuille
* **Route** : `GET /api/credits/wallet`
* **En-têtes** : `Authorization: Bearer <token>`
* **Réponse 200 OK** :
```json
{
  "data": {
    "id": 1,
    "user_id": 42,
    "balance": 2500,
    "currency": "XAF",
    "conversion_rate": "1 FCFA = 1 Crédit BANGO",
    "recent_transactions": [
      {
        "id": 1,
        "type": {
          "value": "purchase",
          "label": "Achat de Crédits BANGO",
          "is_credit": true
        },
        "amount": 2500,
        "balance_before": 0,
        "balance_after": 2500,
        "source": "pay",
        "reference": "BNG-TX-20261016-ABCD",
        "payment_transaction_id": 12,
        "description": "Achat de 2 500 Crédits BANGO (1 FCFA = 1 Crédit)",
        "created_at": "2026-10-16T12:00:00+00:00"
      }
    ],
    "updated_at": "2026-10-16T12:00:00+00:00"
  }
}
```

---

### B. Initier un achat de crédits via PAY
* **Route** : `POST /api/credits/purchases`
* **En-têtes** : `Authorization: Bearer <token>`, `X-Idempotency-Key: <uuid>` (optionnel)
* **Payload** :
```json
{
  "amount": 2500,
  "provider": "monetbil",
  "phone": "690000000",
  "operator": "orange",
  "idempotency_key": "cred_purchase_uuid_001"
}
```
* **Validation** :
  * `amount` : entier obligatoire, min 100, max 10 000 000.
  * `provider` : obligatoire, `monetbil` (ou `mtn_momo`, `orange_money`).
  * `phone` : obligatoire, format MSISDN valide.
  * `operator` : optionnel, `orange` ou `mtn`.
* **Réponse 201 Created** :
```json
{
  "success": true,
  "message": "Demande de paiement Mobile Money initiée avec succès.",
  "data": {
    "reference": "BNG-TX-20261016-WXYZ",
    "service_type": "credit",
    "amount": "2500.00",
    "total_amount": "2500.00",
    "status": "pending",
    "provider": "monetbil",
    "channel": "mobile_money"
  },
  "prompt": {
    "ussd_code": "*126#",
    "timeout_seconds": 120,
    "instruction": "Veuillez valider l'opération sur votre téléphone en saisissant votre code secret Mobile Money."
  },
  "conversion": {
    "amount_fcfa": 2500,
    "credits_awarded": 2500,
    "rate": "1 FCFA = 1 Crédit BANGO"
  }
}
```
*Note* : Le solde n'augmente pas à ce stade. Le crédit intervient à la réception du webhook confirmant le succès.

---

### C. Consulter l'historique des transactions
* **Route** : `GET /api/credits/transactions`
* **Query Parameters** :
  * `per_page` : int (défaut 15, max 100)
  * `type` : string (`purchase`, `debit`)
  * `source` : string (`pay`, `boost`, `visit`)
  * `start_date` : string YYYY-MM-DD
  * `end_date` : string YYYY-MM-DD
* **Réponse 200 OK** :
```json
{
  "data": [
    {
      "id": 2,
      "type": {
        "value": "debit",
        "label": "Consommation de crédits",
        "is_credit": false
      },
      "amount": 1000,
      "balance_before": 2500,
      "balance_after": 1500,
      "source": "boost",
      "reference": "0199f3ac-1122-...",
      "payment_transaction_id": null,
      "description": "Financement campagne boost #0199f3ac",
      "created_at": "2026-10-16T12:05:00+00:00"
    },
    {
      "id": 1,
      "type": {
        "value": "purchase",
        "label": "Achat de Crédits BANGO",
        "is_credit": true
      },
      "amount": 2500,
      "balance_before": 0,
      "balance_after": 2500,
      "source": "pay",
      "reference": "BNG-TX-20261016-WXYZ",
      "payment_transaction_id": 15,
      "description": "Achat de 2 500 Crédits BANGO (1 FCFA = 1 Crédit)",
      "created_at": "2026-10-16T12:01:00+00:00"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

## 5. Intégrations Métier

### Intégration BO (Boost & Publicité)
Lors de la création ou du financement d'une campagne publicitaire avec `payment_method = 'credit'` :
1. Le backend calcule et valide le budget côté serveur.
2. `CreditWalletService::debit` est appelé avec `source = 'boost'` et `reference = $campaign->id`.
3. Si le solde est insuffisant, `InsufficientCreditException` est levée (`422 Unprocessable Entity`).
4. Si la campagne est interrompue ou annulée ultérieurement, **aucun remboursement de crédits** n'a lieu (règle de consommation définitive).

### Intégration IM (Visites Immobilières)
Lors d'une réservation de visite payante avec `payment_method = 'credit'` ou `channel = 'credit_wallet'` :
1. Le backend vérifie l'annonce et le créneau, et calcule les frais côté serveur.
2. `CreditWalletService::debit` est exécuté avec `source = 'visit'` et `reference = 'VISIT-'.$reservation->id`.
3. Le statut de la réservation passe directement à `Confirmed` et `paid_at = now()`.
4. En cas d'annulation ou de refus de la visite, aucun recrédit automatique de crédits virtuels n'est accordé.

---

## 6. Commandes de Migration et de Tests

```bash
# Exécution des migrations (environnement vierge ou incrémental)
php artisan migrate

# Tests unitaires et fonctionnels du module Crédit BANGO
php artisan test tests/Feature/Credits/CreditBangoTest.php --compact

# Tests d'intégration des modules associés
php artisan test tests/Feature/Payment/MonetbilWebhookTest.php --compact
php artisan test tests/Feature/Boost --compact
php artisan test tests/Feature/Visit --compact

# Suite de tests complète
php artisan test --compact
```
