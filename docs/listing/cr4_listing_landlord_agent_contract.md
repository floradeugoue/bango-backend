# Fondations et Contrats Backend — Espaces Bailleur (BL) & Agent Immobilier (AG)

**Statut du document** : Spécification des fondations de données, relations Eloquent et autorisations préparées pour les futurs espaces Bailleur et Agent.
**Important** : Les modules Bailleur complet (revenus, comptabilité, gestion locative, baux) et Agent complet (portefeuille de mandats, CRM, commissions) n'ont pas été développés à ce stade afin de respecter strictement le périmètre de CR4.

---

## 1. Distinction des Rôles et Responsabilités

Le schéma de données `listings` distingue clairement :
- **`owner_id` (Propriétaire / Bailleur)** : Le compte utilisateur juridiquement propriétaire du bien ou détenteur principal de l'annonce.
- **`created_by_id` (Créateur initial)** : L'utilisateur physique ayant saisi l'annonce (peut être le bailleur lui-même ou son agent mandataire).
- **`agent_id` (Agent Gestionnaire)** : Le professionnel de l'immobilier mandaté pour gérer les visites, les requêtes et les mises à jour de l'annonce.

Cette séparation évite toute confusion entre la propriété d'un actif immobilier et sa gestion opérationnelle.

---

## 2. Fondations Disponibles et Opérationnelles

### 2.1. Espace Bailleur (BL)

Les mécanismes suivants sont dès à présent actifs et testés :
1. **Rattachement automatique** :
   Toute annonce créée par un bailleur certifié est rattachée à son `owner_id`.
2. **Reprise des brouillons** :
   Le bailleur peut reprendre son formulaire inachevé via `GET /api/listings/form/draft` ou avec son `submission_key` sans duplication.
3. **Mise à jour granulaire des détails (CR-48)** :
   Le bailleur peut modifier ses annonces via `PATCH /api/listings/{id}`.
4. **Gestion autonome des statuts (BL-18)** :
   Le bailleur peut faire transiter son bien entre `published`, `reserved` (réservé par un locataire) et `taken` (occupé / loué / vendu) via `PATCH /api/listings/{id}/status`.
5. **Gestion des médias** :
   Ajout, ordonnancement (`reorder`) et suppression sécurisée des photos et de la vidéo de visite guidée.
6. **Relation Eloquent User** :
   `$user->listings()` retourne la collection de toutes les annonces appartenant au bailleur.

### 2.2. Espace Agent (AG)

Les fondations suivantes sont prêtes :
1. **Délégation d'annonce (`agent_id`)** :
   Une annonce peut être confiée à un agent via la colonne `agent_id`.
2. **Politique d'accès ciblée (`ListingPolicy`)** :
   Un agent ne dispose pas d'un droit global de modification. Il peut modifier ou gérer uniquement les annonces qui lui sont explicitement assignées (`$listing->agent_id === $agent->id`) ou qu'il a créées.
3. **Relation Eloquent User** :
   `$user->managedListings()` retourne les annonces gérées par l'agent.
4. **Indicateur d'inspection (`agent_visited`)** :
   Permet d'afficher le badge *"Visité par un agent Bango"* sur les fiches d'annonces vérifiées sur le terrain.

---

## 3. Ce qui Devra Être Développé dans les Prochaines Étapes

Les briques suivantes feront l'objet de modules ultérieurs dédiés :

| Domaine | Fonctionnalités ultérieures |
|---|---|
| **Espace Bailleur (BL)** | - Tableau de bord de rentabilité locative<br>- Gestion des contrats de bail et états des lieux numériques<br>- Encaissement des loyers et quittances électroniques<br>- Suivi des incidents et demandes de travaux |
| **Espace Agent (AG)** | - Table complète des mandats de gestion (`mandates`) avec dates de validité et pièces justificatives<br>- Calendrier des créneaux de visites et confirmations SMS/Push<br>- Statistiques de performance et calcul des commissions<br>- Tableau de bord d'agence multi-négociateurs |

---

## 4. Points d'Extension Recommandés

Pour brancher le futur système de mandats sans altérer l'API existante :
1. Créer la table `listing_mandates` avec `listing_id`, `agent_id`, `owner_id`, `status` (`active`, `expired`, `revoked`).
2. Mettre à jour `ListingPolicy::update()` pour vérifier l'existence d'un mandat actif dans `listing_mandates` en complément de `listing->agent_id`.
3. Conserver le contrat `ListingResource` inchangé : la ressource expose déjà les métadonnées de l'agent et du propriétaire de façon découplée.
