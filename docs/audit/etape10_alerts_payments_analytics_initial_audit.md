# BANGO — Étape 10 : Audit Initial & Cartographie Technique

**Date :** 16 Octobre 2026  
**Auteur :** Antigravity AI  
**Objectif :** Moteur d’alertes immobilières & finalisation du module paiements (IM, PAY, BL).  

---

## 1. Matrice des Cinq Fonctionnalités

| N° | Module & Fonctionnalité | Statut Initial | Composants Existants Identifiés | Lacunes à Développer |
| :---: | :--- | :---: | :--- | :--- |
| **1** | **IM — Alertes automatiques de nouveaux logements** | 🟡 **Partiel** | Table `housing_searches`, modèle `HousingSearch`, `HousingSearchController`, modèle `Listing` et `ListingStatus::Published`. | Aucun événement `ListingPublished`. Aucun Listener `NotifyMatchingHousingSearches`. Aucune notification `NewMatchingListingNotification` connectée à `NOTIF`. |
| **2** | **PAY — Moyens de paiement enregistrés** | 🔴 **Absent** | Infrastructure `PaymentTransaction`, `PaymentChannel`, `PaymentProvider`. | Aucune table `saved_payment_methods`, aucun modèle `SavedPaymentMethod`, aucun contrôleur `SavedPaymentMethodController`, aucune FormRequest ni Policy. |
| **3** | **PAY — Reçus PDF officiels** | 🟡 **Partiel** | Reçu JSON spécifique aux visites (`VisitReceiptResource`, `/api/visits/{id}/receipt`). | Aucun endpoint général `GET /api/payments/{id}/receipt` pour les transactions (visites, abonnements, boosts). Aucun générateur PDF binaire officiel autonome sans dépendance lourde. |
| **4** | **PAY — Déclaration d’incidents de double débit** | 🟡 **Partiel** | Modèle `PaymentDispute` et table `payment_disputes` existants. Endpoints back-office `/api/admin/payments/double-charge-claims`. | Aucun endpoint client mobile `POST /api/payments/double-charge-claims`. Aucune FormRequest ni contrôleur client dédié pour soumettre un dossier avec déduplication. |
| **5** | **BL — Analytique immobilière chronologique** | 🟡 **Partiel** | Table `listings` (compteurs totaux `views_count`, `saves_count`), table `listing_saves` (favoris avec `created_at`), table `visit_reservations` (contacts/visites avec `created_at`). | Aucune table métrique quotidienne par annonce (`listing_daily_metrics`), aucun service d'agrégation temporelle 30 jours, aucun contrôleur `LandlordPropertyAnalyticsController` (`GET /api/landlord/properties/{id}/analytics`). |

---

## 2. Plan d'Implémentation Séquentielle

1. **Phase B — IM : Alertes automatiques de nouveaux logements**
   - Événement `App\Events\Listing\ListingPublished`.
   - Déclenchement lors du passage à `ListingStatus::Published` dans `ListingService`.
   - Listener asynchrone `App\Listeners\Listing\NotifyMatchingHousingSearches` (`ShouldQueue`).
   - Notification `App\Notifications\Listing\NewMatchingListingNotification` (`via database / push`).
   - Tests Pest : `tests/Feature/Listing/HousingSearchAlertsTest.php`.

2. **Phase C — PAY : Moyens de paiement enregistrés**
   - Migration `create_saved_payment_methods_table`.
   - Modèle `App\Models\Payment\SavedPaymentMethod`.
   - Form Requests : `StoreSavedPaymentMethodRequest`.
   - Contrôleur `App\Http\Controllers\Payment\SavedPaymentMethodController`.
   - Policy `SavedPaymentMethodPolicy`.
   - Routes : `GET /api/payments/methods`, `POST /api/payments/methods`, `DELETE /api/payments/methods/{id}`, `PATCH /api/payments/methods/{id}/default`.
   - Tests Pest : `tests/Feature/Payment/SavedPaymentMethodTest.php`.

3. **Phase D — PAY : Reçus PDF officiels**
   - Service `App\Services\Payment\ReceiptPdfService` générant un PDF standard 1.4 pur PHP avec métadonnées officielles.
   - Contrôleur `App\Http\Controllers\Payment\PaymentReceiptController` (`GET /api/payments/{id}/receipt`).
   - Support des transactions `visit`, `subscription`, `boost`. Contrôle des droits et statut `confirmed`.
   - Tests Pest : `tests/Feature/Payment/PaymentReceiptTest.php`.

4. **Phase E — PAY : Déclaration de double débit**
   - Form Request `StoreDoubleChargeClaimRequest`.
   - Contrôleur `App\Http\Controllers\Payment\DoubleChargeClaimController` (`POST /api/payments/double-charge-claims`, `GET /api/payments/double-charge-claims/mine`).
   - Service `App\Services\Payment\DoubleChargeClaimService` utilisant `PaymentDispute`.
   - Tests Pest : `tests/Feature/Payment/DoubleChargeClaimTest.php`.

5. **Phase F — BL : Analytique immobilière chronologique**
   - Migration `create_listing_daily_metrics_table`.
   - Modèle `App\Models\Listing\ListingDailyMetric`.
   - Service `App\Services\Listing\ListingAnalyticsService` agrégeant les séries 30 jours (vues, vues vidéo, favoris, contacts) avec zéros continus et totaux.
   - Contrôleur `App\Http\Controllers\Landlord\LandlordPropertyAnalyticsController` (`GET /api/landlord/properties/{id}/analytics`).
   - Tests Pest : `tests/Feature/Landlord/LandlordPropertyAnalyticsTest.php`.

6. **Phase G — Validation globale, formatage Pint & documentation**
   - Formatage Pint : `vendor/bin/pint --format agent` exécuté avec succès.
   - Suite complète Pest non-régression : `php artisan test --compact` validé (814 tests / 3986 assertions, 100 % succès).
   - Documentation technique transversale : `docs/transverse/etape10_alerts_payments_analytics_documentation.md`.

---

## 3. Matrice Finale Post-Implémentation

| N° | Module & Fonctionnalité | Statut Final | Tests Spécifiques | Statut Global |
| :---: | :--- | :---: | :---: | :---: |
| **1** | **IM — Alertes automatiques de nouveaux logements** | 🟢 **COMPLET** | 3 tests / 8 assertions | Opérationnel |
| **2** | **PAY — Moyens de paiement enregistrés** | 🟢 **COMPLET** | 7 tests / 34 assertions | Opérationnel |
| **3** | **PAY — Reçus PDF officiels** | 🟢 **COMPLET** | 6 tests / 15 assertions | Opérationnel |
| **4** | **PAY — Déclaration d’incidents de double débit** | 🟢 **COMPLET** | 6 tests / 23 assertions | Opérationnel |
| **5** | **BL — Analytique immobilière chronologique** | 🟢 **COMPLET** | 6 tests / 22 assertions | Opérationnel |

