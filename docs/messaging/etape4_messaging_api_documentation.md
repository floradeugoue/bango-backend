# BANGO — Documentation Technique : Étape 4 (MS — Messagerie Privée & Intégration CR3 Story)

## 1. Vue d'ensemble de l'Architecture

Le module **MS (Messagerie Privée)** de BANGO fournit une infrastructure de communication sécurisée, temps réel et contextuelle intégrée aux écosystèmes existants :
- **Direct 1:1** : conversations privées entre utilisateurs.
- **Contexte Immobilier (IM / BL / AG)** : bandeau fixe contextualisé avec les données de l'annonce (`listing_id`), loyer, statut, et contrôle strict des droits des agents (`canReplyMessages`).
- **Multimédia & Notes Vocales** : enregistrement, stockage sécurisé et streaming de notes vocales (mp3, m4a, ogg, wav, aac) avec calcul de durée.
- **Groupes** : création de groupes jusqu'à 100 participants avec administrateurs, ajout/retrait et promotion automatique du membre le plus ancien si le dernier admin quitte (MS-17).
- **CR3 Stories** : branchement direct de l'événement `StoryReplyCreated` via un listener idempotent créant automatiquement la conversation et le message avec snapshot immuable de la Story (`metadata.story_preview_card`).
- **Temps Réel (Reverb & Broadcasting)** : diffusion sur canaux privés autorisés (`chat.conversation.{id}`, `chat.user.{id}`).
- **Règles EDGE & Sécurité** : blocage silencieux (MS-21 / EDGE-22 : l'utilisateur bloqué ne reçoit aucune confirmation de blocage, ses messages sont marqués `undelivered` sans notification au bloqueur), anonymisation des comptes supprimés (EDGE-15) et protection stricte contre les IDOR.

---

## 2. Spécification des Endpoints API

Toutes les routes de messagerie sont préfixées par `/api/messages` et protégées par le middleware d'authentification Passport `auth:api`.

### 2.1 Conversations & Fils de Discussion

#### `GET /api/messages/threads`
Récupère la liste paginée des conversations de l'utilisateur connecté, triées par date du dernier message décroissante (`last_message_at DESC`).

- **Query Parameters** :
  - `type` (optionnel, string) : `immo` (fils liés à un bien), `direct` (fils 1:1 sans bien), `group` (groupes).
  - `search` (optionnel, string) : recherche textuelle sur le titre ou le nom/username des participants.
  - `per_page` (optionnel, integer, défaut: 20) : nombre de résultats par page.
- **Réponse HTTP 200 OK** :
```json
{
  "data": [
    {
      "id": "01a14467-33a1-7788-bbf3-010203040506",
      "thread_id": "01a14467-33a1-7788-bbf3-010203040506",
      "type": "direct",
      "title": null,
      "avatar_url": null,
      "listing_id": "01a14467-9999-7777-8888-010203040506",
      "listing": {
        "id": "01a14467-9999-7777-8888-010203040506",
        "title": "Appartement standing Bonapriso",
        "price": 250000,
        "currency": "XAF",
        "status": "published",
        "mode": "rent",
        "kind": "apartment",
        "cover_image": "https://bango.test/storage/listings/cover.jpg",
        "owner_id": 12,
        "agent_id": 45
      },
      "members": [
        {
          "id": 1,
          "user_id": 10,
          "role": "member",
          "is_muted": false,
          "last_read_message_id": "01a14467-...",
          "last_read_at": "2026-10-16T12:00:00Z",
          "joined_at": "2026-10-15T10:00:00Z",
          "user": {
            "id": 10,
            "name": "Alice N.",
            "username": "alice",
            "avatar_url": "https://bango.test/storage/avatars/alice.jpg",
            "is_verified": true,
            "account_type": "personal"
          }
        }
      ],
      "latest_message": {
        "id": "01a14467-...",
        "type": "text",
        "body": "Bonjour, le logement est-il toujours libre ?",
        "created_at": "2026-10-16T12:05:00Z"
      },
      "last_message_at": "2026-10-16T12:05:00Z",
      "unread_count": 1,
      "created_at": "2026-10-15T10:00:00Z"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

#### `POST /api/messages/threads`
Crée ou récupère une conversation directe 1:1, potentiellement contextualisée à une annonce.
- **Corps JSON** :
  - `peer_id` (requis, integer, exists:users,id, différent de soi-même) : identifiant de l'interlocuteur.
  - `listing_id` (optionnel, uuid, exists:listings,id) : identifiant du bien immobilier concerné.
  - `metadata` (optionnel, array) : données contextuelles supplémentaires.
- **Comportement & Idempotence** : Si un fil direct existe déjà entre les deux utilisateurs pour cette annonce (ou sans annonce), il est réutilisé sans duplication.
- **Réponse HTTP 201 Created**.

#### `GET /api/messages/threads/{id}`
Récupère le détail d'une conversation.
- **Autorisation** : L'utilisateur connecté doit être membre actif de la conversation (`Gate::authorize('view', $thread)`).
- **Réponse HTTP 200 OK** ou **403 Forbidden**.

#### `POST /api/messages/threads/{id}/read` (et `PUT`)
Marque la conversation comme lue jusqu'au message spécifié.
- **Corps JSON** :
  - `message_id` (optionnel, uuid) : dernier message lu.
- **Événement émis** : `App\Events\Message\MessageRead` diffusé sur `chat.conversation.{id}`.
- **Réponse HTTP 200 OK** : `{"success": true, "message": "Conversation marquée comme lue."}`.

---

### 2.2 Messages & Notes Vocales

#### `GET /api/messages/threads/{id}/messages`
Récupère les messages d'un fil de discussion par ordre chronologique avec pagination.
- **Query Parameters** :
  - `per_page` (optionnel, integer, défaut: 30)
- **Réponse HTTP 200 OK** :
```json
{
  "data": [
    {
      "id": "01a14467-...",
      "conversation_id": "01a14467-...",
      "thread_id": "01a14467-...",
      "sender_id": 10,
      "sender": {
        "id": 10,
        "name": "Alice",
        "username": "alice",
        "avatar_url": "https://bango.test/...",
        "is_verified": true
      },
      "type": "text",
      "kind": "text",
      "body": "Bonjour, le créneau de visite de samedi est-il confirmé ?",
      "client_message_id": "4e73b06c-...",
      "reply_to_message_id": null,
      "reply_to": null,
      "attachment_url": null,
      "attachment_mime": null,
      "attachment_size": null,
      "attachment_duration_ms": null,
      "metadata": {},
      "is_retracted": false,
      "retracted_at": null,
      "created_at": "2026-10-16T12:10:00Z"
    }
  ]
}
```

#### `POST /api/messages/threads/{id}/messages`
Envoie un message dans un fil de discussion.
- **Corps JSON** :
  - `body` (requis sauf si pièce jointe, string, max: 5000) : contenu texte.
  - `type` (optionnel, string, in: `text`, `voice`, `image`, `listing`, `story_reply`, `system`) : défaut `text`.
  - `client_message_id` (optionnel, string, max: 128) : clé d'idempotence client (UUID recommandé).
  - `reply_to_message_id` (optionnel, uuid, exists:messages,id) : réponse ciblée à un message précédent.
  - `metadata` (optionnel, array) : données variables.
- **Règles de sécurité & métier vérifiées** :
  1. Appartenance : l'utilisateur doit être membre actif (sinon 403).
  2. Mandat Agent (MS-20) : si le fil concerne un bien immobilier (`listing_id`), un agent mandaté ne peut répondre que si son mandat est actif et possède la capacité `reply`. S'il n'a pas la capacité ou est révoqué, l'envoi est bloqué avec **403 Forbidden**.
  3. Blocage silencieux (MS-21 / EDGE-22) : si le destinataire a bloqué l'expéditeur, l'envoi renvoie **201 Created** à l'expéditeur, mais le message est marqué `metadata.undelivered = true` et n'est ni diffusé en WebSocket ni notifié au destinataire.
  4. Idempotence : si un message avec le même `client_message_id` existe déjà dans ce fil, le message existant est retourné sans duplication.
- **Réponse HTTP 201 Created**.

#### `POST /api/messages/threads/{id}/voice`
Téléverse et publie une note vocale dans le fil.
- **Corps `multipart/form-data`** :
  - `voice` (requis, file, max: 25 Mo, mimes: `mp3`, `m4a`, `ogg`, `wav`, `aac`) : fichier audio.
  - `duration_ms` (optionnel, integer, min: 100, max: 3600000) : durée en millisecondes.
  - `client_message_id` (optionnel, string, max: 128) : clé d'idempotence.
  - `reply_to_message_id` (optionnel, uuid) : message auquel on répond.
- **Stockage** : Fichier stocké sous `messages/voices/{thread_id}/{uuid}.{ext}` sur le disque configuré (`media.disk`, défaut `public`). Nettoyage automatique en cas d'échec de persistance.
- **Réponse HTTP 201 Created** avec `type = "voice"`, `attachment_duration_ms` et `attachment_url`.

#### `POST /api/messages/threads/{id}/messages/{messageId}/retract` (et `DELETE`)
Rétractation d'un message par son expéditeur.
- **Règles** : Seul l'auteur initial du message peut le rétracter (sinon 403). Le corps textuel et le chemin de pièce jointe sont purgés (`null`), et le drapeau `is_retracted = true` est persisté avec horodatage `retracted_at`.
- **Réponse HTTP 200 OK**.

---

### 2.3 Conversations de Groupe

#### `POST /api/messages/groups`
Crée une nouvelle conversation de groupe.
- **Corps JSON** :
  - `title` (requis, string, 2-100 caractères) : nom du groupe.
  - `members` (requis, array d'entiers, 1 à 100 membres) : identifiants des utilisateurs à ajouter.
  - `avatar_url` (optionnel, url, max: 2048) : photo de profil du groupe.
- **Règles** : Le créateur est automatiquement assigné comme `admin`. Les membres bloqués réciproquement sont filtrés. Chaque membre reçoit une notification `AddedToGroupNotification`.
- **Réponse HTTP 201 Created**.

#### `POST /api/messages/groups/{id}/members`
Ajoute de nouveaux membres à un groupe.
- **Autorisation** : Réservé aux administrateurs du groupe (`Gate::authorize('addMembers', $group)`).
- **Corps JSON** :
  - `members` (requis, array, min: 1, `members.*`: exists:users,id).
- **Réponse HTTP 200 OK**.

#### `DELETE /api/messages/groups/{id}/members/{userId}`
Retire un membre du groupe ou permet à un membre de quitter le groupe.
- **Autorisation** : L'administrateur peut retirer n'importe quel membre ; un membre peut se retirer lui-même.
- **Règle MS-17** : Si le **dernier administrateur** quitte le groupe, le système promeut automatiquement le membre le plus ancien (`role = 'admin'`).
- **Réponse HTTP 200 OK**.

---

## 3. Branchement CR3 : Événement `StoryReplyCreated`

### 3.1 Flux d'Intégration
```
[Utilisateur répond à une Story]
             │
             ▼
POST /api/stories/{id}/reply
             │
             ▼
StoryService::createReply()
  ├── Persiste tuple story_replies (status = 'pending')
  ├── Fige le snapshot immuable dans story_replies.metadata
  └── Dispatche StoryReplyCreated($reply)
             │
             ▼
CreateConversationFromStoryReply Listener (ShouldQueue)
  ├── 1. Vérification Idempotence (status === 'processed' ou future_message_id renseigné)
  ├── 2. Identification du répondant (user_id) et de l'auteur de la Story (story_author_id)
  ├── 3. MessageThreadService::getOrCreateDirectConversation($respondent, $author->id)
  ├── 4. MessageService::sendMessage() avec type = 'story_reply' et metadata.story_preview_card
  ├── 5. Mise à jour atomique : future_conversation_id, future_message_id, status = 'processed'
  └── 6. Diffusion temps réel MessageSent + Notification NewStoryReplyNotification
```

### 3.2 Snapshot Immuable de la Story
Afin que la carte d'aperçu de la Story reste visible dans la messagerie même si la Story originale **expire après 24 heures ou est supprimée**, le listener réutilise strictement le snapshot déjà figé dans `story_replies.metadata` :
- `story_id` : identifiant UUID de la Story.
- `author_name` & `author_handle` : identité de l'auteur au moment de la publication.
- `media_url` & `thumbnail_url` : liens vers les médias de la vignette.
- `caption` : légende textuelle associée.

### 3.3 Idempotence et Résilience
- La méthode `handle` du listener commence par vérifier si `$reply->future_message_id` ou `$reply->status === 'processed'` est présent.
- Si le job de queue est réémis ou rejoué, aucun second message ni conversation dupliquée n'est créé.
- Le message est rattaché à une clé idempotente déterministe `story_reply_{$reply->id}`.

---

## 4. Laravel Reverb & Broadcasting Temps Réel

### 4.1 Canaux de Diffusion
Tous les canaux de messagerie sont **privés** :
1. `private-chat.conversation.{conversationId}` :
   - Écoute les messages envoyés (`message.sent`), les accusés de lecture (`message.read`) et les rétractations.
   - **Autorisation** : L'utilisateur authentifié doit être membre actif (`isMember($user->id)`) de la conversation. Les non-membres sont rejetés avec un code 403.
2. `private-chat.user.{userId}` :
   - Écoute les notifications personnelles et les créations de conversations/groupes (`conversation.created`).
   - **Autorisation** : L'utilisateur authentifié ne peut écouter que son propre identifiant (`$user->id === (int) $userId`).

### 4.2 Événements Broadcastés
| Événement PHP | Nom d'Événement WebSocket | Canal | Payload |
| :--- | :--- | :--- | :--- |
| `App\Events\Message\MessageSent` | `message.sent` | `chat.conversation.{id}` & `chat.user.{recipient}` | Identifiant du message, conversation, expéditeur, type, corps, attachement, métadonnées, date ISO |
| `App\Events\Message\ConversationCreated` | `conversation.created` | `chat.user.{member_id}` pour chaque membre | Identifiant, type, titre, listing_id, date |
| `App\Events\Message\MessageRead` | `message.read` | `chat.conversation.{id}` | conversation_id, user_id, last_read_message_id, read_at |

### 4.3 Résilience WebSocket
La persistance en base de données PostgreSQL est la **seule source de vérité**. Si Laravel Reverb est indisponible ou si la connexion WebSocket d'un client est interrompue :
- Les messages et statuts de lecture sont correctement enregistrés en base.
- L'application mobile se synchronise automatiquement au rétablissement du réseau via l'endpoint de pagination `GET /api/messages/threads/{id}/messages`.

---

## 5. Guide d'Exploitation & Commandes Utiles

### Migrations
```bash
# Exécuter les migrations de la messagerie
php artisan migrate

# Vérifier le statut des tables
php artisan migrate:status
```

### Démarrage des Services
```bash
# Démarrer le serveur Laravel Reverb (WebSockets)
php artisan reverb:start

# Démarrer le worker de queue pour les notifications et le listener Story
php artisan queue:work --tries=3 --timeout=60
```

### Exécution des Tests
```bash
# Tests ciblés de la messagerie
php artisan test tests/Feature/Message

# Suite complète de l'application
php artisan test --compact
```
