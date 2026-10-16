# BANGO — AUDIT TECHNIQUE INITIAL : ÉTAPE 8
## NOTIFICATIONS, SUPPORT & MODÉRATION, SYNCHRONISATION HORS LIGNE ET RGPD

**Date :** 16 Octobre 2026  
**Environnement :** Laravel 13, PostgreSQL, PHP 8.4, Pest PHP  
**Statut initial de la suite de tests :** 673 tests passés (3 181 assertions) ✅  

---

### 1. Synthèse de l'Audit par Module Cœur

| Module | Fonctionnalité | État constaté | Éléments existants identifiés | Action requise |
| :--- | :--- | :---: | :--- | :--- |
| **NOTIF (21)** | Notifications in-app | Incomplet | Table standard `notifications` migrée (`2026_10_14_115652_create_notifications_table.php`). Trait `Notifiable` sur `User`. Classes de notifications existantes (Boost, Delegation, Kyc, Message, Page, Subscription, Visit). | Développer `NotificationController` (`GET /api/notifications` avec filtres `category`, pagination, compteurs non-lus ; `POST /api/notifications/read` et `POST /api/notifications/{id}/read`). |
| **NOTIF (21)** | Device tokens (FCM/APNs) | Absent | Aucun modèle ni table de stockage des push tokens. | Créer la migration `device_tokens`, modèle `DeviceToken`, `DeviceTokenController` (`POST /api/notifications/device-token`), validation stricte (plateforme `android`/`ios`, token, device_id), unicité et gestion de la bascule de compte. |
| **NOTIF (21)** | Envoi Push asynchrone | Non branché | Notifications envoyées uniquement via le canal `database`. | Mettre en place un service push / job `SendPushNotificationJob` résilient (ne bloque pas et ne fait pas échouer la notification in-app en cas d'erreur de push gateway). |
| **SUP (18)** | Signalement universel | Incomplet | `StoryReport` existe mais est restreint au modèle `Story`. Aucun endpoint universel. | Créer la table `reports` polymorphique, modèle `Report`, `ReportController` (`POST /api/reports`, `GET /api/reports/mine`), validation des motifs, anonymat du déclarant, cibles : `Post`, `Reel`, `PostComment`/`ReelComment`, `Listing`, `Message`, `User` (profil), `Page`. |
| **SUP (18)** | Recours de modération | Absent | Champs de suspension sur `User` (`status = 'suspended'`, `suspension_reason`, etc.), écran mobile `app/appeal.tsx` référencé, mais aucun modèle ni route backend. | Créer la table `moderation_appeals`, modèle `ModerationAppeal`, `ModerationAppealController` (`POST /api/moderation/appeals`), traçabilité des décisions sans levée automatique de suspension. |
| **SY (19)** | Synchronisation offline | Absent | Aucun endpoint de sync en lot. | Créer `OfflineSyncController` (`POST /api/sync/flush`), validation des actions (`like_post`, `bookmark_listing`, `save_post`, `view_reel`, `follow_user`), déduplication par `action_id` / idempotency, isolation des échecs partiels. |
| **LG (3)** | Export RGPD personnel | Absent | Aucun endpoint ni job d'export de données personnelles. | Créer la table `data_export_requests`, modèle `DataExportRequest`, job asynchrone `GenerateDataExportJob`, service d'assemblage `DataExportService` (profil, posts, listings, transactions, abonnements, SANS secrets ni données tierces), téléchargement privé temporaire (`POST /api/user/data-export`, `GET /api/user/data-export/status`, `GET /api/user/data-export/download/{id}`). |

---

### 2. Plan d'Implémentation Séquentiel

1. **Phase 1 — Module 21 : NOTIF**
   - Migration `device_tokens` (`user_id`, `token`, `platform`, `device_id`, timestamps, unique `token`).
   - Modèle `DeviceToken` avec relation sur `User`.
   - `DeviceTokenController` (`POST /api/notifications/device-token`).
   - `NotificationController` (`GET /api/notifications`, `POST /api/notifications/read`, `POST /api/notifications/{id}/read`).
   - Filtres de catégories : `all`, `immo`, `social`, `account`.
   - Compteur `unread_count`.
   - Service Push simulable / Job d'envoi push avec fakes de test.
   - Tests Pest complets pour NOTIF.

2. **Phase 2 — Module 18 : SUP**
   - Migration `reports` polymorphique (`id`, `reporter_id`, `reportable_type`, `reportable_id`, `reason`, `details`, `status`, `metadata`, `resolved_at`, timestamps).
   - Modèle `Report` et Enum `ReportReason`, `ReportStatus`.
   - Migration `moderation_appeals` (`id`, `user_id`, `sanction_type`, `sanction_reference`, `reason`, `explanation`, `status`, `reviewed_at`, `reviewed_by`, timestamps).
   - Modèle `ModerationAppeal` et Form Requests.
   - `ReportController` (`POST /api/reports`, `GET /api/reports/mine`).
   - `ModerationAppealController` (`POST /api/moderation/appeals`).
   - Tests Pest complets pour SUP.

3. **Phase 3 — Module 19 : SY**
   - `OfflineSyncController` (`POST /api/sync/flush`).
   - `OfflineSyncService` pour l'exécution atomique / isolée des actions candidates.
   - Prise en charge des actions : `like_post`, `save_post`, `bookmark_listing`, `view_reel`, `follow_user`.
   - Idempotence et déduplication des événements.
   - Tests Pest complets pour SY.

4. **Phase 4 — Module 3 : LG**
   - Migration `data_export_requests` (`id`, `user_id`, `status`, `file_path`, `disk`, `expires_at`, `completed_at`, timestamps).
   - Modèle `DataExportRequest`.
   - Job `GenerateDataExportJob` générant une archive ZIP privée.
   - `DataExportController` (`POST /api/user/data-export`, `GET /api/user/data-export/status`, `GET /api/user/data-export/download/{id}`).
   - Filtrage strict des secrets (mots de passe, tokens, secrets 2FA exclus) et des données privées tierces.
   - Tests Pest complets pour LG.

5. **Phase 5 & 6 — Intégration Transverse des 18 Modules & Qualité**
   - Vérification des interactions entre NOTIF, SUP, SY, LG et les 14 autres modules.
   - Validation globale de la suite de tests Pest et formatage Pint.
   - Clôture et rapport final.
