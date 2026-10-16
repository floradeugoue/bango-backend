# BANGO — ÉTAPE 8 : DOCUMENTATION TECHNIQUE & CONTRATS D'API
## NOTIFICATIONS, SUPPORT & MODÉRATION, SYNCHRONISATION HORS LIGNE ET RGPD

---

### 1. Vue d'Ensemble & Architecture Globale

L'Étape 8 finalise les quatre modules transverses de BANGO :
- **Module 21 — NOTIF** : Notifications Push & In-App, gestion multi-terminaux (FCM/APNs), filtres par catégories et compteurs en temps réel.
- **Module 18 — SUP** : Signalement universel polymorphique, modération et recours contre les sanctions/suspensions (`app/appeal.tsx`).
- **Module 19 — SY** : Synchronisation hors-ligne (`POST /api/sync/flush`), rejeu idempotent d'actions mobiles et tolérance aux pannes partielles.
- **Module 3 — LG** : Droit d'accès et portabilité RGPD (`POST /api/user/data-export`), assemblage asynchrone d'archives ZIP sécurisées sans secrets.

```
+------------------------------------------------------------------------------------------------+
|                                    APPLICATION CLIENTE (MOBILE)                                |
+------------------------------------------------------------------------------------------------+
       |                                |                             |                     |
       v                                v                             v                     v
+---------------+             +--------------------+          +---------------+     +---------------+
| MODULE 21     |             | MODULE 18          |          | MODULE 19     |     | MODULE 3      |
| NOTIF         |             | SUP                |          | SY            |     | LG            |
| Notifications |             | Signalements &     |          | Sync Offline  |     | Export RGPD   |
| In-App & Push |             | Recours Modération |          | Batch Actions |     | Archive ZIP   |
+---------------+             +--------------------+          +---------------+     +---------------+
       |                                |                             |                     |
       | DatabaseNotification           | Polymorphic Report          | PostLikeService     | Asynchronous
       | DeviceToken (APNs/FCM)         | ModerationAppeal            | ReelInteraction     | ZIP Generator
       | PushNotificationService        | Suspension Linkage          | ListingService      | Signed Download
       v                                v                             v                     v
+------------------------------------------------------------------------------------------------+
|                                   BASE DE DONNÉES POSTGRESQL                                   |
+------------------------------------------------------------------------------------------------+
```

---

### 2. Module 21 — NOTIF : Notifications Push & In-App

#### 2.1. Fonctionnalités
- **Flux paginé in-app** : Notifications associées à l'utilisateur connecté via la table native `notifications`.
- **Filtres de catégories** :
  - `all` : toutes les notifications.
  - `immo` : visites immobilières (`VisitRequestedNotification`, etc.), mandats et délégations bailleur-agent (`AgentInvitationNotification`, etc.).
  - `social` : messages directs, réponses story, invitations d'équipe Page, interactions.
  - `account` : rappels de renouvellement d'abonnement, KYC, campagnes Boost, alertes de sécurité.
- **Compteur unread_count** : Recalcul dynamique des notifications non lues.
- **Acquittement de lecture** :
  - Unitaire : `POST /api/notifications/{id}/read` (avec protection 403 si la notification appartient à un tiers).
  - En lot : `POST /api/notifications/read` (alias `POST /api/notifications/mark-all-read`).
- **Enregistrement des Push Tokens** :
  - `POST /api/notifications/device-token` : Enregistre ou réassigne le token APNs/FCM en cas de bascule de compte sur un même appareil physique.
  - `DELETE /api/notifications/device-token` : Désactive le token à la déconnexion.
- **Dispatch Asynchrone** :
  - `SendPushNotificationJob` et `PushNotificationService` pour distribuer les alertes push sans impacter ni faire échouer la notification in-app si la gateway push est indisponible.

#### 2.2. Endpoints & Contrats API
- **`GET /api/notifications?category={all|immo|social|account}&unread_only={0|1}&per_page={20}`**
```json
{
  "data": [
    {
      "id": "9d90181b-5e76-47b7-b08e-599e03d4a67e",
      "category": "immo",
      "type": "visit_requested",
      "title": "Demande de visite",
      "content": "Une visite est demandée pour demain à 14h.",
      "data": {
        "slot_id": 99,
        "listing_id": "villa-bonapriso"
      },
      "read_at": null,
      "is_read": false,
      "created_at": "2026-10-16T14:30:00Z"
    }
  ],
  "unread_count": 3,
  "links": { ... },
  "meta": { ... }
}
```

- **`POST /api/notifications/device-token`**
```json
{
  "token": "fcm_token_abcdef123456...",
  "platform": "android",
  "device_id": "samsung-galaxy-s24"
}
```

---

### 3. Module 18 — SUP : Signalements Universels & Recours

#### 3.1. Signalement Universel Polymorphique (`POST /api/reports`)
Permet de signaler n'importe quel contenu légitime de l'application :
- Cibles autorisées : `post`, `post_comment`, `reel`, `reel_comment`, `listing`, `message`, `user` (profil), `page`.
- Motifs normalisés (`ReportReason`) : `spam`, `fraud`, `harassment`, `inappropriate_content`, `copyright`, `fake_listing`, `violence`, `hate_speech`, `other`.
- **Garde-fous** :
  - Détection et interdiction de l'auto-signalement abusif (`422 Unprocessable Entity`).
  - Déduplication : si un signalement pour ce contenu est déjà en cours par l'utilisateur, retourne `200 OK` avec message d'information sans doubler la charge de modération.
  - Anonymat strict : l'identité du reporter n'est jamais exposée publiquement.

#### 3.2. Recours contre une Sanction (`POST /api/moderation/appeals`)
Permet à un utilisateur sanctionné de contester une mesure :
- Types de sanction : `account_suspension`, `page_suspension`, `content_removal`, `warning`.
- **Règles métier impératives** :
  - Vérification de la légitimité : un utilisateur avec un compte actif ne peut pas déposer un recours pour suspension de compte.
  - Pour une Page, le demandeur doit être membre autorisé (`owner` ou `editor`) et la Page doit être suspendue.
  - **Le dépôt d'un recours NE LÈVE PAS automatiquement la suspension.**
  - Prévention des recours en doublon pour une même sanction en cours d'instruction.

---

### 4. Module 19 — SY : Synchronisation Hors-Ligne (`POST /api/sync/flush`)

#### 4.1. Protocole & Rejeu
Lors d'une reconnexion réseau, le client mobile vide son stockage SQLite en soumettant un lot d'actions horodatées :
- Actions supportées :
  - `like_post` / `unlike_post`
  - `like_reel` / `save_reel` / `view_reel`
  - `bookmark_listing` / `unbookmark_listing`
  - `follow_user` / `unfollow_user`
- Taille de lot : 1 à 50 actions maximum.
- **Isolation des pannes** : Chaque action est traitée indépendamment. Si un Reel est supprimé ou introuvable, l'action associée renvoie `status: "failed"` sans annuler les likes ou favoris valides du même lot.
- **Idempotence intra-lot et réseau** : Renvoyer `already_applied` si l'action a déjà été exécutée, évitant les compteurs aberrants.

#### 4.2. Exemple de Contrat Sync
- **Requête :**
```json
{
  "actions": [
    {
      "action_id": "local-uuid-001",
      "type": "like_post",
      "target_id": "42"
    },
    {
      "action_id": "local-uuid-002",
      "type": "view_reel",
      "target_id": "9d90181b-5e76-47b7-b08e-599e03d4a67e"
    }
  ]
}
```
- **Réponse (200 OK) :**
```json
{
  "processed_count": 2,
  "success_count": 2,
  "failed_count": 0,
  "results": [
    {
      "action_id": "local-uuid-001",
      "type": "like_post",
      "target_id": "42",
      "status": "success",
      "error": null
    },
    {
      "action_id": "local-uuid-002",
      "type": "view_reel",
      "target_id": "9d90181b-5e76-47b7-b08e-599e03d4a67e",
      "status": "already_applied",
      "error": null
    }
  ]
}
```

---

### 5. Module 3 — LG : Export RGPD des Données Personnelles

#### 5.1. Cycle de Vie Asynchrone
1. **Demande** : `POST /api/user/data-export`
   - Crée une demande en statut `pending`.
   - Empêche les demandes multiples concurrentes.
   - Dispatche le job `GenerateDataExportJob`.
   - Répond immédiatement `202 Accepted`.
2. **Suivi** : `GET /api/user/data-export/status`
   - Indique l'état (`pending`, `processing`, `ready`, `failed`, `expired`).
   - Fournit l'URL temporaire de téléchargement dès que l'archive est prête.
3. **Téléchargement** : `GET /api/user/data-export/download/{id}`
   - Téléchargement du fichier ZIP généré.
   - Protection IDOR stricte (403 si un tiers tente d'accéder à l'archive).
   - Expiration automatique après 48h (410 Gone).

#### 5.2. Périmètre de l'Archive ZIP & Règles de Sécurité
L'archive contient les fichiers JSON suivants :
- `profile.json` : Identité publique, coordonnées, informations de compte.
- `preferences_and_privacy.json` : Paramètres de confidentialité, consentement publicitaire (`ads_personalization`), centres d'intérêt.
- `square_posts.json` : Publications créées par l'utilisateur.
- `reels.json` : Vidéos Reels de l'utilisateur.
- `listings.json` : Annonces immobilières publiées par l'utilisateur.
- `boost_campaigns.json` : Historique des campagnes de sponsoring et budgets.
- `transactions.json` : Portefeuille Crédit BANGO et écritures comptables.
- `subscriptions.json` : Historique des abonnements et badges.

> [!CAUTION]
> **Exclusion formelle des secrets :** L'exporteur omet strictement `password`, `two_factor_secret`, `backup_codes`, `remember_token`, tokens Passport et identifiants techniques tiers. Les données privées de tiers (messages reçus dans d'autres conversations, coordonnées d'autres membres) sont exclues.

---

### 6. Matrice d'Intégration Transverse des 18 Modules

| Module | Fonctionnalité & Raccordement | Statut | Preuve de Validation |
| :--- | :--- | :---: | :--- |
| **NOTIF (21)** | Centre de notifications, filtres catégories, device tokens FCM/APNs | **Validé** | [NotificationTest.php](file:///c:/Users/DEUGOUE%20Floriane/Documents/GitHub/bango_backend/tests/Feature/Notification/NotificationTest.php) (11 tests passés) |
| **SUP (18)** | Signalement universel (Post, Reel, Comment, Listing, Msg, Page, User) & Recours | **Validé** | [ReportAndModerationAppealTest.php](file:///c:/Users/DEUGOUE%20Floriane/Documents/GitHub/bango_backend/tests/Feature/Support/ReportAndModerationAppealTest.php) (14 tests passés) |
| **SY (19)** | Synchronisation offline, idempotence, rejeu de likes/saves/views/follows | **Validé** | [OfflineSyncTest.php](file:///c:/Users/DEUGOUE%20Floriane/Documents/GitHub/bango_backend/tests/Feature/Sync/OfflineSyncTest.php) (5 tests passés) |
| **LG (3)** | Export RGPD personnel asynchrone, ZIP sans secrets, protection IDOR | **Validé** | [DataExportTest.php](file:///c:/Users/DEUGOUE%20Floriane/Documents/GitHub/bango_backend/tests/Feature/Legal/DataExportTest.php) (6 tests passés) |
| **MS (13)** | Notification in-app de message & signalement de message privé | **Validé** | `NewMessageNotification`, Report target `message` |
| **IM (7)** | Alertes visites, signalement annonce frauduleuse, sync favoris offline | **Validé** | `VisitRequestedNotification`, `bookmark_listing` |
| **BL (8)** | Notifications de visite bailleur & mandats | **Validé** | `AgentInvitationNotification`, etc. |
| **AG (9)** | Invitations de mandat agent & visites affectées | **Validé** | Notifications Delegation & Visit |
| **SQ (4)** | Likes Square, commentaires, signalement de publication, sync offline | **Validé** | `like_post`, Report target `post` |
| **RL (5)** | Réactions Reels, vues offline, signalement Reel vidéo | **Validé** | `like_reel`, `view_reel`, Report target `reel` |
| **BO (10)** | Notifications de campagne (`activated`, `suspended`, `completed`), export | **Validé** | `CampaignActivatedNotification`, export `boost_campaigns.json` |
| **SUB / AB (11)** | Notifications d'échéances et de relances de paiement, export abonnements | **Validé** | `RenewalUpcomingReminderNotification`, export `subscriptions.json` |
| **PG (12)** | Notifications invitations d'équipe, certification, signalement Page, recours | **Validé** | `PageMemberInvitedNotification`, recours `page_suspension` |
| **PR (15)** | Profil exporté sans mot de passe, sync de follow offline, signalement profil | **Validé** | `follow_user`, Report target `user`, export `profile.json` |
| **ST (16)** | Sécurité, tokens d'appareils actifs, révocation de token à la déconnexion | **Validé** | `DELETE /api/notifications/device-token` |
| **PAY (17)** | Transactions et solde Crédit BANGO exportés dans l'archive RGPD | **Validé** | Export `transactions.json` |
| **EDGE (20)** | Cohérence des sanctions : suspension conservatoire sans levée automatique | **Validé** | `ModerationAppealTest` : compte reste suspendu après recours |
| **AU (2)** | Compte suspendu autorisé à soumettre un recours de modération | **Validé** | `POST /api/moderation/appeals` avec compte suspendu |
