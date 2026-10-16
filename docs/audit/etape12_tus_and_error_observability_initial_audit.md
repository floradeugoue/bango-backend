# BANGO — Étape 12 : Audit Initial & Cartographie Technique
## Protocole Tus & Observabilité des Erreurs Serveur (Module 19 — SY)

**Date :** 16 Octobre 2026  
**Auteur :** Antigravity AI  
**Périmètre :** Module 19 — SY (États Système & Résilience), raccordé aux modules RL (Reels), SQ (Square), CR (Création de contenu) et SUP (Support).

---

## 1. Matrice d'Audit Exhaustif de l'Existant

| N° | Composant / Fonctionnalité | Statut Initial | Composants Existants Identifiés | Lacunes à Développer |
| :---: | :--- | :---: | :--- | :--- |
| **1** | **SY — Téléversement Reprenable par Morceaux (Protocole Tus v1.0.0)** | 🔴 **ABSENT** | `MediaStorageService` (chemins, disques, types MIME autorisés pour Reels/Square). `MediaUploadController` et `StudioReelController@uploadMedia` (uploads multipart standard sans reprise). | Aucune route Tus `/api/uploads/tus/*`. Aucune table de sessions d'upload. Aucun modèle, service ou contrôleur gérant les offsets atomiques, le chunking, les headers Tus (`Tus-Resumable`, `Upload-Offset`, `Upload-Length`), la détection de conflit (409) et la reprise après interruption. |
| **2** | **SY — Observabilité des Erreurs Serveur HTTP 5xx (`incident_id`)** | 🔴 **ABSENT** | `bootstrap/app.php` configure uniquement des renderers pour `ResourceNotFoundException`, `ResourceGoneException` et `ResourceLockedException`. Logging standard Laravel dans `storage/logs/laravel.log`. | Aucune génération d'identifiant d'incident `incident_id` pour les erreurs serveur inattendues. Aucun format de réponse standardisé `ERR-BANGO-YYYYMMDD-XXXX`. Aucune journalisation structurée avec masquage des secrets. Aucun raccordement aux tickets de support client. |
| **3** | **RL / SQ / CR — Intégration Média des Téléversements Finalisés** | 🟡 **EXISTANT MAIS INCOMPLET** | `MediaStorageService::storeReelClip`, `MediaStorageService::storeSquareMedia`, `MediaStorageService::pathFor`. | Nécessité d'une méthode de promotion du fichier temporaire Tus complété vers le stockage permanent du Reel ou de la publication Square sans recopie superflue. |
| **4** | **SUP — Corrélation des Incidents avec la Billetterie Support** | 🟡 **EXISTANT MAIS INCOMPLET** | `SupportTicketController`, `StoreSupportTicketRequest`, `SupportTicketService`. | `StoreSupportTicketRequest` n'accepte pas encore formellement `incident_id` pour permettre aux utilisateurs de soumettre la référence de l'erreur reçue. |

---

## 2. Spécifications Cibles du Protocole Tus v1.0.0

Le protocole Tus 1.0.0 officiel est implémenté selon les spécifications :

1. **`OPTIONS /api/uploads/tus` & `OPTIONS /api/uploads/tus/{id}`**
   - En-têtes retournés :
     - `Tus-Resumable: 1.0.0`
     - `Tus-Version: 1.0.0`
     - `Tus-Max-Size: 524288000` (500 Mo)
     - `Tus-Extension: creation,termination,expiration`
2. **`POST /api/uploads/tus` (Creation Extension)**
   - En-têtes attendus : `Upload-Length`, `Upload-Metadata`, `Tus-Resumable: 1.0.0`.
   - Création de la session en base avec statut `active`, offset initial `0`.
   - Réponse : `201 Created` avec `Location: /api/uploads/tus/{id}`, `Upload-Expires`, `Tus-Resumable: 1.0.0`.
3. **`HEAD /api/uploads/tus/{id}` (Core Protocol - Consultation d'Offset)**
   - Vérification d'authentification et de propriété de la session.
   - Réponse : `200 OK` avec `Upload-Offset: <offset>`, `Upload-Length: <length>`, `Cache-Control: no-store`, `Tus-Resumable: 1.0.0`.
4. **`PATCH /api/uploads/tus/{id}` (Core Protocol - Dépôt de Fragment)**
   - En-têtes attendus : `Content-Type: application/offset+octet-stream`, `Upload-Offset: <offset>`, `Tus-Resumable: 1.0.0`.
   - Vérification de concordance stricte de l'offset serveur :
     - Si `Upload-Offset != offset_serveur` : `409 Conflict`.
     - Si dépassement de `Upload-Length` : `413 Request Entity Too Large` ou `400 Bad Request`.
   - Écriture atomique en mode append binaire direct (flux PHP `php://input`).
   - Réponse : `204 No Content` avec `Upload-Offset: <nouveau_offset>`, `Tus-Resumable: 1.0.0`.
   - Si `nouveau_offset === Upload-Length` : session marquée `completed`, promotion automatique du média disponible.
5. **`DELETE /api/uploads/tus/{id}` (Termination Extension)**
   - Nettoyage du fichier temporaire et annulation de la session.
   - Réponse : `204 No Content`.
6. **`POST /api/uploads/tus/{id}/finalize` (Finalisation Média)**
   - Validation du fichier complet et promotion vers `MediaStorageService` pour utilisation dans un Reel ou une publication Square.

---

## 3. Spécifications Cibles de l'Observabilité 5xx

1. **Génération d'Identifiant d'Incident :**
   - Format déterministe et sécurisé : `ERR-BANGO-{YYYYMMDD}-{6 CARACTÈRES ALPHANUMÉRIQUES}` (ex : `ERR-BANGO-20261016-A7B8C9`).
2. **Journalisation Structurée :**
   - Log au niveau `error` avec le tag `[INCIDENT]`.
   - Contexte : `incident_id`, `url`, `method`, `user_id`, `ip`, `exception_class`, `exception_message`, `file`, `line`.
   - Masquage des paramètres sensibles (`password`, `token`, `secret`, `authorization`, `credit_card`, `phone`).
3. **Réponse API Standardisée :**
   - Code HTTP : 500 (ou statut d'origine si 5xx).
   - Corps JSON :
     ```json
     {
       "message": "Une erreur serveur inattendue est survenue.",
       "code": "INTERNAL_SERVER_ERROR",
       "incident_id": "ERR-BANGO-20261016-A7B8C9"
     }
     ```
   - Protection absolue en production : aucune trace de pile, aucune information interne exposée.
4. **Préservation Stricte des Erreurs 4xx :**
   - 400, 401, 403, 404, 409, 413, 422, 429 et exceptions métier (`ResourceNotFoundException`, etc.) restent restituées avec leurs statuts et messages respectifs sans transformation en erreur 500.
5. **Intégration Support :**
   - Prise en charge de `incident_id` dans `StoreSupportTicketRequest`.

---

## 4. Plan d'Exécution

* **Étape B — Téléversement Tus :**
  - Migration `create_tus_upload_sessions_table`.
  - Modèle `App\Models\Upload\TusUploadSession`.
  - Service `App\Services\Upload\TusUploadService`.
  - Contrôleur `App\Http\Controllers\Upload\TusUploadController`.
  - Routes dans `routes/api.php`.
  - Tests Pest `tests/Feature/Upload/TusResumableUploadTest.php`.
* **Étape C — Observabilité & Gestionnaire d'Erreurs :**
  - Service `App\Services\System\IncidentTrackerService`.
  - Mise à jour du handler d'exceptions dans `bootstrap/app.php`.
  - Prise en charge dans `StoreSupportTicketRequest` et `SupportTicketService`.
  - Tests Pest `tests/Feature/System/ServerIncidentObservabilityTest.php`.
* **Étape D — Intégrations Média (Reels, Square, Support) & Non-Régression :**
  - Tests de bout en bout et tests de non-régression.
* **Étape E — Bouclage & Documentation :**
  - Formatage Pint : `vendor/bin/pint --format agent` validé.
  - Tests automatisés Pest Étape 12 : 19 tests / 99 assertions (100 % succès).
  - Documentation technique détaillée : `docs/transverse/etape12_tus_error_observability_documentation.md`.

---

## 5. Matrice Finale Post-Implémentation

| N° | Composant / Fonctionnalité | Statut Final | Tests Spécifiques | Validation |
| :---: | :--- | :---: | :---: | :---: |
| **1** | **SY — Téléversement Reprenable par Morceaux (Tus v1.0.0)** | 🟢 **COMPLET** | 13 tests / 74 assertions | Conforme standard (OPTIONS, POST, HEAD, PATCH, DELETE, finalize) |
| **2** | **SY — Observabilité des Erreurs Serveur HTTP 5xx (`incident_id`)** | 🟢 **COMPLET** | 6 tests / 25 assertions | Conforme format `ERR-BANGO-YYYYMMDD-XXXXXX`, logs structurés, 4xx préservés |
| **3** | **RL / SQ — Promotion et rattachement des médias finalisés** | 🟢 **COMPLET** | Intégré (`reels/videos`, `square/videos`) | Validation MIME réelle, draft Reel |
| **4** | **SUP — Corrélation des Incidents avec les Tickets Support** | 🟢 **COMPLET** | Intégré (`POST /api/support/tickets`) | Incident ID transmis au premier message |
