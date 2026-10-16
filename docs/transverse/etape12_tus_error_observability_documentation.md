# BANGO — Documentation Technique Étape 12 : Protocole Tus & Observabilité des Erreurs Serveur

**Projet :** BANGO Backend (Laravel 13 / PHP 8.4 / PostgreSQL 18.6)  
**Date :** 16 Octobre 2026  
**Statut :** Validé — 100 % opérationnel, testé (19 tests spécifiques / 99 assertions Pest)

---

## 1. Vue d'Ensemble & Objectifs

L'Étape 12 finalise et consolide le **Module 19 — SY : États Système & Résilience**, en raccordement direct avec les modules **RL (Reels)**, **SQ (Square)**, **CR (Création)** et **SUP (Support)**.

Cette étape introduit deux piliers fondamentaux de résilience et de fiabilité :
1. **Téléversement reprenable par morceaux (Protocole Tus v1.0.0)** : Permet aux utilisateurs mobiles d'envoyer des vidéos volumineuses (jusqu'à 500 Mo) sur des réseaux mobiles instables (3G/4G/5G). En cas d'interruption réseau ou de fermeture de l'application, l'upload reprend exactement à l'offset confirmé sans renvoyer les octets déjà stockés.
2. **Observabilité serveur HTTP 5xx (`incident_id`)** : Toute erreur serveur inattendue sur les API produit un identifiant unique `ERR-BANGO-YYYYMMDD-XXXXXX`. Cet identifiant est retourné au client mobile pour affichage convivial, journalisé côté serveur avec un contexte structuré et sécurisé (masquage strict des données sensibles), et communicable directement au support technique via la billetterie SUP.

---

## 2. Protocole Tus v1.0.0 — Téléversement Reprenable

### 2.1. Spécification des En-têtes & Méthodes

L'implémentation est strictement conforme aux spécifications du protocole standard Tus v1.0.0 :

| Méthode | URI | Description | En-têtes Clés Requis / Retournés |
| :--- | :--- | :--- | :--- |
| `OPTIONS` | `/api/uploads/tus` | Découverte publique des capacités du serveur Tus | `Tus-Resumable: 1.0.0`, `Tus-Version: 1.0.0`, `Tus-Max-Size: 524288000`, `Tus-Extension: creation,termination,expiration` |
| `POST` | `/api/uploads/tus` | Création d'une session de téléversement (Creation Extension) | Req: `Upload-Length`, `Upload-Metadata` / Resp: `201 Created`, `Location: /api/uploads/tus/{id}`, `Upload-Expires` |
| `HEAD` | `/api/uploads/tus/{id}` | Consultation de l'offset actuel et statut (Core Protocol) | Resp: `200 OK`, `Upload-Offset: <offset>`, `Upload-Length: <length>`, `Cache-Control: no-store` |
| `PATCH` | `/api/uploads/tus/{id}` | Envoi d'un fragment binaire à l'offset attendu (Core Protocol) | Req: `Content-Type: application/offset+octet-stream`, `Upload-Offset: <offset>`, Body binaire / Resp: `204 No Content`, `Upload-Offset: <nouvel_offset>` |
| `DELETE` | `/api/uploads/tus/{id}` | Annulation de la session et purge du fichier partiel (Termination Extension) | Resp: `204 No Content`, `Tus-Resumable: 1.0.0` |
| `POST` | `/api/uploads/tus/{id}/finalize` | Validation et promotion du fichier vers le stockage permanent | Resp: `200 OK`, descripteur média complet (`id`, `url`, `path`, `type`, `mime_type`, `file_size`) |

### 2.2. Modèle de Données & Persistance

La table `tus_upload_sessions` assure la traçabilité complète de chaque session :
* `id` (UUID) : Identifiant unique de session, non prédictible.
* `user_id` (Foreign Key) : Propriétaire de la session. L'accès par un tiers est strictement refusé (`403 Forbidden`).
* `upload_length` (BigInteger) : Taille totale déclarée en octets.
* `upload_offset` (BigInteger) : Nombre d'octets confirmés et écrits sur le disque.
* `mime_type` (String) : Type MIME déclaré et validé par inspection binaire (`finfo`).
* `original_filename` (String) : Nom initial du fichier extrait de `Upload-Metadata`.
* `temp_path` (String) : Emplacement temporaire sécurisé sur le disque (`uploads/temp/{user_id}/{uuid}.part`).
* `final_path` & `final_url` (String) : Emplacement définitif et URL publique une fois l'upload complété.
* `status` (Enum) : `active`, `completed`, `cancelled`, `expired`.
* `expires_at` (Timestamp) : Expiration automatique après 24 heures d'inactivité.

### 2.3. Concurrence, Verrouillage & Gestion des Conflits

* **Verrouillage pessimiste en base :** Toute opération d'écriture `PATCH` s'exécute dans une transaction DB avec `lockForUpdate()` sur la ligne de session, empêchant les écritures concurrentes asynchrones et non coordonnées.
* **Détection stricte de conflit d'offset :**
  Si le client envoie un `Upload-Offset` différent de l'offset réel du serveur :
  - Le serveur retourne `409 Conflict`.
  - En-tête `Upload-Offset: <offset_actuel_du_serveur>`.
  - Corps JSON explicatif (`error: 'TUS_OFFSET_CONFLICT'`).
  - Le client mobile synchronise son pointeur de lecture local et renvoie le fragment à l'offset exact sans perte de données.
* **Écriture binaire directe :** L'écriture s'effectue en mode append binaire direct (`fopen('ab')`), évitant tout dépassement de mémoire vive du processus PHP.

### 2.4. Intégration avec les Reels & Square

Lorsque l'upload atteint 100 % (`upload_offset === upload_length`) ou lors de l'appel à `/finalize` :
1. Le fichier temporaire est inspecté par `finfo_file` pour valider sa signature binaire réelle.
2. Les types MIME autorisés sont vérifiés (`video/mp4`, `video/quicktime`, `video/webm`, `image/jpeg`, etc.).
3. Le fichier est promu dans le répertoire définitif :
   - Pour les Reels : `reels/videos/{user_id}/reel_video_{id}.mp4`
   - Pour Square : `square/videos/{user_id}/square_video_{id}.mp4`
4. L'URL publique retournée est immédiatement utilisable dans `POST /api/reels/drafts`, `POST /api/reels/publish` ou `POST /api/square-cr1/posts`.

---

## 3. Observabilité des Erreurs Serveur HTTP 5xx (`incident_id`)

### 3.1. Spécification de l'Identifiant d'Incident

* **Format standardisé :** `ERR-BANGO-{YYYYMMDD}-{6 CARACTÈRES ALPHANUMÉRIQUES}`  
  *Exemple :* `ERR-BANGO-20261016-A7B8C9`
* **Propriétés :**
  - **Unique et non prédictible :** Combine la date du jour et une entropie aléatoire cryptographique.
  - **Présent dans la réponse HTTP :** En-tête `X-Incident-Id` et champ `incident_id` dans le JSON.
  - **Présent dans les journaux :** Tagué sous la forme `[INCIDENT:ERR-BANGO-...]` au niveau `error`.

### 3.2. Format de Réponse API

Pour toute erreur serveur inattendue (500, 502, 503, 504 ou exception non gérée sur `/api/*`) :
```json
{
  "message": "Une erreur serveur inattendue est survenue.",
  "code": "INTERNAL_SERVER_ERROR",
  "incident_id": "ERR-BANGO-20261016-A7B8C9"
}
```
* **Sécurité en Production :** Aucune trace de pile (stack trace), aucun chemin système, aucune requête SQL interne ni aucune variable d'environnement n'est exposée.
* **Mode Débogage (`app.debug = true`) :** Les détails d'exception sont inclus dans un nœud `debug` séparé sans altérer la structure de base.

### 3.3. Journalisation Structurée & Masquage Strict des Secrets

Le service `IncidentTrackerService` consigne dans les logs Laravel un contexte structuré :
* `incident_id`, `status_code`, `environment`, `timestamp`
* `url`, `method`, `route`, `client_ip`
* `user_id`, `user_email` (si authentifié)
* `exception_class`, `exception_message`, `file`, `line`
* **Masquage automatique des secrets :** Toute clé contenant `password`, `token`, `secret`, `authorization`, `pin`, `momo_pin`, `credit_card` ou `kyc` est automatiquement remplacée par `******** [MASQUÉ]`.

### 3.4. Préservation Absolue des Erreurs Métier 4xx

Le gestionnaire global d'exceptions dans `bootstrap/app.php` préserve sans transformation toutes les erreurs clientes et exceptions métier :
* `401 Unauthorized` (Token manquant ou révoqué)
* `403 Forbidden` (Accès interdit par une Policy)
* `404 Not Found` (Ressource inexistante ou `ResourceNotFoundException`)
* `409 Conflict` (Conflit d'offset Tus ou doublon)
* `413 Payload Too Large` (Fichier dépassant la taille maximale)
* `415 Unsupported Media Type` (Type de contenu non conforme)
* `422 Unprocessable Entity` (Erreurs de validation de formulaire avec dictionnaire `errors`)
* `429 Too Many Requests` (Limitation de débit / Rate limiting)

### 3.5. Raccordement à la Billetterie Support (Module 18 — SUP)

L'endpoint de création de ticket client `POST /api/support/tickets` accepte désormais le paramètre optionnel `incident_id` :
* Lors d'une erreur technique sur mobile, l'application peut proposer un bouton « Signaler ce problème au support » prérempli avec l'`incident_id`.
* Le ticket de support consigne l'identifiant dans son message initial, permettant aux équipes techniques d'isoler immédiatement la trace d'incident dans les logs serveur.

---

## 4. Matrice de Sécurité & Résilience

| Exigence de Sécurité / Résilience | Implémentation Backend | Statut |
| :--- | :--- | :--- |
| **Isolation des Téléversements** | Fichiers temporaires compartimentés par utilisateur (`uploads/temp/{user_id}/`). Vérification de propriété (`user_id`). | Conforme (100 %) |
| **Protection Anti-Dépassement** | Contrôle strict de `Upload-Length` et blocage des fragments excédentaires (`413`). | Conforme (100 %) |
| **Vérification Type Réel** | Inspection binaire via `finfo_file` avant finalisation pour contrer les extensions trompeuses. | Conforme (100 %) |
| **Concurrence & Intégrité** | Verrouillage transactionnel pessimiste (`lockForUpdate`) et détection de conflit d'offset (`409`). | Conforme (100 %) |
| **Masquage des Secrets** | Filtrage récursif des paramètres sensibles dans les logs d'erreurs 5xx. | Conforme (100 %) |
| **Absence de Fuite d'Informations** | Trace de pile désactivée en production sur les erreurs 500. | Conforme (100 %) |
| **Nettoyage Automatique** | Purge des sessions expirées (> 24 h) et suppression immédiate sur `DELETE`. | Conforme (100 %) |

---

## 5. Synthèse des Tests Automatisés Pest

| Fichier de Test | Périmètre Couvert | Nombre de Tests | Assertions | Résultat |
| :--- | :--- | :---: | :---: | :---: |
| `tests/Feature/Upload/TusResumableUploadTest.php` | Protocole Tus 1.0.0 (OPTIONS, POST creation, HEAD offset, PATCH chunking, 409 conflict, reprise réseau, DELETE) | 11 | 56 | Passé |
| `tests/Feature/Upload/TusMediaIntegrationTest.php` | Promotion des médias finalisés vers les Reels (`POST /api/reels/drafts`) et purge des sessions expirées | 2 | 18 | Passé |
| `tests/Feature/System/ServerIncidentObservabilityTest.php` | Format `incident_id`, capture 500, log structuré, masquage secrets, préservation 4xx, raccordement support | 6 | 25 | Passé |
| **TOTAL ÉTAPE 12** | **Protocole Tus & Observabilité Erreurs Serveur** | **19** | **99** | **100 % Vert** |

---

## 6. Guide d'Intégration Frontend Mobile (React Native / Expo)

### 6.1. Bibliothèque Client Recommandée
Utiliser le client standard `tus-js-client` dans React Native :
```javascript
import * as tus from 'tus-js-client';

const upload = new tus.Upload(fileUri, {
  endpoint: 'https://api.bango.africa/api/uploads/tus',
  retryDelays: [0, 1000, 3000, 5000],
  headers: {
    Authorization: `Bearer ${userToken}`,
  },
  metadata: {
    filename: 'video.mp4',
    filetype: 'video/mp4',
    kind: 'reel',
  },
  onError: (error) => {
    console.error('Échec upload:', error);
  },
  onProgress: (bytesUploaded, bytesTotal) => {
    const percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(2);
    setUploadProgress(percentage);
  },
  onSuccess: async () => {
    // Récupérer l'URL finale via /finalize si besoin
    const sessionId = upload.url.split('/').pop();
    const res = await api.post(`/uploads/tus/${sessionId}/finalize`);
    const mediaUrl = res.data.data.url;
  },
});

upload.start();
```

### 6.2. Gestion des Erreurs 500 & Support
Intercepter les erreurs HTTP dans le client Axios / Fetch :
```javascript
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status >= 500) {
      const incidentId = error.response.data?.incident_id;
      showErrorModal({
        title: 'Erreur inattendue',
        message: 'Une panne serveur est survenue. Veuillez réessayer dans un instant.',
        incidentId: incidentId,
        onReport: () => navigateToSupportTicket({ incidentId }),
      });
    }
    return Promise.reject(error);
  }
);
```
