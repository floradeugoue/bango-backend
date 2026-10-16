# BANGO — AUDIT TECHNIQUE INITIAL : ÉTAPE 11
## MODULE 18 : SUP — CENTRE D'AIDE FAQ DYNAMIQUE & BILLETTERIE SUPPORT CLIENT

**Date :** 16 Octobre 2026  
**Environnement :** Laravel 13, PostgreSQL 18.6, PHP 8.4, Pest PHP  
**Auteurs/Contexte :** Pair-programming BANGO Backend  

---

### 1. Cartographie de l'Existant et Écarts Identifiés

| Composant Fonctionnel | État Constaté | Fichiers & Schémas Existants | Écarts par Rapport aux Exigences | Actions à Réaliser |
| :--- | :---: | :--- | :--- | :--- |
| **FAQ dynamique & Base de connaissances (`SUP-01`, `SUP-02`)** | 🔴 Absent | Aucun modèle `HelpArticle`, aucune table `help_articles`. Le frontend stocke des articles en dur (`lib/help/fr.ts`). | Pas de table pour stocker les articles d'aide, pas de catégorisation serveur, pas de recherche plein texte ni de pagination. | Créer la table `help_articles`, modèle `HelpArticle`, enum `HelpArticleCategory`, service `HelpArticleService` (recherche textuelle, filtres, pagination, incrément de vues), et contrôleur `HelpArticleController` (`GET /api/help/articles`, `GET /api/help/articles/{slug}`). |
| **Vote d'utilité des articles (`SUP-03`)** | 🔴 Absent | Aucun système de vote pour la FAQ. | Pas d'enregistrement des retours utilisateurs, risque de votes dupliqués par retry réseau, compteurs non fiables. | Créer la table `help_article_feedbacks` (unicité par utilisateur ou IP, bascule possible du vote sans double comptage), endpoint `POST /api/help/articles/{id}/feedback` avec mise à jour atomique de `helpful_count` et `not_helpful_count`. |
| **Billetterie de support client (`SUP-04` à `SUP-06`)** | 🔴 Absent | Seuls `Report` (signalements) et `ModerationAppeal` (recours) existent dans `app/Models/Support/`. | Aucun système de tickets d'assistance distinct de la modération et de la messagerie privée. Pas de référence publique de ticket, pas de pièces jointes. | Créer la table `support_tickets`, modèle `SupportTicket`, enums `TicketCategory`, `TicketStatus`, `TicketPriority`, référence unique non prédictible (`TIC-YYYY-XXXX`), service `SupportTicketService`, contrôleur `SupportTicketController` (`POST /api/support/tickets`, `GET /api/support/tickets/mine`, `GET /api/support/tickets/{id}`). |
| **Messages & Réponses aux tickets (`SUP-07`, `SUP-08`)** | 🔴 Absent | Aucun modèle de message de ticket. | Pas de conversation support, pas de distinction client / support / notes internes réservées aux agents. | Créer la table `support_ticket_messages`, modèle `SupportTicketMessage`, endpoint `POST /api/support/tickets/{id}/messages`, masquage strict des notes internes pour les clients, transitions automatiques de statut lors d'une réponse client. |
| **Pièces jointes sécurisées (`SUP-05`, `SUP-08`)** | 🔴 Absent | Stockage média Square / Reels / Listings existe, mais pas de stockage privé pour pièces jointes de support. | Fichiers joints potentiellement exposés publiquement sans contrôle d'accès. | Créer la table `support_ticket_attachments`, modèle `SupportTicketAttachment`, stockage sur disque privé (`local`), whitelist formats (images, pdf, txt/logs, max 10MB), endpoint de téléchargement sécurisé `GET /api/support/tickets/{ticketId}/attachments/{attachmentId}` soumis à la policy. |
| **Interface Support / Back-office (`SUP-09`)** | 🔴 Absent | Les contrôleurs admin existent pour les utilisateurs, rôles, catalogue et paiements, mais pas pour le support. | Aucun moyen pour un agent ou admin de lister, assigner, mettre à jour le statut ou répondre avec note interne. | Créer `AdminSupportTicketController` (`GET /api/admin/support/tickets`, `PATCH /api/admin/support/tickets/{id}/status`, `PATCH /api/admin/support/tickets/{id}/assign`, `POST /api/admin/support/tickets/{id}/messages`). |
| **Notifications Support** | 🟡 Partiel | Trait `Notifiable`, table `notifications`. Pas de notifications support. | L'utilisateur n'est pas alerté quand le support répond. | Créer `SupportTicketCreatedNotification` et `SupportTicketReplyNotification`. |

---

### 2. Plan d'Implémentation Séquentiel

1. **Phase 1 — FAQ Dynamique & Recherche (`SUP-01`, `SUP-02`)**
   - Migration `create_help_articles_table`.
   - Modèle `HelpArticle` et Enum `HelpArticleCategory`.
   - Service `HelpArticleService` avec recherche plein texte PostgreSQL et fallback insensible à la casse.
   - Resource `HelpArticleResource` & `HelpArticleDetailResource`.
   - Contrôleur `HelpArticleController` (`GET /api/help/articles`, `GET /api/help/articles/{slug}`).
   - Tests Pest.

2. **Phase 2 — Vote d'Utilité (`SUP-03`)**
   - Migration `create_help_article_feedbacks_table`.
   - Modèle `HelpArticleFeedback`.
   - Méthode `voteFeedback` atomique et idempotente dans `HelpArticleService`.
   - Contrôleur `HelpArticleFeedbackController` (`POST /api/help/articles/{id}/feedback`).
   - Tests Pest.

3. **Phase 3 — Billetterie : Modèles, Migrations & Pièces Jointes (`SUP-04` à `SUP-06`)**
   - Migrations `create_support_tickets_table`, `create_support_ticket_messages_table`, `create_support_ticket_attachments_table`.
   - Modèles `SupportTicket`, `SupportTicketMessage`, `SupportTicketAttachment`.
   - Enums `TicketCategory`, `TicketStatus`, `TicketPriority`, `TicketMessageSenderType`.
   - Form Requests : `StoreSupportTicketRequest`, `ReplySupportTicketRequest`.
   - Policy : `SupportTicketPolicy` (propriétaire, agent support, admin).
   - Service `SupportTicketService` (création atomique ticket + premier message + fichiers joints, validation et stockage privé).
   - Contrôleur `SupportTicketController` (`POST /api/support/tickets`, `GET /api/support/tickets/mine`, `GET /api/support/tickets/{id}`, `POST /api/support/tickets/{id}/messages`, `GET /api/support/tickets/{id}/attachments/{attachmentId}`).
   - Notifications `SupportTicketReplyNotification`.
   - Tests Pest.

4. **Phase 4 — Traitement par le Support & Administration (`SUP-07` à `SUP-09`)**
   - Contrôleur `AdminSupportTicketController` pour agents et admins :
     - `GET /api/admin/support/tickets` (filtres par statut, priorité, catégorie, recherche)
     - `PATCH /api/admin/support/tickets/{id}/status`
     - `PATCH /api/admin/support/tickets/{id}/assign`
     - `POST /api/admin/support/tickets/{id}/messages` (support des notes internes)
   - Tests Pest d'autorisation et d'isolation.

5. **Phase 5 — Validation, Non-régression, Pint & Documentation**
   - Exécution de Pint : `vendor/bin/pint --format agent`.
   - Exécution de tous les tests fonctionnels et d'intégration.
   - Documentation technique dans `docs/support/etape11_support_faq_tickets_documentation.md`.
   - Mise à jour de `docs/audit/module_api_coverage.md`.
