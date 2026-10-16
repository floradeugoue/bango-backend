# BANGO — Documentation Technique : Module SUP (Centre d’Aide FAQ & Billetterie Support Client)

**Module :** 18 — SUP : Support, Centre d'Aide & Billetterie Client  
**Étape de développement :** Étape 11  
**Statut :** 🟢 Opérationnel et validé par tests Pest automatisés  
**Date d'actualisation :** 16 Octobre 2026  

---

## 1. Vue d'Ensemble & Objectifs

L'Étape 11 a pour objectif de remplacer les données d'aide locales ou statiques du frontend mobile (`lib/help/fr.ts`) par un système centralisé, dynamique et sécurisé dans le backend Laravel 13 :
1. **Base de connaissances FAQ dynamique (SUP-01 à SUP-03)** : Distribution d'articles d'aide catégorisés, recherche plein texte sécurisée (compatible PostgreSQL / SQLite), consultation par slug avec comptage des vues et vote d'utilité idempotent (utile / inutile).
2. **Billetterie de support client (SUP-04 à SUP-08)** : Système complet de tickets d'assistance avec référence publique non prédictible (`TIC-YYYY-XXXX`), message initial obligatoire, pièces jointes privées sécurisées (disque local non public), fil de conversation et transitions de statut automatiques.
3. **Back-office & Traitement par les agents (SUP-09)** : Interface pour agents et administrateurs permettant de lister les tickets avec filtres avancés, d'assigner les dossiers, d'actualiser les statuts et de consigner des notes internes strictement masquées aux clients.

---

## 2. Architecture & Modèle de Données

```
+-------------------+        1:N        +-------------------------+
|   help_articles   |<------------------|  help_article_feedbacks |
+-------------------+                   +-------------------------+
| id                |                   | id                      |
| slug (unique)     |                   | help_article_id (FK)    |
| category          |                   | user_id (FK, nullable)  |
| title             |                   | ip_address (nullable)   |
| content_markdown  |                   | session_id (nullable)   |
| helpful_count     |                   | is_helpful (boolean)    |
| unhelpful_count   |                   +-------------------------+
| views_count       |
| is_published      |
| published_at      |
| locale            |
| order             |
+-------------------+

+-------------------------+        1:N        +-------------------------+        1:N        +-------------------------------+
|     support_tickets     |<------------------| support_ticket_messages |<------------------|  support_ticket_attachments   |
+-------------------------+                   +-------------------------+                   +-------------------------------+
| id                      |                   | id                      |                   | id                            |
| reference (unique)      |                   | support_ticket_id (FK)  |                   | support_ticket_id (FK)        |
| user_id (FK)            |                   | sender_id (FK, nullable)|                   | support_ticket_message_id (FK)|
| assigned_to (FK, null)  |                   | sender_type (enum)      |                   | file_path                     |
| subject                 |                   | message (text)          |                   | original_name                 |
| category (enum)         |                   | is_internal (boolean)   |                   | mime_type                     |
| status (enum)           |                   | metadata (json, null)   |                   | file_size                     |
| priority (enum)         |                   +-------------------------+                   | disk (default: 'local')       |
| last_reply_at           |                                                                 +-------------------------------+
| resolved_at             |
| closed_at               |
+-------------------------+
```

---

## 3. Enums Métier

### 3.1. Catégories (`TicketCategory` / `HelpArticleCategory`)
- `account_auth` : Compte et authentification
- `payment_wallet` : Paiement et portefeuille
- `subscription_badge` : Abonnement et badge
- `real_estate_visits` : Immobilier et visites
- `publishing_content` : Publication et contenu
- `messaging` : Messagerie
- `pro_pages` : Pages Pro
- `security_report` : Sécurité et signalement
- `other` : Autre

### 3.2. Statuts (`TicketStatus`)
- `open` : Ouvert (nouveau ticket créé, en attente de prise en charge)
- `in_progress` : En cours de traitement (pris en charge par le support)
- `waiting_for_user` : En attente de réponse du client
- `resolved` : Résolu (intervention effectuée)
- `closed` : Fermé (dossier définitivement clos)

### 3.3. Priorités (`TicketPriority`)
- `low` : Basse
- `normal` : Normale (défaut)
- `high` : Haute
- `urgent` : Urgente

### 3.4. Types d'expéditeurs (`TicketMessageSenderType`)
- `client` : Message rédigé par le client propriétaire du ticket
- `support` : Réponse rédigée par un agent de support ou un administrateur
- `system` : Notification système automatique
- `internal_note` : Note interne réservée à l'équipe support (invisible au client)

---

## 4. Spécification des Endpoints API

### 4.1. Base de Connaissances FAQ (Public)

#### A. Liste et recherche des articles
- **Route :** `GET /api/help/articles`
- **Accès :** Public
- **Paramètres de requête :**
  - `q` : Recherche textuelle dans le titre et le contenu Markdown.
  - `category` : Filtrage par catégorie (`account_auth`, `payment_wallet`, etc.).
  - `locale` : Langue de l'article (`fr` par défaut).
  - `page` : Numéro de page (défaut 1).
  - `per_page` : Éléments par page (défaut 15, max 50).
- **Réponse HTTP 200 :**
```json
{
  "data": [
    {
      "id": 1,
      "slug": "comment-creer-un-compte",
      "category": {
        "value": "account_auth",
        "label": "Compte et authentification"
      },
      "title": "Comment créer un compte BANGO ?",
      "content_markdown": "Pour créer un compte, téléchargez l'application...",
      "helpful_count": 14,
      "unhelpful_count": 1,
      "views_count": 128,
      "locale": "fr",
      "created_at": "2026-10-16T18:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 1
  }
}
```

#### B. Consultation détaillée d'un article
- **Route :** `GET /api/help/articles/{slug}`
- **Accès :** Public
- **Paramètre d'URL :** `slug` (ex: `visites-virtuelles-360`).
- **Comportement :** Incrémente automatiquement `views_count` de façon atomique. Renvoie `404` si l'article est inexistant ou non publié (`is_published: false`).

#### C. Vote d'utilité (SUP-03)
- **Route :** `POST /api/help/articles/{id}/feedback`
- **Accès :** Public ou Authentifié (avec ou sans Bearer Token).
- **Corps de la requête :**
```json
{
  "helpful": true
}
```
- **Règles métier :**
  - Idempotence : un vote identique répété ne double pas l'incrémentation.
  - Bascule de vote : si l'utilisateur vote `helpful: false` après avoir voté `helpful: true`, le compteur `helpful_count` est décrémenté et `unhelpful_count` est incrémenté.
  - Déduplication : par `user_id` pour les utilisateurs connectés, ou par `session_id` / `ip_address` pour les visiteurs anonymes.
- **Réponse HTTP 200 :**
```json
{
  "message": "Merci pour votre retour.",
  "data": {
    "helpful": true,
    "helpful_count": 15,
    "unhelpful_count": 1,
    "changed": true
  }
}
```

---

### 4.2. Billetterie Support Client (Authentifié)

#### A. Création d'un ticket avec premier message
- **Route :** `POST /api/support/tickets`
- **Accès :** Authentifié (`auth:api`), compte non suspendu.
- **Format :** `multipart/form-data` ou `application/json` (sans fichier).
- **Champs requis :**
  - `subject` : string (3 à 255 caractères).
  - `category` : string (valeur valide de `TicketCategory`).
  - `message` : string (5 à 5000 caractères, description initiale du problème).
  - `priority` : string (optionnel, `low`, `normal`, `high`, `urgent`).
  - `attachments` : array de fichiers (optionnel, max 5 fichiers, max 10 Mo par fichier, extensions autorisées : `jpeg`, `jpg`, `png`, `webp`, `pdf`, `txt`, `log`).
- **Atomicité :** Exécution sous transaction DB complète. En cas d'erreur de stockage ou de base de données, les fichiers uploadés sont nettoyés automatiquement.
- **Réponse HTTP 201 :**
```json
{
  "message": "Votre ticket de support a été créé avec succès.",
  "data": {
    "id": 1,
    "reference": "TIC-2026-X8B9J2",
    "subject": "Problème de déblocage de caution",
    "category": {
      "value": "payment_wallet",
      "label": "Paiement et portefeuille"
    },
    "status": {
      "value": "open",
      "label": "Ouvert"
    },
    "priority": {
      "value": "normal",
      "label": "Normale"
    },
    "user": {
      "id": 42,
      "name": "Jean Dupont",
      "email": "jean@example.com"
    },
    "created_at": "2026-10-16T19:30:00Z"
  }
}
```

#### B. Liste de mes tickets
- **Route :** `GET /api/support/tickets/mine`
- **Accès :** Authentifié (`auth:api`).
- **Filtres :** `status` (ex: `open`, `in_progress`, `closed`), pagination serveur `per_page`.
- **Sécurité :** Ne retourne strictement que les tickets appartenant à l'utilisateur connecté.

#### C. Détail d'un ticket et fil d'échanges
- **Route :** `GET /api/support/tickets/{id}`
- **Accès :** Authentifié (`auth:api`), propriétaire du ticket ou agent support.
- **Sécurité renforcée :**
  - Retourne `403 Forbidden` si un utilisateur tente de consulter le ticket d'un tiers.
  - Les notes internes (`is_internal = true`) sont **strictement filtrées et exclues** pour le client, et uniquement visibles aux agents support.

#### D. Répondre à un ticket
- **Route :** `POST /api/support/tickets/{id}/messages`
- **Accès :** Authentifié (`auth:api`), propriétaire du ticket ou agent support.
- **Champs requis :**
  - `message` : string (1 à 5000 caractères).
  - `attachments` : array de fichiers (optionnel, max 5 fichiers).
- **Règles de transition automatique :**
  - Si le ticket était `waiting_for_user` ou `resolved`, la réponse du client fait repasser le ticket à `in_progress`.
  - Si le ticket est `closed`, la réponse du client est rejetée avec `403/422`.
- **Notifications :** Déclenche `SupportTicketReplyNotification` vers l'agent assigné (si client répond) ou vers le client (si support répond).

#### E. Téléchargement sécurisé d'une pièce jointe
- **Route :** `GET /api/support/tickets/{ticketId}/attachments/{attachmentId}`
- **Accès :** Authentifié (`auth:api`).
- **Sécurité :**
  - Contrôle d'autorisation via `SupportTicketPolicy@viewAttachment`.
  - Un tiers est rejeté avec `403 Forbidden`.
  - Une pièce jointe rattachée à une note interne est inaccessible au client (`403 Forbidden`).
  - Téléchargement en flux privé (disque `local` protégé).

---

### 4.3. Back-Office Support & Administration (`admin`)

#### A. Liste générale des tickets
- **Route :** `GET /api/admin/support/tickets`
- **Accès :** `auth:api` + rôle `admin` ou agent support.
- **Filtres disponibles :** `status`, `priority`, `category`, `assigned_to`, pagination `per_page`.

#### B. Mise à jour du statut d'un ticket
- **Route :** `PATCH /api/admin/support/tickets/{id}/status`
- **Corps :** `{"status": "resolved"}` (ou `closed`, `in_progress`, `waiting_for_user`).
- **Effets :** Met à jour automatiquement `resolved_at` ou `closed_at`.

#### C. Assignation d'un ticket
- **Route :** `PATCH /api/admin/support/tickets/{id}/assign`
- **Corps :** `{"agent_id": 12}` (ou `{"agent_id": null}` pour désassigner).

#### D. Réponse administrative et notes internes
- **Route :** `POST /api/admin/support/tickets/{id}/messages`
- **Corps :**
```json
{
  "message": "Note interne : contrôle d'identité confirmé.",
  "is_internal": true
}
```
- **Règles :**
  - Si `is_internal: true`, aucune notification n'est envoyée au client et le message est invisible pour ce dernier.
  - Si `is_internal: false`, réponse publique transmise au client avec notification In-App / Push.

---

## 5. Raccordement Frontend Mobile (React Native / Expo)

Dans l'application mobile, le service `HelpService` ou les écrans de FAQ et support peuvent consommer directement ces endpoints :

### 5.1. FAQ & Recherche
```typescript
// Remplacement des données locales de lib/help/fr.ts par l'API dynamique
export const fetchHelpArticles = async (query?: string, category?: string) => {
  const params = new URLSearchParams();
  if (query) params.append('q', query);
  if (category) params.append('category', category);
  
  const response = await apiClient.get(`/help/articles?${params.toString()}`);
  return response.data; // { data: HelpArticle[], meta: ... }
};

export const submitArticleFeedback = async (articleId: number, helpful: boolean) => {
  const response = await apiClient.post(`/help/articles/${articleId}/feedback`, { helpful });
  return response.data; // { data: { helpful, helpful_count, unhelpful_count } }
};
```

### 5.2. Billetterie Support
```typescript
export const createSupportTicket = async (formData: FormData) => {
  const response = await apiClient.post('/support/tickets', formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
  });
  return response.data;
};

export const fetchMyTickets = async (status?: string) => {
  const response = await apiClient.get(`/support/tickets/mine${status ? `?status=${status}` : ''}`);
  return response.data;
};

export const replyToTicket = async (ticketId: number | string, message: string, attachments?: File[]) => {
  const formData = new FormData();
  formData.append('message', message);
  if (attachments) {
    attachments.forEach((file) => formData.append('attachments[]', file));
  }
  const response = await apiClient.post(`/support/tickets/${ticketId}/messages`, formData);
  return response.data;
};
```

---

## 6. Validation et Non-Régression

- **Tests Pest FAQ :** `tests/Feature/Support/HelpArticleTest.php` (6 tests, 70 assertions, 100% verts).
- **Tests Pest Billetterie Client :** `tests/Feature/Support/SupportTicketTest.php` (9 tests, 37 assertions, 100% verts).
- **Tests Pest Back-Office :** `tests/Feature/Support/AdminSupportTicketTest.php` (4 tests, 21 assertions, 100% verts).
- **Tests Signalements & Recours existants :** `tests/Feature/Support/ReportAndModerationAppealTest.php` (14 tests, 41 assertions, 100% verts).
- **Suite Complète de Tests BANGO :** **786 tests réussis, 3884 assertions, 0 échec.**
- **Formatage du Code :** Conforme aux standards Laravel Boost avec Laravel Pint (`vendor/bin/pint --format agent`).
