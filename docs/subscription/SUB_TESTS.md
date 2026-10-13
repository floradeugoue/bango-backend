# BANGO — Rapport des Tests Exécutés Module SUB (Abonnements & Badges)

Ce document consigne les tests fonctionnels automatisés rédigés et réellement exécutés pour le module **SUB** avec Pest PHP sur la base de données PostgreSQL locale.

---

## 1. Métriques d'Exécution

Commande exécutée :
```powershell
php artisan test --compact tests/Feature/SubscriptionModuleTest.php
```

- **Nombre de tests de la suite SUB :** **14 / 14 passés (100 %)**
- **Nombre d'assertions :** **147**
- **Temps d'exécution :** 1.67 seconde
- **Statut :** ✅ **PASSED**

---

## 2. Matrice des Scénarios Couverts

| Test Fonctionnel | Scénario & Assertions | Résultat |
| :--- | :--- | :---: |
| **Consultation Catalogue Public** | `GET /api/catalog` renvoie les offres actives (`landlord`, `agent`, `page_pro`, `listing_boost`) avec tarifs mensuels et annuels en XAF (7 500 et 75 000 FCFA). | ✅ Validé |
| **Détail d'une Offre** | `GET /api/catalog/{code}` résout par code et rejette les offres inconnues avec `404 Not Found`. | ✅ Validé |
| **Administration du Catalogue** | `POST /api/admin/catalog/products` permet à un administrateur d'ajouter un produit et un tarif avec date d'effet. Un utilisateur standard est rejeté avec `403 Forbidden`. | ✅ Validé |
| **Intention de Souscription** | `POST /api/subscriptions/subscribe` valide l'éligibilité sans activer le badge prématurément, et retourne l'intention de paiement prête pour PAY. | ✅ Validé |
| **Activation & Anneau Vert** | `SubscriptionService::activateSubscription` active l'abonnement Bailleur, met à jour `users.badge = landlord` et renvoie l'anneau vert `#1E9E5A`. | ✅ Validé |
| **Cumul Double Badge & Anneau Rouge** | L'utilisateur détient simultanément un abonnement Bailleur et un abonnement Agent. `BadgeResolutionService` bascule le badge à `double` et l'anneau en Rouge Signature `#D80F0F`. | ✅ Validé |
| **Distinction Paiement vs KYC** | Un abonnement payé par un utilisateur non vérifié (`is_verified = false`) conserve le statut `none` sans anneau de confiance. L'approbation KYC active immédiatement le badge. | ✅ Validé |
| **Expiration Documentaire (Lapsed)** | Lorsque `verification_lapsed = true`, le badge et les droits de publication sont suspendus même avec un abonnement payé actif. | ✅ Validé |
| **Calcul du Prorata Temporis** | `POST /api/subscriptions/{id}/change-plan` calcule la déduction des jours non consommés lors du passage mensuel vers annuel. | ✅ Validé |
| **Résiliation d'Abonnement** | `POST /api/subscriptions/{id}/cancel` passe `auto_renew = false`, enregistre motif et feedback, tout en maintenant les droits jusqu'à `current_period_end`. | ✅ Validé |
| **Opérations Back-Office** | Un administrateur peut suspendre, réactiver, prolonger (+15j) ou révoquer un abonnement avec historisation dans `subscription_events`. | ✅ Validé |
| **Isolation des Données** | Un utilisateur B ne peut ni consulter ni résilier l'abonnement d'un utilisateur A (`404 Not Found`). | ✅ Validé |
| **Middleware EnsureActiveSubscription** | Protège les routes métier : `403 SUBSCRIPTION_REQUIRED` si sans abonnement, `403 KYC_REQUIRED` si sans KYC, `403 KYC_LAPSED` si pièce expirée, `200 OK` si conforme. | ✅ Validé |
| **Période de Grâce & Expiration** | `enterGracePeriod` maintient les privilèges pendant 7 jours (`isUsable = true`). À l'expiration (`expireSubscription`), le badge est révoqué (`badge = none`). | ✅ Validé |

---

## 3. Test de Non-Régression de la Suite Globale

Commande exécutée :
```powershell
php artisan test --compact
```

- **Nombre total de tests :** **110 / 110 passés**
- **Nombre total d'assertions :** **541**
- **Échecs :** **0**
- **Avertissements non-bloquants :** 2 (dépréciations de dépendances tierces)
- **Couverture préservée :** Module AUTH (100%), Module PR (100%), Module Geo (100%), Module Admin (100%), Module SUB (100%).
