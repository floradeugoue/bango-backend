# API Backend Sans Module Frontend Direct (Back-Office & Webhooks) — Version Consolidée

**Projet :** BANGO Backend (Laravel 12 / PHP 8.4)  
**Date d'actualisation :** 16 Octobre 2026  
**Périmètre :** Routes backend existantes et fonctionnelles qui ne sont pas directement appelées par l'application mobile grand public BANGO, mais destinées au Back-Office d'administration, aux webhooks de passerelles tierces ou à la documentation OpenAPI.  

---

## Synthèse

Sur les **411 routes API** du backend Laravel, **82 routes** relèvent de la gestion administrative centrale, de l'instruction des dossiers légaux, du support client N2, de la gestion de catalogue ou de récepteurs d'événements asynchrones.

Ces API sont pleinement développées, protégées par des rôles RBAC étanches et couvertes par la suite de tests automatisés.

---

## 1. Back-Office — Gestion des Rôles & Permissions (6 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/roles` | Lister l'ensemble des rôles système définis (Admin, Modérateur, Support, Client, etc.) | Administration RBAC |
| `POST /api/roles` | Créer un nouveau rôle avec ses prérogatives | Administration RBAC |
| `GET /api/roles/{role}` | Consulter le détail et les permissions d'un rôle | Administration RBAC |
| `PUT /api/roles/{role}` | Mettre à jour la définition et les droits d'un rôle | Administration RBAC |
| `DELETE /api/roles/{role}` | Supprimer un rôle obsolète | Administration RBAC |
| `GET /api/roles/{role}/users` | Lister tous les utilisateurs rattachés à un rôle spécifique | Administration RBAC |

---

## 2. Back-Office — Supervision & Modération des Utilisateurs (13 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/users` | Annuaire global des usagers avec filtres avancés (recherche, statut, ville, rôle) | Supervision Usagers |
| `GET /api/users/{user}` | Consulter la fiche complète d'un utilisateur (y compris historique d'audit) | Supervision Usagers |
| `PATCH /api/users/{user}` | Modifier administrativement les données d'un usager | Support Client N2 |
| `DELETE /api/users/{user}` | Bannissement ou suppression administrative (soft delete immédiat) | Modération & Sanctions |
| `GET /api/users/{user}/role` | Consulter le rôle système assigné à un usager | Administration RBAC |
| `PATCH /api/users/{user}/role` | Promouvoir un usager (ex: passage au statut Modérateur ou Support) | Administration RBAC |
| `POST /api/users/{user}/suspend` | Suspendre immédiatement un compte avec motif de sanction et durée | Modération & Sanctions |
| `POST /api/users/{user}/unsuspend` | Lever manuellement une suspension suite à un recours accepté | Modération & Recours |
| `POST /api/users/{user}/block` | Bloquer définitivement un utilisateur au niveau plateforme | Sécurité & Fraude |
| `POST /api/users/{user}/unblock` | Débloquer administrativement un compte | Sécurité & Support |
| `GET /api/users/{user}/sessions` | Inspecter la liste des terminaux connectés d'un utilisateur suspect | Sécurité & Audit |
| `DELETE /api/users/{user}/sessions` | Révoquer de force toutes les sessions d'un compte compromis | Sécurité d'urgence |
| `DELETE /api/users/{user}/sessions/{session}` | Révoquer une session précise d'un utilisateur | Sécurité d'urgence |

---

## 3. Back-Office — Administration des Données Géographiques (15 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `POST`, `PUT`, `DELETE /api/geo/countries` | Gestion administrative du référentiel des pays | Référentiel Géo |
| `POST`, `PUT`, `DELETE /api/geo/currencies` | Gestion des devises monétaires supportées | Référentiel Géo |
| `POST`, `PUT`, `DELETE /api/geo/cities` | Gestion du catalogue des villes | Référentiel Géo |
| `POST`, `PUT`, `DELETE /api/geo/neighbourhoods` | Gestion des quartiers par ville | Référentiel Géo |
| `POST`, `PUT`, `DELETE /api/geo/operators` | Gestion des opérateurs télécoms et réseaux Mobile Money | Référentiel Télécom |

---

## 4. Back-Office — Catalogue des Offres & Tarifs d'Abonnement (6 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/catalog/products` | Lister tous les produits d'abonnement (Badges Bailleur, Agent) | Gestion Commerciale |
| `POST /api/admin/catalog/products` | Créer un nouveau produit d'abonnement | Gestion Commerciale |
| `GET /api/admin/catalog/products/{id}` | Afficher les détails d'un produit avec ses grilles tarifaires | Gestion Commerciale |
| `PUT /api/admin/catalog/products/{id}` | Mettre à jour les informations d'un produit | Gestion Commerciale |
| `POST /api/admin/catalog/products/{id}/prices` | Ajouter une nouvelle grille tarifaire (prix / période / pays) | Gestion Commerciale |
| `PUT /api/admin/catalog/prices/{priceId}` | Modifier un tarif existant (activation / désactivation) | Gestion Commerciale |

---

## 5. Back-Office — Supervision des Abonnements & Badges (7 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/subscriptions` | Relevé et filtrage de l'ensemble des abonnements actifs | Supervision Badges |
| `GET /api/admin/subscriptions/{id}` | Historique d'audit complet d'un abonnement | Supervision Badges |
| `POST /api/admin/subscriptions/{id}/suspend` | Suspendre administrativement un badge de confiance | Modération & Litiges |
| `POST /api/admin/subscriptions/{id}/reactivate` | Réactiver un abonnement suspendu | Support Client N2 |
| `POST /api/admin/subscriptions/{id}/extend` | Accorder une prolongation de période de grâce ou validité | Geste Commercial |
| `POST /api/admin/subscriptions/{id}/revoke` | Révocation définitive pour fraude avérée | Sanctions |
| `GET /api/admin/badges/history` | Journal d'audit global des mouvements de badges | Audit & Conformité |

---

## 6. Back-Office — Transactions, Litiges & Remboursements (11 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/payments/transactions` | Journal de supervision de tous les flux financiers | Finance & Comptabilité |
| `GET /api/admin/payments/transactions/{id}` | Fiche détaillée d'un flux de paiement | Finance & Comptabilité |
| `GET /api/admin/payments/transactions/{id}/events` | Relevé technique des callbacks de passerelle USSD | Traçabilité & Audit |
| `POST /api/admin/payments/transactions/{id}/refund` | Déclencher un remboursement manuel | Support Financier |
| `GET /api/admin/payments/disputes` | Liste de tous les litiges déclarés | Gestion des Conflits |
| `POST /api/admin/payments/disputes` | Ouvrir un dossier d'investigation sur un paiement | Gestion des Conflits |
| `GET /api/admin/payments/disputes/{id}` | Fiche d'instruction d'un litige | Gestion des Conflits |
| `POST /api/admin/payments/disputes/{id}/status` | Mettre à jour l'issue d'un litige (accepté, rejeté) | Gestion des Conflits |
| `POST /api/admin/payments/disputes/{id}/notes` | Consigner une note d'investigation interne | Support Interne |
| `GET /api/admin/payments/double-charge-claims` | Relevé de toutes les réclamations usagers de double débit | Finance & Litiges |
| `POST /api/admin/payments/double-charge-claims` | Saisie manuelle administrative d'une réclamation | Finance & Litiges |

---

## 7. Back-Office — Sonothèque Musicale (9 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/sounds` | Supervision du catalogue audio pour les Reels | Édition Multimédia |
| `POST /api/admin/sounds` | Téléverser et publier un nouveau morceau musical | Édition Multimédia |
| `GET` & `POST /api/admin/sounds/{id}` | Consulter et modifier les métadonnées d'un son | Édition Multimédia |
| `PATCH /api/admin/sounds/{id}/publication` | Basculer la visibilité d'un son dans le catalogue | Gestion Éditoriale |
| `PATCH /api/admin/sounds/{id}/rights` | Paramétrer les licences et droits de diffusion | Conformité Droits d'Auteur |
| `DELETE /api/admin/sounds/{id}` | Retirer temporairement un son (soft delete) | Gestion Éditoriale |
| `POST /api/admin/sounds/{id}/restore` | Restaurer un son au catalogue | Gestion Éditoriale |
| `DELETE /api/admin/sounds/{id}/force` | Suppression définitive et purge physique du fichier audio | Maintenance Disque |

---

## 8. Back-Office — Pages Professionnelles & Certifications (5 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/pages/certifications` | File d'attente d'instruction des dossiers KYC entreprise | Vérification Légale |
| `POST /api/admin/pages/certifications/{certification}/decide` | Valider (avec attribution du badge entreprise) ou rejeter un dossier | Décision Administrative |
| `POST /api/admin/pages/{page}/suspend-certification` | Suspendre temporairement le badge certifié d'une page | Modération d'Entreprise |
| `POST /api/admin/pages/{page}/suspend` | Suspendre totalement une Page Pro pour infraction | Sanctions |
| `POST /api/admin/pages/{page}/unsuspend` | Réhabiliter une Page Pro suspendue | Support Entreprise |

---

## 9. Back-Office — Billetterie Support & Tickets (4 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `GET /api/admin/support/tickets` | File des tickets usagers avec filtres par statut et priorité | Support Client N2 |
| `PATCH /api/admin/support/tickets/{id}/assign` | Affecter un ticket à un agent de support | Gestion d'Équipe |
| `POST /api/admin/support/tickets/{id}/messages` | Répondre au client ou ajouter une note interne confidentielle | Résolution d'Incidents |
| `PATCH /api/admin/support/tickets/{id}/status` | Mettre à jour l'état du ticket (`in_progress`, `resolved`, `closed`) | Résolution d'Incidents |

---

## 10. Back-Office — CMS Légal & Base de Connaissances (6 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné |
| :--- | :--- | :--- |
| `POST /api/admin/legal/documents` | Créer un nouveau document légal ou une version de CGU | Conformité Juridique |
| `PUT /api/admin/legal/documents/{id}` | Mettre à jour le contenu d'un document légal | Conformité Juridique |
| `POST /api/admin/legal/documents/{id}/publish` | Publier une version officielle et forcer la ré-acceptation | Conformité Juridique |
| `POST /api/admin/help/articles` | Créer un article de la base de connaissances FAQ | Centre d'Aide |
| `PUT /api/admin/help/articles/{id}` | Modifier le texte, la catégorie ou les mots-clés d'un article | Centre d'Aide |
| `DELETE /api/admin/help/articles/{id}` | Supprimer ou archiver un article obsolète | Centre d'Aide |

---

## 11. Webhooks Tiers & Outils (3 routes)

| Route (Méthode & URI) | Rôle Technique | Émetteur Tiers |
| :--- | :--- | :--- |
| `POST /api/webhooks/payments/monetbil` | Réception asynchrone des notifications de paiement (IPN) | Passerelle Monetbil |
| `POST /api/webhooks/cloudflare-stream` | Notification de fin de transcodage asynchrone vidéo | Cloudflare Stream |
| `GET /docs.openapi` | Spécification OpenAPI / Swagger générée dynamiquement | Scribe Documentation |
