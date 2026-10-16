# AUDIT INITIAL DU MODULE 12 : PG — PAGES PROFESSIONNELLES

Date : 16 Octobre 2026
Contexte : Backend BANGO (Laravel 13 + PostgreSQL)

## Matrice d'audit initial

| Fonctionnalité | État actuel | Éléments existants | Manques constatés | Action |
| :--- | :--- | :--- | :--- | :--- |
| **Pages et identité publique** | Absent en base, prévu conceptuellement | Colonne `page_id` dans `posts`. Référence frontend `app/page.tsx`, `app/my-pages.tsx`, `app/page-new.tsx`, `app/page-switch.tsx`. | Table `pages` inexistante. Modèle `Page` absent. Routes CRUD et gestion des visuels absentes. | Créer la migration `pages`, le modèle Eloquent `Page`, Form Requests, Resources, Contrôleur et Service. |
| **Membres et rôles RBAC** | Absent | Références dans `frontend_modules_inventory.md` (`PG-03`). Policies existantes pour `Campaign`, `Listing`, `Conversation`. | Table `page_members` absente. Modèle `PageMember`, Policies (`PagePolicy`), gestion des invitations (invite/accept/decline/revoke) absents. | Créer la table pivot `page_members`, le modèle `PageMember`, les Enums `PageRole` et `PageMemberStatus`, la `PagePolicy`, `PageMemberController`. |
| **Certification entreprise** | Partiellement préparé dans PAY | Enum `PaymentServiceType::Certification` présent. Catalogue tarifaire `page_pro` (25 000 FCFA) dans `PricingService` et `SubscriptionCatalogSeeder`. Stockage privé sécurisé dans `KycService`. | Table `page_certifications` absente. Modèle `PageCertification` absent. Workflow de soumission, examen admin, règle des 2 corrections (PG-07) et téléchargement sécurisé absents. | Créer la migration `page_certifications`, le modèle `PageCertification`, le contrôleur utilisateur et le contrôleur admin, en appliquant PG-07. |
| **Paiement des frais** | Partiellement intégré dans PAY | `PaymentEngineService` gère les transactions, quotes, webhooks Monetbil et transitions atomiques. Fulfillers existants pour `Visit`, `Boost`, `Credit`. | Branchement du fulfiller `PaymentServiceType::Certification` manquant dans `PaymentEngineService` pour lier la transaction au dossier de certification. | Raccorder `PaymentEngineService` au `PageCertificationService` avec idempotence et verrouillage transactionnel. |
| **Inbox de Page** | Prévu dans le schéma messagerie | Table `conversations` avec type `page` supporté dans le schéma (`2026_10_16_130000_create_messaging_tables.php`). | Colonne `page_id` absente de `conversations`. Filtrage de l'inbox par Page et vérification des rôles (Modérateur/Éditeur/Propriétaire) non implémentés. | Ajouter `page_id` à `conversations`, ajouter l'endpoint `inbox` dédié ou étendre `MessageThreadService` avec contrôle RBAC strict. |
| **Publications Square et Reels** | Partiellement existant pour Square, absent pour Reels | `posts.page_id` existe déjà. `CreatePostRequest` accepte `as_page_id`. | Validation d'appartenance/rôle manquant dans `PostCreationService`. `page_id` absent de `reels`. Attribution et branding de Page manquant dans `ReelResource` et `PostResource`. | Ajouter `page_id` à `reels`, contrôler que l'auteur est Propriétaire/Éditeur de la Page, adapter `PostResource` et `ReelResource`. |
| **Boost au nom d'une Page** | Partiellement existant | Module BO complet (`Campaign`, `CampaignService`, `CreditWallet`, `CreditTransaction`). Support polymorphique `boostable`. | Colonne `page_id` absente de `campaigns`. Mention « Sponsorisé par [Page] » et vérification des droits du membre non reliés à la Page. | Ajouter `page_id` à `campaigns`, étendre `CampaignService` et `CampaignPolicy` pour valider l'appartenance active (Propriétaire/Éditeur). |
| **Catalogue immobilier** | Modèle `Listing` existant | `listings` avec bailleur, agent, délégations et mandats. | Vitrine d'annonces de l'agence immobilière non rattachée à la Page. | Permettre le rattachement d'annonces à la Page (`listings.page_id`) et exposer l'endpoint vitrine `GET /api/pages/{page}/listings`. |
| **Recherche professionnelle** | Moteur de recherche existant (`RC`) | Contrôleurs de recherche `PeopleSearchController`, `PlaceSearchController`, `HashtagSearchController`. | Recherche de Pages absente (`PageSearchController`). Filtrage par secteur, ville, quartier et statut certifié manquant. | Implémenter `PageSearchController` avec filtres, scopes Eloquent et pagination. |
| **Notifications et modération** | Système de notifications en base existant | Tables `notifications`, dossier `app/Notifications/`. `CheckAdminRole` middleware existant. | Notifications dédiées aux invitations, décisions de certification et suspensions de Page absentes. | Créer les classes de notification dans `App\Notifications\Page\`. Raccorder les actions de modération/suspension. |

---

## Décisions techniques et règles métier

1. **Règle PG-07 (Deux corrections incluses) :**
   - Une correction est définie comme une resoumission du dossier par le propriétaire après une demande de pièces complémentaires (`supplement_requested`) émise par un administrateur, ou après un rejet motivé.
   - Le champ `corrections_count` débute à 0. Après 2 corrections (`corrections_count >= 2`), toute nouvelle demande de correction est bloquée et le dossier nécessite un arbitrage ou une nouvelle démarche administrative.
2. **Stockage sécurisé des documents de certification :**
   - Les justificatifs administratifs (RCCM, NIF, pièce d'identité du représentant légal) sont stockés sur le disque privé `local` (`storage/app/page_certifications/...`), hors du répertoire public web.
   - Le téléchargement est soumis à `PageCertificationPolicy` (seul le propriétaire de la Page ou un administrateur BANGO peut télécharger le fichier via streaming sécurisé).
3. **Protection EDGE-01 (Dernier propriétaire) :**
   - Il est impossible de révoquer ou de rétrograder le dernier propriétaire ayant le statut `accepted`.
   - Il est impossible pour le dernier propriétaire de quitter la page sans avoir préalablement transféré la propriété à un membre actif.
   - Le transfert de propriété est strictement transactionnel (`lockForUpdate`).
4. **Révocation immédiate EDGE-02 :**
   - La révocation passe le statut à `revoked` et horodate `revoked_at`.
   - Toutes les autorisations vérifient `status === 'accepted'`. Un membre révoqué est immédiatement bloqué pour la boîte de réception, les publications Square/Reels et le sponsoring.
5. **Suspension EDGE-03 & EDGE-04 :**
   - La suspension de certification révoque immédiatement le badge public sans détruire la Page.
   - La suspension opérationnelle de la Page désactive la possibilité de publier, inviter ou interagir au nom de la Page.
