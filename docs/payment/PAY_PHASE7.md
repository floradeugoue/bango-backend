# BANGO BACKEND — RAPPORT D'EXÉCUTION PHASE 7 : SÉCURISATION ET VALIDATION DES PAIEMENTS

## 1. Contexte et Objectifs

La Phase 7 du backend BANGO parachève le niveau de sécurité et de résilience du module PAY sous Laravel 12, PHP 8.4, PostgreSQL et Laravel Passport (`auth:api`).

### Objectifs atteints :
1. **Vérification serveur-à-serveur systématique (`checkPayment`)** : Aucun retour de l'application mobile ni notification IPN isolée ne vaut preuve de paiement sans une interrogation directe, sécurisée et certifiée auprès de l'API Monetbil.
2. **Gestion robuste des statuts et des timeouts (`unknown`)** : Distinguer formellement un paiement en cours (`processing`), un échec définitif (`failed`) et un état indéterminé (`unknown` suite à un timeout réseau ou indisponibilité temporaire). Ne jamais déclarer un paiement échoué ou réussi par simple timeout.
3. **Webhook IPN impénétrable & anti-fraude** : Signature MD5 timing-safe avec `hash_equals()`, validation stricte des données, vérification de concordance de la devise (`XAF`) et du montant total dû, avec émission d'alertes `fraud_alert`.
4. **Idempotence & Concurrence** : Utilisation de transactions SQL PostgreSQL avec verrous pessimistes `lockForUpdate()`, interdisant toute régression d'un paiement `confirmed` et toute double activation SUB.
5. **Réconciliation automatique & planifiée** : Service `PaymentReconciliationService` et commande Artisan `payment:reconcile` (planifiée toutes les 10 minutes) pour sonder et résoudre les transactions non finalisées.
6. **Étanchéité des prestations hors-abonnement** : Les prestations ponctuelles (`certification`, `boost`, `visit`) consignent l'événement `service_fulfilled` sans jamais créer ni altérer d'abonnement SUB.
7. **Socle préparatoire Apple IAP & Google Play Billing** : Migration de la table additive `store_purchase_receipts` avec contrainte d'unicité sur l'empreinte SHA-256 (`purchase_token_hash`) pour interdire formellement le rejeu de reçus ou jetons de store déjà consommés.

---

## 2. Travaux Réalisés & Architecture

### A. DTO & Passerelle Monetbil (`MonetbilGateway`)
- **Méthode `GatewayStatusResult::unknown(...)`** : Constructeur statique typé pour encapsuler les états indéterminés avec `PaymentStatus::Unknown`.
- **Amélioration de `checkStatus()`** :
  - Encadrement des requêtes `POST /payment/v1/checkPayment` avec délais stricts (Connect Timeout: 5s, Request Timeout: 15s).
  - Gestion explicite des erreurs HTTP 5xx et 408 : classification en `unknown` sans basculer arbitrairement en échec.
  - Gestion des réponses non-JSON ou mal formées : classification en `unknown`.
  - Capture de `ConnectionException` et timeouts réseau : retour de `GatewayStatusResult::unknown(...)`.
  - Normalisation stricte : `1`/`success` => `confirmed`, `0`/`failed`/`cancelled` => `failed`, `-1`/`pending` => `pending`, tout autre statut => `unknown`.

### B. Contrôleur Webhook IPN (`MonetbilWebhookController`)
- **Contrôle cryptographique timing-safe** : Validation de la signature MD5 via `$gateway->verifyWebhookSignature()` avec rejet `403 Forbidden` (`Signature invalide.`).
- **Validation structurelle** : Rejet `422 Unprocessable Content` si les identifiants de transaction (`paymentId` ou `item_ref`) ou le montant sont absents ou invalides.
- **Verrouillage concurrentiel** : Requête exécutée sous `DB::transaction()` avec `lockForUpdate()`.
- **Idempotence terminale** : Si la transaction est déjà `Confirmed`, réponse `200` immédiate ("Paiement déjà confirmé.") sans régression d'état ni double activation.
- **Contrôle anti-fraude** : Vérification stricte de la devise (`XAF`) et du montant total dû (écart maximal de 0.01 FCFA). Si anomalie : consignation d'un événement `fraud_alert` et rejet `422`.
- **Contre-vérification serveur obligatoire** : Appel systématique de `checkStatus()`. Seule une réponse formellement `Confirmed` par le serveur Monetbil autorise `confirmAndFulfill()`.

### C. Moteur Métier & Prestations Ponctuelles (`PaymentEngineService`)
- Prise en charge des transitions depuis l'état `Unknown` vers `Confirmed` une fois la confirmation certifiée obtenue.
- Pour les paiements liés à `certification`, `boost` et `visit`, enregistrement de l'événement d'audit `service_fulfilled` sans appel à `SubscriptionService`.
- Conservation de l'événement `subscription_fulfilled` pour garantir qu'un abonnement ne sera jamais activé deux fois.

### D. Service & Commande de Réconciliation (`PaymentReconciliationService`)
- **Fichier de service** : `app/Services/Payment/PaymentReconciliationService.php`.
- **Commande console** : `app/Console/Commands/Payment/ReconcilePaymentsCommand.php` (`php artisan payment:reconcile`).
- **Planification dans `routes/console.php`** :
  - Planifiée toutes les 10 minutes (`everyTenMinutes()`).
  - Protection contre les chevauchements (`withoutOverlapping()`).
  - Exécution en arrière-plan (`runInBackground()`) sur un serveur unique (`onOneServer()`).
- **Configuration dédiée dans `config/payment.php`** :
  - `reconciliation.enabled` (défaut : `true`)
  - `reconciliation.min_age_minutes` (défaut : `5` min)
  - `reconciliation.max_age_days` (défaut : `3` jours)
  - `reconciliation.max_retries` (défaut : `5` tentatives max)
  - `reconciliation.batch_size` (défaut : `50` transactions par lot)

### E. Préparation Apple IAP & Google Play Billing
- **Migration SQL** : `database/migrations/2026_10_14_122500_create_store_purchase_receipts_table.php` (exécutée sur PostgreSQL).
- **Modèle Eloquent** : `app/Models/Payment/StorePurchaseReceipt.php` :
  - Colonnes : `transaction_id`, `provider` (`apple`, `google`), `store_product_id`, `original_transaction_id`, `purchase_token_hash`, `verification_status`, `environment`, `store_payload`, `verified_at`.
  - Méthode `hashToken(string $rawToken): string` : calcul de l'empreinte SHA-256 du reçu/jeton pour interdire le rejeu sans stocker de jetons sensibles géants en clair.
  - Relation `storeReceipt` déclarée sur `PaymentTransaction`.

---

## 3. Résultats des Tests de Validation

### Suite de Tests Pest Phase 7 (`tests/Feature/Payment/PaymentSecurityPhase7Test.php`)
22 tests exhaustifs couvrant l'ensemble des cas d'usage exigés :
1. ✅ **Signature IPN invalide** : Rejet systématique avec code `403 Forbidden`.
2. ✅ **Notification mal formée** : Rejet avec code `422 Unprocessable Content`.
3. ✅ **Référence externe inconnue** : Rejet avec code `404 Not Found`.
4. ✅ **Montant incorrect** : Rejet `422` et enregistrement immédiat d'un événement `fraud_alert`.
5. ✅ **Devise incorrecte** : Rejet `422` et enregistrement immédiat d'un événement `fraud_alert`.
6. ✅ **Vérification Monetbil réussie** : Confirmation et activation atomique de l'abonnement et du badge KYC.
7. ✅ **Vérification Monetbil échouée** : Transition vers `Failed` sans aucun droit accordé.
8. ✅ **Timeout réseau** : Maintien du statut incertain `Unknown` sans déclaration prématurée d'échec.
9. ✅ **IPN dupliqué** : Réponse `200` idempotente sans duplication d'événements.
10. ✅ **Traitements concurrents** : Atomicité garantie sous verrou pessimiste PostgreSQL.
11. ✅ **Succès après état `Unknown`** : Transition valide et activation rétroactive certifiée.
12. ✅ **Notification négative tardive** : Protection absolue du statut `Confirmed` contre toute rétrogradation.
13. ✅ **Prévention double activation SUB** : Un seul enregistrement d'abonnement actif même en cas d'appels multiples.
14. ✅ **Paiement non confirmé** : Aucun droit premium ni badge attribué.
15. ✅ **Isolation des données utilisateur** : Rejet sécurisé (`404`) lors de la consultation d'une transaction tierce.
16. ✅ **Non-régression Passport** : Authentification OAuth2 `auth:api` exigée (`401` sans token, `200` avec token).
17. ✅ **Non-régression catalogue et tarification** : Maintien des calculs exacts de `PricingService`.
18. ✅ **Non-régression prestations ponctuelles** : Confirmation de `certification`, `boost` et `visit` avec `service_fulfilled` sans abonnement.
19. ✅ **Non-régression renouvellement manuel & grâce** : Règles de la Phase 6 (éligibilité, devis, 7 jours de grâce) 100% opérationnelles.
20. ✅ **Idempotence réconciliation** : Exécution multiple de `payment:reconcile` sans effets de bord.
21. ✅ **Protection anti-fuite** : Aucun secret marchand ni mot de passe divulgué dans les réponses API.
22. ✅ **Anti-rejeu Store Purchases** : Unicité stricte du hash SHA-256 du reçu store et rejet des doublons.

### Résultats d'exécution :
- `PaymentSecurityPhase7Test.php` : **22/22 tests réussis**, 75 assertions.
- Suite complète PAY (`tests/Feature/Payment`) : **103/103 tests réussis**, 578 assertions.
- Suite complète SUB (`tests/Feature/Subscription`) : **28/28 tests réussis**, 211 assertions.
- **Suite Complète BANGO** : **227/227 tests réussis**, 1183 assertions, **0 échec**.
- Formatage Pint : `php vendor/bin/pint --format agent` exécuté avec succès (0 violation résiduelle).

---

## 4. Synthèse des Éléments Implémentés vs Vérifications Réelles

| Domaine | Implémenté & Testé Automatiquement (Mocks/Fakes) | À Valider avec Monetbil en Environnement Réel |
| :--- | :--- | :--- |
| **API `placePayment`** | Format form-urlencoded, normalisation des téléphones CM, headers et timeouts validés. | Délai réel de réception du prompt USSD sur les cartes SIM Orange Money et MTN MoMo. |
| **API `checkPayment`** | Gestion des réponses 200, 4xx, 5xx, timeouts réseau, statuts `1`, `0`, `-1` et statuts inconnus. | Latence moyenne de synchronisation entre l'opérateur télécom et Monetbil. |
| **Webhook IPN** | Validation cryptographique MD5 timing-safe, contrôles de devises et montants, idempotence. | Configuration exacte de l'URL publique de notification (`MONETBIL_NOTIFY_URL`) sur le compte marchand Monetbil. |
| **Réconciliation** | Commande console, polling avec retry max et filtres d'ancienneté. | Fréquence de passage du cron de production (`* * * * * php artisan schedule:run`). |
| **Stores Natifs** | Table `store_purchase_receipts`, hachage SHA-256 anti-rejeu, isolation du canal. | Obtention des clés de service Apple App Store Server API et Google Play Developer API lors des phases ultérieures. |
