# BANGO — Documentation Technique Étape 10 : Alertes Immobilières & Finalisation des Paiements

**Projet :** BANGO Backend (Laravel 13 / PHP 8.4 / PostgreSQL 18.6)  
**Date :** 16 Octobre 2026  
**Statut :** Validé — 100 % opérationnel, testé (28 tests spécifiques / 102 assertions Pest)

---

## 1. Vue d'Ensemble & Périmètre d'Intervention

L'Étape 10 finalise et raccorde cinq fonctionnalités critiques transversales sur les modules Immobiliers (**IM**), Bailleur (**BL**) et Paiements (**PAY**) :

1. **IM — Alertes automatiques de nouveaux logements** : Moteur événementiel asynchrone comparant toute nouvelle publication d'annonce immobilière aux critères géographiques, budgétaires et typologiques des recherches enregistrées (`housing_searches`), avec déduplication et routage vers le centre de notifications (`NOTIF`).
2. **PAY — Moyens de paiement enregistrés** : Mémorisation sécurisée des numéros Mobile Money (MTN, Orange, Wave, Moov, Airtel) sans détention de PIN, avec gestion de l'opérateur par défaut, masquage conforme aux normes de confidentialité et garantie stricte d'absence de débit lors de l'enregistrement.
3. **PAY — Génération de reçus PDF officiels** : Service de production de reçus PDF (spécification ISO PDF 1.4 binaire en PHP pur) pour toutes les transactions confirmées (Visites, Abonnements, Boosts de publications/annonces), avec vérification de propriété et métadonnées d'authenticité numérique.
4. **PAY — Déclaration d'incidents de double débit** : Flux de déclaration mobile permettant aux clients de signaler un débit surnuméraire avec référence opérateur, détection des doublons, validation d'intégrité et rattachement au système de litiges sans altération automatique du solde ou déclenchement intempestif de remboursement.
5. **BL — Analytique immobilière chronologique** : Endpoint restituant aux bailleurs une série temporelle continue sur 30 jours (vues, vues vidéo, favoris, contacts) avec remplissage à 0 des journées sans activité et consolidation des données réelles de réservations de visite et de favoris.

---

## 2. Inventaire des Endpoints Ajoutés ou Modifiés

| Module | Méthode | URI | Contrôleur & Action | Authentification | Autorisation / Scope |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **PAY** | `GET` | `/api/payments/methods` | `SavedPaymentMethodController@index` | Requise (Bearer) | Utilisateur connecté (liste de ses moyens enregistrés) |
| **PAY** | `POST` | `/api/payments/methods` | `SavedPaymentMethodController@store` | Requise (Bearer) | Validation opérateur, format téléphone, pas de débit |
| **PAY** | `PATCH` | `/api/payments/methods/{id}/default` | `SavedPaymentMethodController@setDefault` | Requise (Bearer) | `SavedPaymentMethodPolicy@update` (propriétaire uniquement) |
| **PAY** | `DELETE` | `/api/payments/methods/{id}` | `SavedPaymentMethodController@destroy` | Requise (Bearer) | `SavedPaymentMethodPolicy@delete` + réélection moyen par défaut |
| **PAY** | `GET` | `/api/payments/{id}/receipt` | `PaymentReceiptController@downloadReceipt` | Requise (Bearer) | Propriétaire ou Admin, transaction au statut `confirmed` |
| **PAY** | `POST` | `/api/payments/double-charge-claims` | `DoubleChargeClaimController@store` | Requise (Bearer) | Propriétaire de transaction si renseignée, déduplication référence |
| **PAY** | `GET` | `/api/payments/double-charge-claims/mine` | `DoubleChargeClaimController@mine` | Requise (Bearer) | Liste paginée des déclarations du client connecté |
| **BL** | `GET` | `/api/landlord/properties/{id}/analytics` | `LandlordPropertyAnalyticsController@show` | Requise (Bearer) | Propriétaire (`owner_id`), agent délégué (`agent_id`) ou Admin |

---

## 3. Détail Technique par Fonctionnalité

### 3.1. IM — Alertes Automatiques de Nouveaux Logements

- **Événement déclencheur :** `App\Events\Listing\ListingPublished`
- **Listener dédié :** `App\Listeners\Listing\NotifyMatchingHousingSearches`
- **Notification :** `App\Notifications\Listing\NewMatchingListingNotification`
- **Règles Métier & Filtrage :**
  - Seules les annonces au statut `published` (ayant une date `published_at`) déclenchent les alertes. Les brouillons (`draft`) ou mises à jour sans publication ne génèrent aucune notification.
  - L'auteur de l'annonce (`owner_id`) est systématiquement exclu de la notification.
  - **Filtres de compatibilité multi-critères :**
    - **Ville :** Correspondance exacte ou insensible à la casse.
    - **Quartier :** Si la recherche spécifie des quartiers (`neighbourhoods`), l'annonce doit appartenir à cette liste.
    - **Fourchette budgétaire :** Le loyer/prix de l'annonce doit être compris entre `budget[0]` (min) et `budget[1]` (max).
    - **Type de bien (`kind`) :** Si la recherche spécifie une liste de types, le type de l'annonce (`flat`, `villa`, `studio`, `room`, `office`, `land`) doit correspondre.
    - **Statut de la recherche :** Seules les recherches avec `intent = 'active'` sont prises en compte (exclusion des recherches désactivées `intent = 'no'`).
  - **Déduplication & Prévention du Spam :**
    - Vérification dans la table `notifications` que l'utilisateur n'a pas déjà été notifié pour cette même annonce dans les dernières 48 heures.
    - Persistance dans les canaux `database` et `broadcast` pour affichage in-app immédiat et badge push.

---

### 3.2. PAY — Moyens de Paiement Enregistrés

- **Modèle :** `App\Models\Payment\SavedPaymentMethod`
- **Table :** `saved_payment_methods` (champs : `id`, `user_id`, `operator`, `phone_number`, `is_default`, `timestamps`).
- **Opérateurs autorisés :** `mtn`, `orange`, `wave`, `moov`, `airtel`.
- **Règles de Sécurité Strictes :**
  - **Aucun stockage de secret :** Aucun code PIN, token secret ou mot de passe opérateur n'est sollicité ni stocké.
  - **Aucun débit automatique :** L'enregistrement d'un moyen de paiement est une opération purement informative dans le carnet d'adresses de paiement du client. Aucune transaction n'est insérée dans `payment_transactions`.
  - **Masquage de sécurité :** L'attribut `masked_phone_number` masque les chiffres intermédiaires (ex: `+237 6••••••67`), protégeant les données personnelles en cas d'affichage sur les écrans partagés ou journaux réseau.
  - **Unicité du moyen par défaut :** Un utilisateur ne peut avoir qu'un seul moyen `is_default = true`. En cas de suppression du moyen par défaut, le système réélit automatiquement un autre moyen enregistré si disponible.

#### Exemple de Réponse (`201 Created`) :
```json
{
  "data": {
    "id": 1,
    "operator": "mtn",
    "phone_number": "+237 6••••••67",
    "raw_phone_number": "+237671234567",
    "is_default": true,
    "created_at": "2026-10-16T19:35:00.000000Z"
  },
  "message": "Moyen de paiement enregistré avec succès."
}
```

---

### 3.3. PAY — Génération de Reçus PDF Officiels

- **URI :** `GET /api/payments/{id}/receipt` (accessible par ID numérique ou référence alphanumérique `reference`).
- **Contrôleur :** `App\Http\Controllers\Payment\PaymentReceiptController`
- **Service Moteur :** `App\Services\Payment\ReceiptPdfService`
- **Spécifications Techniques :**
  - Génération en PHP pur d'un flux binaire conforme `%PDF-1.4` sans dépendance externe binaire (`wkhtmltopdf` ou extension non autorisée).
  - En-têtes HTTP de réponse :
    - `Content-Type: application/pdf`
    - `Content-Disposition: attachment; filename="recu-{reference}.pdf"`
    - `Cache-Control: no-cache, private`
  - **Contrôles d'intégrité :**
    - Seules les transactions au statut `confirmed` (`PaymentStatus::Confirmed`) génèrent un reçu. Les transactions `pending`, `failed` ou `cancelled` sont rejetées avec un code HTTP `422 Unprocessable Entity`.
    - Vérification stricte des droits d'accès : seul le propriétaire de la transaction (`user_id`) ou un administrateur peut télécharger le reçu (`403 Forbidden` pour tout tiers).
    - Signature d'authenticité SHA-256 intégrée au document pour prévenir toute falsification.

---

### 3.4. PAY — Déclaration d'Incidents de Double Débit

- **URI :** `POST /api/payments/double-charge-claims`
- **URI Liste :** `GET /api/payments/double-charge-claims/mine`
- **Contrôleur :** `App\Http\Controllers\Payment\DoubleChargeClaimController`
- **Modèle Réutilisé :** `App\Models\Payment\PaymentDispute` (avec `type = 'double_charge'`)
- **Règles Anti-Fraude & Déduplication :**
  - **Non-altération financière automatique :** La déclaration ne modifie jamais le statut de la transaction initiale, ne modifie pas le solde de crédits et ne déclenche aucun remboursement automatique. Le dossier est ouvert au statut `pending` pour instruction manuelle par le service financier.
  - **Déduplication par référence opérateur :** Si un litige avec la même référence d'opération opérateur (`operator_reference`) existe déjà, la requête est rejetée avec un code `422` et renvoie la référence du dossier existant.
  - **Vérification de propriété :** Si le client lie sa réclamation à une transaction existante (`transaction_id` ou `transaction_reference`), le backend vérifie que la transaction lui appartient (`403 Forbidden` sinon).

#### Exemple de Réponse (`201 Created`) :
```json
{
  "message": "Votre déclaration de double débit a été enregistrée avec succès. Notre équipe financière va instruire votre dossier.",
  "data": {
    "id": 14,
    "reference": "BNG-DCC-20261016-ABCD",
    "status": "pending",
    "operator": "mtn",
    "operator_reference": "MTN-REF-123456789",
    "disputed_amount": 5100,
    "currency": "XAF",
    "created_at": "2026-10-16T19:37:40.000000Z"
  }
}
```

---

### 3.5. BL — Analytique Immobilière Chronologique pour les Bailleurs

- **URI :** `GET /api/landlord/properties/{id}/analytics?days=30`
- **Contrôleur :** `App\Http\Controllers\Landlord\LandlordPropertyAnalyticsController`
- **Service Moteur :** `App\Services\Listing\ListingAnalyticsService`
- **Table Dédiée :** `listing_daily_metrics` (enregistre par jour et par bien : `views`, `video_views`, `favorites`, `contacts`).
- **Caractéristiques de Calcul & Performance :**
  - **Série temporelle complète garantie :** Chaque jour des 30 derniers jours (de `J-29` à `Aujourd'hui`) figure dans la série, même en l'absence totale d'événement (initialisation à 0).
  - **Consolidation multi-sources :**
    - Les vues et vues vidéo proviennent de la table `listing_daily_metrics`.
    - Les ajouts aux favoris réels proviennent de `listing_saves` agrégés par date.
    - Les demandes de visites réelles proviennent de `visit_reservations` agrégées par date.
  - **Contrôle d'accès rigoureux :** Seul le propriétaire du bien (`owner_id`), l'agent immobilier mandaté (`agent_id`) ou un administrateur a le droit de consulter l'analytique (`403 Forbidden` sinon).

#### Structure de Réponse JSON (`200 OK`) :
```json
{
  "data": {
    "listing_id": "9d4e5f6a-...",
    "period_days": 30,
    "series": [
      {
        "date": "2026-09-17",
        "views": 0,
        "video_views": 0,
        "favorites": 0,
        "contacts": 0
      },
      {
        "date": "2026-10-16",
        "views": 25,
        "video_views": 10,
        "favorites": 3,
        "contacts": 1
      }
    ],
    "totals": {
      "views": 40,
      "video_views": 15,
      "favorites": 3,
      "contacts": 1
    }
  }
}
```

---

## 4. Matrice de Sécurité, Autorisation & Intégrité des Données

| Règle de Sécurité / Métier | Implémentation Backend | Statut de Conformité |
| :--- | :--- | :--- |
| **Secrets Mobile Money** | Aucun PIN, mot de passe ou secret OTP stocké | Conforme (100 %) |
| **Paiements Fantômes** | Aucun débit lors de l'enregistrement de moyen de paiement | Conforme (Vérifié par test Pest) |
| **Remboursements Abusifs** | Aucune altération financière lors de la réclamation double débit | Conforme (Vérifié par test Pest) |
| **Fraude Reçu PDF** | Reçu généré exclusivement pour transactions au statut `confirmed` | Conforme (Vérifié par test Pest) |
| **Confidentialité Bailleur** | Propriété vérifiée (`owner_id` / `agent_id`) sur les analytiques | Conforme (Vérifié par test Pest) |
| **Notifications Non Sollicitées** | Exclusion du bailleur, déduplication 48h et respect des filtres | Conforme (Vérifié par test Pest) |

---

## 5. Synthèse des Tests Automatisés Pest

| Fichier de Test | Périmètre Couvert | Nombre de Tests | Assertions | Résultat |
| :--- | :--- | :--- | :--- | :--- |
| `tests/Feature/Listing/HousingSearchAlertsTest.php` | Alertes de publication, filtres ville/quartier/budget/type, exclusions | 3 | 8 | Passé |
| `tests/Feature/Payment/SavedPaymentMethodTest.php` | Enregistrement, liste personnelle, défaut unique, suppression, pas de débit | 7 | 34 | Passé |
| `tests/Feature/Payment/PaymentReceiptTest.php` | Génération binaire PDF, types Visite/Abonnement/Boost, rejet statut non confirmé, permissions | 6 | 15 | Passé |
| `tests/Feature/Payment/DoubleChargeClaimTest.php` | Signalement double débit, rejet doublon référence, appartenance transaction, non-remboursement | 6 | 23 | Passé |
| `tests/Feature/Landlord/LandlordPropertyAnalyticsTest.php` | Série 30 jours continue, remplissage 0, agrégations réelles, exclusion hors fenêtre, permissions | 6 | 22 | Passé |
| **TOTAL ÉTAPE 10** | **Toutes les 5 fonctionnalités de l'Étape 10** | **28** | **102** | **100 % Vert** |

---

## 6. Guide d'Intégration Frontend Mobile (React Native / Expo)

### 6.1. Écran « Mes Moyens de Paiement »
- Appeler `GET /api/payments/methods` pour afficher la liste des numéros enregistrés avec les logos opérateurs correspondants (MTN, Orange, Wave, Moov, Airtel).
- Lors de l'initiation d'un paiement (`POST /api/payments/initiate`), préremplir le champ de téléphone avec le moyen de paiement ayant `is_default = true`.
- Appeler `PATCH /api/payments/methods/{id}/default` lorsqu'un utilisateur clique sur « Définir par défaut ».

### 6.2. Écran « Historique des Transactions & Reçu »
- Dans la vue détaillée d'une transaction ayant `status === 'confirmed'`, afficher le bouton « Télécharger mon reçu PDF ».
- Déclencher l'appel `GET /api/payments/{id}/receipt` avec le token d'authentification Bearer et enregistrer le flux binaire via `expo-file-system` / `expo-sharing`.

### 6.3. Écran « Signaler un Incident / Double Débit »
- Dans les options de support d'une transaction, proposer le bouton « Déclarer un double débit ».
- Soumettre le formulaire vers `POST /api/payments/double-charge-claims` avec la référence SMS reçue de l'opérateur (`operator_reference`).

### 6.4. Tableau de Bord Bailleur « Statistiques du Bien »
- Appeler `GET /api/landlord/properties/{id}/analytics`.
- Utiliser la série `data.series` pour alimenter directement les graphiques (ex: `victory-native` ou `react-native-chart-kit`) avec les 4 métriques : Vues, Vidéos, Favoris, Contacts.
- Afficher les cartes de synthèse en haut de l'écran avec `data.totals`.
