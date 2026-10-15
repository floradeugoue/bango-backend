# Focus Spécifique — Espace Bailleur, Espace Agent, Immobilier Commun & Messagerie

**Projet :** BANGO Platform (Frontend Mobile & Backend Laravel)  
**Date de l'audit :** Octobre 2026  
**Objet :** Cartographie ciblée et exhaustive sur les 4 piliers centraux du modèle économique et serviciel de BANGO.

---

## 1. Espace Bailleur (BL)

L'Espace Bailleur s'adresse aux propriétaires de biens immobiliers ayant souscrit au Badge Bailleur (7 500 XAF/mois ou 75 000 XAF/an) et souhaitant louer, vendre ou partager leurs logements sans intermédiaires imposés.

### 1.1 Fonctionnalités Frontend Identifiées

| Périmètre | Fonctionnalités Observées dans le Code Frontend | Écrans Frontend | Fichiers Sources |
| :--- | :--- | :--- | :--- |
| **Souscription & Badges** | - Présentation des avantages de l'offre Bailleur.<br>- Dépôt des pièces d'identité et justificatifs de propriété (KYC).<br>- Écran d'attente d'homologation (`BL-03`).<br>- Reprise de dossier en cas de rejet (`BL-04`).<br>- Activation du badge et privilèges (`BL-05`). | `BL-01` à `BL-05`, `app/landlord-join.tsx` | `lib/api/access.ts`, `lib/api/billing.ts` |
| **Gestion du Patrimoine** | - Tableau de bord de ses biens avec indicateurs d'urgence.<br>- Changement d'état opérationnel : `published` (en ligne), `reserved` (visites suspendues), `taken` (loué/occupé).<br>- Dépublication avec alerte si une campagne de boost est active (`BL-19`).<br>- Suppression d'annonce avec double confirmation (`BL-20`). | `BL-06`, `BL-17` à `BL-20`, `app/landlord.tsx` | `lib/api/landlord.ts`, `app/mark-taken.tsx` |
| **Visites & Calendrier** | - Définition des créneaux hebdomadaires d'ouverture aux visites.<br>- Traitement des demandes de visite entrantes : acceptation, report, refus (`BL-07`, `BL-08`). | `BL-07`, `BL-08`, `app/visit-requests.tsx` | `lib/api/visits.ts`, `lib/api/landlord.ts` |
| **Statistiques Pro** | - Métriques d'audience par bien : vues de la vidéo, ajouts en favoris, prises de contact messagerie, demandes de visite générées (`BL-09`, `BL-21`). | `BL-09`, `BL-21`, `app/listing-stats.tsx` | `lib/api/listingActivity.ts` |
| **Gestion des Accès (Délégations)** | - Inventaire des personnes ayant accès au bien (`BL-22`).<br>- Invitation d'un agent de terrain avec sélection du périmètre de capacités (`visits`, `reply`, `edit`).<br>- Révocation de mandat avec conservation de l'historique en lecture seule. | `BL-22`, `app/listing-access.tsx` | `lib/api/agents.ts`, `lib/api/landlord.ts` |
| **Recettes & Encaissements** | - Relevé des frais de dossier de visite collectés et reçus téléchargeables (`BL-11`). | `BL-11` | `lib/api/payments.ts` |

### 1.2 Couverture Backend Existante & Manques

| Opération Métier Bailleur | Statut Backend | Éléments Backend Existants | Éléments Manquants dans le Backend |
| :--- | :--- | :--- | :--- |
| **Souscription au Badge Bailleur** | **Développée** | `POST /api/subscriptions/subscribe`, produit `badge_bailleur` dans `SubscriptionProduct`, liaison avec Monetbil. | Aucun pour le volet financier. |
| **Dépôt des pièces KYC Bailleur** | **Absente** | Aucun modèle de document KYC ni contrôleur. | Manque table `kyc_documents` et route `POST /api/landlord/kyc`. |
| **Inventaire des biens du bailleur** | **Absente** | Aucune table `properties` ou `listings`. | Manque modèle `Property`/`Listing` et route `GET /api/landlord/properties`. |
| **Modification du statut d'un bien** | **Absente** | Aucune table ni endpoint. | Manque route `PATCH /api/landlord/properties/{id}/status`. |
| **Marquer un bien comme loué** | **Absente** | Aucune table ni endpoint. | Manque route `POST /api/landlord/properties/{id}/taken` avec logique d'arrêt des campagnes actives. |
| **Configuration des créneaux de visite** | **Absente** | Aucune table `visit_slots`. | Manque table d'agenda et route `PUT /api/landlord/properties/{id}/slots`. |
| **Gestion des demandes de visite** | **Absente** | Aucune table `visit_requests`. | Manque routes `GET /api/landlord/visit-requests`, `/accept`, `/reject`. |
| **Statistiques d'audience du bien** | **Absente** | Aucune table d'analytique immobilière. | Manque table `listing_views` / `listing_stats` et route `GET /api/landlord/properties/{id}/stats`. |
| **Délégation d'accès aux agents** | **Absente** | Aucune table `mandates` ou `delegations`. | Manque table de délégation et routes d'invitation/révocation d'agents. |

---

## 2. Espace Agent Immobilier (AG)

L'Espace Agent est destiné aux professionnels de l'intermédiation détenteurs du Badge Agent (3 500 XAF/mois ou 35 000 XAF/an), mandatés par des bailleurs pour faire visiter les biens et échanger avec les prospects.

### 2.1 Fonctionnalités Frontend Identifiées

| Périmètre | Fonctionnalités Observées dans le Code Frontend | Écrans Frontend | Fichiers Sources |
| :--- | :--- | :--- | :--- |
| **Profil Professionnel & Badge** | - Affichage de l'anneau ambre distinctif de l'agent certifié.<br>- Présentation des quartiers d'intervention et de l'ancienneté.<br>- Règles de suspension en cas de badge expiré (`AG-06`, `EDGE-07`, `EDGE-08`). | `AG-01`, `AG-06`, `app/agent-space.tsx` | `lib/api/agents.ts`, `lib/api/access.ts` |
| **Portefeuille de Mandats** | - Vue consolidée des biens confiés par différents bailleurs.<br>- Distinction des capacités déléguées : mener les visites (`visits`), répondre aux messages (`reply`), modifier l'annonce (`edit`). | `AG-05`, `app/agent-portfolio.tsx` | `lib/api/agents.ts` |
| **Invitations & Relations Bailleurs** | - Réception des propositions de délégation (`AG-04`).<br>- Examen du périmètre, acceptation ou refus avec notification au bailleur.<br>- Délais d'expiration d'une invitation sans réponse (7 jours, `AG-01`). | `AG-04`, `app/agent-invitation.tsx` | `lib/api/agents.ts` |
| **Gestion des Prospects** | - Liste des contacts ayant demandé une visite sur les biens délégués (`app/agent-prospects.tsx`).<br>- Prise de contact directe et conduite des rendez-vous terrain. | `app/agent-prospects.tsx` | `lib/api/agents.ts`, `lib/api/messages.ts` |
| **Annuaire Public & Recherche** | - Répertoire public des agents immobiliers vérifiés avec filtrage par ville et quartier (`app/agents.tsx`, `app/find-agent.tsx`). | `app/agents.tsx`, `app/find-agent.tsx`, `RC-10` | `lib/api/agents.ts`, `lib/api/search.ts` |
| **Révocation & Conservation** | - Passage automatique en "lecture seule" en cas de révocation par le bailleur (`AG-06`, `MS-20`).<br>- Préservation stricte de l'historique des visites menées pour éviter toute perte de traçabilité. | `AG-06`, `EDGE-02` | `lib/api/agents.ts`, `lib/api/messages.ts` |

### 2.2 Couverture Backend Existante & Manques

| Opération Métier Agent | Statut Backend | Éléments Backend Existants | Éléments Manquants dans le Backend |
| :--- | :--- | :--- | :--- |
| **Souscription au Badge Agent** | **Développée** | Produit `badge_agent` dans `SubscriptionProduct` et gestion du statut. | Aucun pour le paiement. |
| **Portefeuille de mandats délégués** | **Absente** | Aucune table `delegations` ou `mandates`. | Manque modèle `Delegation` et route `GET /api/agent/properties`. |
| **Traitement d'une invitation** | **Absente** | Aucune table ni endpoint. | Manque routes `POST /api/agent/invitations/{id}/accept` et `/reject`. |
| **Annuaire public des agents** | **Absente** | La recherche `GET /api/square-cr1/search/people` cherche tous les usagers sans filtre de badge d'agent. | Manque endpoint de recherche spécialisé `GET /api/agents/search` avec tri par ville/quartier. |
| **Répertoire des prospects affectés** | **Absente** | Aucune table de prospects. | Manque modèle `Prospect` et route `GET /api/agent/prospects`. |
| **Gestion de la lecture seule post-révocation** | **Absente** | Aucune logique d'autorisation déléguée en base. | Manque politique de contrôle d'accès RBAC/Policy pour interdire l'écriture tout en préservant la lecture. |

---

## 3. Fonctionnalités Immobilières Communes (IM & CR4)

Ce bloc regroupe l'ensemble des opérations et données immobilières partagées entre les prospects (chercheurs de logement), les bailleurs et les agents.

### 3.1 Cartographie des Composants & Flux Identifiés

1. **Création d'Annonce Guidée (CR4)** :
   - *Tournage guidé pièce par pièce* : Consignes vidéo strictes (durée 30-180s, format portrait 9:16 obligatoire, contrôle de luminosité).
   - *Sélection et qualification des photos* : Jusqu'à 20 clichés qualifiés par pièce (`living`, `bedroom`, `kitchen`, `shower`, `outside`) avec couverture immuable (`photos[0]`).
   - *Décomposition financière transparente* : Prix nu, charges récurrentes mensuelles, caution (mois), avance (mois), frais de dossier de visite, devise rattachée au pays du bien.
   - *Localisation de sécurité* : Quartier et repère public sans adresse exacte ni numéro de porte pour préserver la vie privée et éviter le démarchage sauvage.
   - *Cycle de validation* : Soumission -> Statut `review` (modération) -> Statut `published`.

2. **Consultation & Découverte (IM)** :
   - *Double vue synchrone* : Feed vertical immersif (vidéos automatiques) et carte interactive vectorielle avec clusters de densité.
   - *Moteur de filtres structuré* : Type de contrat (location, colocation, vente), budget charges comprises ou non, nombre de pièces, équipements certifiés, visite préalable effectuée par un agent.
   - *Suggestions d'élargissement de critères* : Si une recherche est infructueuse, proposition serveur du plus petit assouplissement utile (`listRelaxations` : ex. monter le budget de 10 000 FCFA pour débloquer 4 biens).

3. **Réservation de Visite Transactionnelle** :
   - *Grille de disponibilité* : 5 jours ouvrés glissants avec créneaux horaires configurés par le bailleur.
   - *Option temporaire (`holding`)* : Verrouillage du créneau pendant 15 minutes le temps de finaliser le paiement Mobile Money.
   - *Validation & Reçu* : Levée du verrou post-paiement, transmission de l'adresse exacte au prospect et émission du reçu certifié.

4. **Favoris & Alertes** :
   - *Mise en favori* : Sauvegarde locale et serveur pour consultation ultérieure.
   - *Alertes de quartier* : Notification push instantanée dès parution d'un bien conforme aux critères enregistrés.

### 3.2 État de Couverture Backend & Éléments Réutilisables

| Composant Immobilier | Statut Backend | Éléments Déjà Prêts dans le Backend | Éléments Manquants dans le Backend |
| :--- | :--- | :--- | :--- |
| **Référentiel géographique** | **Développé** | Tables `countries`, `cities`, `neighbourhoods`, `currencies` et contrôleurs associés. | Rien (le socle géographique et monétaire est complet). |
| **Téléversement de médias** | **Partiellement développé** | `POST /api/square-cr1/media/upload` et `POST /api/reels/media/upload`. | Manque le traitement spécifique des vidéos immobilières (contrôle du ratio 9:16 et durée) et l'association des photos par pièce. |
| **Passerelle de paiement des visites** | **Développée** | `POST /api/payments/quote`, `POST /api/payments/initiate` et webhook Monetbil. | Les endpoints financiers existent, mais l'objet métier de rattachement (`visit_reservation`) manque pour finaliser le cycle de vie. |
| **Modèle de données des biens (`listings`)** | **Absente** | Aucun modèle Eloquent ni migration. | Création requise de la table `listings` et des relations avec `users`, `cities`, `neighbourhoods`. |
| **Moteur de recherche immobilière** | **Absente** | Aucune requête spatiale ni route de feed. | Création requise de `GET /api/listings` avec filtres, tri et recherche par emprise cartographique. |
| **Agenda & Réservation de créneaux** | **Absente** | Aucun système d'agenda. | Création requise des tables `visit_slots` et `visit_reservations` avec mécanisme de retenue temporaire. |
| **Gestion des favoris d'annonces** | **Absente** | Seuls les favoris de Reels existent (`reel_saves`). | Création requise de la table pivot `listing_saves`. |

---

## 4. Messagerie Privée (MS) & Ponts CR3 Story

La messagerie instantanée BANGO est le canal d'échange privilégié entre voisins, prospects, bailleurs et agents, avec la particularité d'être fortement contextualisée autour des annonces immobilières.

### 4.1 Fonctionnalités Frontend Identifiées

| Périmètre | Fonctionnalités Observées dans le Code Frontend | Écrans Frontend | Fichiers Sources |
| :--- | :--- | :--- | :--- |
| **Boîte de Réception & Fils** | - Liste des fils de discussion ordonnés par date décroissante.<br>- Filtrage par catégorie : Tous, Immo, Directs.<br>- Typologie visuelle des interlocuteurs : Utilisateur (avatar rond), Page Pro (carré arrondi), Groupe (double avatar décalé).<br>- Ligne de contexte sous le nom (`subject`) indiquant le sujet de discussion. | `MS-01`, `MS-09`, `app/(tabs)/messages.tsx` | `lib/api/messages.ts` |
| **Discussion Contextuelle Immo** | - Bandeau supérieur fixe rappelant le titre du bien, le loyer et le créneau de visite convenu (`MS-02`).<br>- Accès en 1 tap à la fiche du bien sans quitter la conversation.<br>- Bouton d'appel téléphonique si le numéro est partagé. | `MS-02`, `app/thread.tsx` | `lib/api/messages.ts` |
| **Envoi Multimédia & Vocaux** | - Envoi de messages texte avec support des émoticônes.<br>- Enregistrement de notes vocales audio avec affichage de la durée et lecture in-situ (`MS-03`).<br>- Envoi de photos avec indicateur de progression de téléversement (`MS-13`). | `MS-03`, `MS-13`, `app/thread-media.tsx` | `lib/api/messages.ts`, `components/messages/` |
| **Conversations de Groupe** | - Création d'un groupe avec choix du titre et des membres (`MS-14`).<br>- Gestion des membres et rôle d'administrateur (`MS-16`).<br>- Règles de sécurité si le dernier administrateur quitte le groupe (`MS-17`).<br>- Réponses ciblées et mentions `@` au sein du groupe (`MS-18`). | `MS-14` à `MS-18`, `app/message-group.tsx`, `app/group-info.tsx` | `lib/api/messages.ts` |
| **Demandes de Message** | - Boîte de réception isolée pour les messages d'utilisateurs inconnus (`MS-05`).<br>- Boutons Accepter (bascule en fil normal), Refuser, ou Supprimer et Bloquer. | `MS-05`, `app/message-requests.tsx` | `lib/api/messages.ts` |
| **États de Lecture & Sécurité** | - Indicateur de saisie en cours (`typing`), accusés de distribution et de lecture.<br>- Mode muet / lecture seule si l'interlocuteur est un agent révoqué (`MS-20`).<br>- Gestion protectrice du blocage : "BANGO ne dit jamais qui t'a bloqué" (`MS-21`, `EDGE-22`).<br>- Conservation de l'historique de chat même si un compte est supprimé (`EDGE-15`). | `MS-11`, `MS-20`, `MS-21`, `app/blocked.tsx` | `lib/api/messages.ts`, `lib/api/access.ts` |

### 4.2 Ponts et Préparations Existantes : CR3 Story -> Messagerie

Le module backend **CR3 Story** a été conçu pour préparer l'arrivée de la messagerie sans anticiper artificiellement son schéma de base de données.

1. **Persistance de l'Intention de Réponse (`POST /api/stories/{id}/reply`)** :
   - Lorsqu'un utilisateur répond à une Story ou envoie une réaction émoji, la route enregistre un tuple dans la table `story_replies`.
   - L'enregistrement fige le type (`text` ou `reaction`), le contenu, l'identifiant de l'auteur de la Story (`story_author_id`) et celui du répondant (`user_id`).
   - Le statut initial est fixé à `pending` (en attente d'ingestion par la messagerie).

2. **Snapshot Immuable de la Story (`metadata`)** :
   - Afin que le fil de discussion puisse afficher la vignette de la Story même si celle-ci **expire après 24 heures ou est supprimée par son créateur**, un snapshot complet est stocké dans le champ JSON `metadata` de `story_replies` :
     - Identifiant de la Story
     - Handle et nom de l'auteur
     - URL du média et de la miniature
     - Légende textuelle et filtre appliqué
     - Date de publication

3. **Événement Métier Standardisé (`StoryReplyCreated`)** :
   - Dès l'enregistrement en base, l'événement Laravel `App\Events\Story\StoryReplyCreated` est diffusé sur le bus applicatif.
   - Son payload contient l'ensemble des données nécessaires à l'initialisation d'un fil de conversation.

4. **Réservation des Clés Étrangères de Rattachement** :
   - La table `story_replies` dispose déjà de deux colonnes nullables prêtes à accueillir les identifiants de messagerie :
     - `future_conversation_id` (identifiant du fil direct créé ou réutilisé)
     - `future_message_id` (identifiant du message créé dans ce fil)

### 4.3 Couverture Backend Globale de la Messagerie

| Opération Métier Messagerie | Statut Backend | Éléments Backend Existants | Éléments Manquants dans le Backend |
| :--- | :--- | :--- | :--- |
| **Gestion des Conversations** | **Absente** | Aucune table `threads` ou `conversations`. | Manque modèle `Conversation` et routes `GET /api/messages/threads`, `POST /threads`. |
| **Envoi & Lecture des Messages** | **Absente** | Aucune table `messages`. | Manque modèle `Message` et routes `GET /threads/{id}/messages`, `POST /messages`. |
| **Envoi de Notes Vocales Audio** | **Absente** | Seul l'upload audio pour Reels existe (`AdminSoundCatalog`). | Manque stockage CDN dédié et endpoint d'envoi audio `POST /threads/{id}/voice`. |
| **Contexte Annonce Immobilière** | **Absente** | Aucune relation `listing_id` dans un schéma de message. | Manque champ de métadonnées et clé étrangère `listing_id` dans les conversations. |
| **Conversations de Groupe** | **Absente** | Aucune table `conversation_members`. | Manque gestion des participants et des droits administrateurs de groupe. |
| **Demandes de Message** | **Absente** | Aucune table de filtrage de messages entrants. | Manque boîte de réception des requêtes et endpoints d'acceptation. |
| **Accusés de Lecture & Présence** | **Absente** | Aucun canal WebSocket ou Pusher configuré pour le chat. | Manque infrastructure d'événements broadcastés pour le chat temps réel. |
| **Réponse Privée Story** | **Partiellement développée** | Route `POST /api/stories/{id}/reply`, table `story_replies`, snapshot `metadata`, événement `StoryReplyCreated`. | Manque le Listener de messagerie qui consomme l'événement pour créer le fil et le message correspondant. |
