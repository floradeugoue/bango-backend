# API Backend Sans Module Frontend Identifié (Back-Office & Webhooks)

**Projet :** BANGO Backend (Laravel)  
**Date de l'audit :** Octobre 2026  
**Périmètre :** Routes backend existantes et fonctionnelles qui ne sont pas directement appelées par l'application mobile grand public BANGO, mais destinées au Back-Office d'administration, aux webhooks tiers ou à la supervision.

---

## Synthèse

Sur les **241 routes API** du backend Laravel, **68 routes** relèvent de la gestion administrative, de la modération centrale, de l'alimentation des référentiels ou de récepteurs d'événements asynchrones.

Ces API sont pleinement développées et indispensables à l'exploitation de la plateforme, mais ne correspondent à aucun écran du périmètre mobile utilisateur final.

---

## 1. Back-Office — Gestion des Rôles & Permissions (6 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Module Frontend Éventuellement Susceptible de l'Utiliser |
| :--- | :--- | :--- | :--- |
| `GET /api/roles` | Lister l'ensemble des rôles système définis (Admin, Modérateur, Support, Client, etc.) | Administration RBAC | Aucun (strictement réservé au portail web d'administration BANGO Back-Office). |
| `POST /api/roles` | Créer un nouveau rôle avec ses prérogatives | Administration RBAC | Back-Office d'administration. |
| `GET /api/roles/{role}` | Consulter le détail et les permissions d'un rôle | Administration RBAC | Back-Office d'administration. |
| `PUT /api/roles/{role}` | Mettre à jour la définition et les droits d'un rôle | Administration RBAC | Back-Office d'administration. |
| `DELETE /api/roles/{role}` | Supprimer un rôle obsolète | Administration RBAC | Back-Office d'administration. |
| `GET /api/roles/{role}/users` | Lister tous les utilisateurs rattachés à un rôle spécifique | Administration RBAC | Back-Office d'administration. |

---

## 2. Back-Office — Supervision & Modération des Utilisateurs (13 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Module Frontend Éventuellement Susceptible de l'Utiliser |
| :--- | :--- | :--- | :--- |
| `GET /api/users` | Annuaire global des usagers avec filtres avancés (recherche, statut, ville, rôle) | Supervision Usagers | Back-Office (pourrait alimenter un écran d'annuaire interne ou de modération). |
| `GET /api/users/{user}` | Consulter la fiche complète d'un utilisateur (y compris historique d'audit) | Supervision Usagers | Back-Office d'administration. |
| `PATCH /api/users/{user}` | Modifier administrativement les données d'un usager | Support Client | Back-Office / Support N2. |
| `DELETE /api/users/{user}` | Bannissement ou suppression administrative (soft delete immédiat) | Modération & Sanctions | Back-Office d'administration. |
| `GET /api/users/{user}/role` | Consulter le rôle système assigné à un usager | Administration RBAC | Back-Office d'administration. |
| `PATCH /api/users/{user}/role` | Promouvoir un usager (ex: passage au statut Modérateur ou Support) | Administration RBAC | Back-Office d'administration. |
| `POST /api/users/{user}/suspend` | Suspendre immédiatement un compte avec motif de sanction et durée | Modération & Sécurité | Résultat visible indirectement côté mobile sur `AU-26` (`suspended.tsx`). |
| `POST /api/users/{user}/unsuspend` | Lever manuellement une suspension suite à un recours accepté | Modération & Recours | Découle du traitement d'un appel mobile `app/appeal.tsx` (`SUP-24`). |
| `POST /api/users/{user}/block` | Bloquer définitivement un utilisateur au niveau plateforme | Sécurité & Fraude | Back-Office d'administration. |
| `POST /api/users/{user}/unblock` | Débloquer administrativement un compte | Sécurité & Support | Back-Office d'administration. |
| `GET /api/users/{user}/sessions` | Inspecter la liste des terminaux connectés d'un utilisateur suspect | Sécurité & Audit | Back-Office d'investigation fraude. |
| `DELETE /api/users/{user}/sessions` | Révoquer de force toutes les sessions d'un compte compromis | Sécurité d'urgence | Déclenché administrativement pour sécuriser un compte piraté. |
| `DELETE /api/users/{user}/sessions/{session}` | Révoquer une session précise d'un utilisateur | Sécurité d'urgence | Back-Office d'administration. |

---

## 3. Back-Office — Administration des Données Géographiques (15 routes)

Le frontend mobile consomme les données géographiques en **lecture seule** (`GET /api/geo/*`). Le CRUD de création, modification et suppression appartient au back-office pour alimenter la plateforme.

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `POST /api/geo/countries` | Ajouter un nouveau pays desservi | Référentiel Géo | Back-Office (ex: ouverture de BANGO en Côte d'Ivoire ou au Sénégal). |
| `PUT /api/geo/countries/{id}` | Modifier indicatifs, règles de validation téléphonique | Référentiel Géo | Back-Office. |
| `DELETE /api/geo/countries/{id}` | Désactiver un pays de la plateforme | Référentiel Géo | Back-Office. |
| `POST /api/geo/currencies` | Ajouter une nouvelle devise | Référentiel Devises | Back-Office. |
| `PUT /api/geo/currencies/{id}` | Ajuster format, taux de référence ou libellé de devise | Référentiel Devises | Back-Office. |
| `DELETE /api/geo/currencies/{id}` | Supprimer une devise | Référentiel Devises | Back-Office. |
| `POST /api/geo/cities` | Ajouter une nouvelle ville | Référentiel Villes | Back-Office (ex: ajout de Bafoussam, Garoua, etc.). |
| `PUT /api/geo/cities/{id}` | Modifier coordonnées géographiques de centrage de la ville | Référentiel Villes | Back-Office. |
| `DELETE /api/geo/cities/{id}` | Désactiver une ville | Référentiel Villes | Back-Office. |
| `POST /api/geo/neighbourhoods` | Enregistrer un nouveau quartier | Référentiel Quartiers | Back-Office (ex: enrichissement du maillage d'Almadies, Bonapriso, etc.). |
| `PUT /api/geo/neighbourhoods/{id}` | Corriger libellé ou limites géographiques d'un quartier | Référentiel Quartiers | Back-Office. |
| `DELETE /api/geo/neighbourhoods/{id}` | Supprimer un quartier | Référentiel Quartiers | Back-Office. |
| `POST /api/geo/operators` | Ajouter un opérateur téléphonique ou Mobile Money | Référentiel Opérateurs | Back-Office (ex: ajout de Wave, Moov, Orange, MTN). |
| `PUT /api/geo/operators/{id}` | Modifier préfixes de numéros ou paramètres USSD | Référentiel Opérateurs | Back-Office. |
| `DELETE /api/geo/operators/{id}` | Désactiver un opérateur temporairement en panne | Référentiel Opérateurs | Back-Office. |

---

## 4. Back-Office — Catalogue des Abonnements & Tarifs (6 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `GET /api/admin/catalog/products` | Liste exhaustive des produits (actifs, brouillons, archivés) | Gestion des Offres | Back-Office commercial. |
| `POST /api/admin/catalog/products` | Créer un nouveau produit d'abonnement ou badge de confiance | Gestion des Offres | Back-Office (permet de créer les offres Bailleur, Agent, etc.). |
| `GET /api/admin/catalog/products/{id}` | Détail d'un produit avec historique de ses grilles tarifaires | Gestion des Offres | Back-Office commercial. |
| `PUT /api/admin/catalog/products/{id}` | Mettre à jour titre, avantages et conditions d'une offre | Gestion des Offres | Back-Office. |
| `POST /api/admin/catalog/products/{id}/prices` | Ajouter une nouvelle grille tarifaire (prix / période / pays) | Gestion des Tarifs | Permet de configurer les prix en XAF, XOF, GHS, NGN. |
| `PUT /api/admin/catalog/prices/{priceId}` | Ajuster ou désactiver un tarif existant | Gestion des Tarifs | Back-Office financier. |

---

## 5. Back-Office — Supervision des Abonnements & Badges (7 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `GET /api/admin/subscriptions` | Tableau de bord de l'ensemble des souscriptions BANGO | Supervision Abonnements | Back-Office support et gestion financière. |
| `GET /api/admin/subscriptions/{id}` | Fiche détaillée d'un contrat avec journal des événements | Supervision Abonnements | Back-Office support. |
| `POST /api/admin/subscriptions/{id}/suspend` | Suspendre un abonnement pour défaut ou enquête | Supervision Abonnements | Déclenche côté mobile le statut `badgeSuspended` (`AG-06`, `EDGE-07`). |
| `POST /api/admin/subscriptions/{id}/reactivate` | Réactiver un abonnement suspendu après régularisation | Supervision Abonnements | Restaure les droits côté application mobile. |
| `POST /api/admin/subscriptions/{id}/extend` | Accorder un geste commercial ou une extension de validité | Fidélisation & Support | Prolonge la date de fin sans exiger de paiement immédiat. |
| `POST /api/admin/subscriptions/{id}/revoke` | Révoquer définitivement un abonnement et retirer le badge | Modération & Sanctions | Retire le badge d'autorité sur le profil mobile. |
| `GET /api/admin/badges/history` | Journal d'audit central des attributions et retraits de badges | Audit & Conformité | Registre immuable de traçabilité pour les litiges. |

---

## 6. Back-Office — Supervision Financière, Litiges & Remboursements (9 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `GET /api/admin/payments/transactions` | Journal général des transactions financières de la plateforme | Supervision Comptable | Back-Office financier. |
| `GET /api/admin/payments/transactions/{id}` | Détail technique d'une transaction (logs opérateur, IPN) | Audit Financier | Back-Office support financier. |
| `GET /api/admin/payments/transactions/{id}/events` | Chronologie complète des événements d'une transaction | Audit Financier | Analyse des échecs de paiement ou délais USSD. |
| `POST /api/admin/payments/transactions/{id}/refund` | Déclencher un remboursement manuel vers l'utilisateur | Remboursements | Résout un litige ou une annulation de visite par le bailleur (`IM-15`). |
| `GET /api/admin/payments/disputes` | Liste de tous les litiges financiers ouverts | Gestion des Litiges | Back-Office contentieux. |
| `POST /api/admin/payments/disputes` | Déclarer un nouveau dossier de contestation bancaire | Gestion des Litiges | Back-Office contentieux. |
| `GET /api/admin/payments/disputes/{id}` | Fiche d'instruction d'un litige | Gestion des Litiges | Back-Office contentieux. |
| `POST /api/admin/payments/disputes/{id}/status` | Mettre à jour le statut d'un litige (en cours, réglé, rejeté) | Gestion des Litiges | Back-Office contentieux. |
| `POST /api/admin/payments/disputes/{id}/notes` | Ajouter une note interne d'enquête sur un litige | Gestion des Litiges | Back-Office contentieux. |
| `GET /api/admin/payments/double-charge-claims` | Registre des réclamations de double débit Mobile Money | Gestion des Litiges | Supervision des signalements de double prélèvement (`PAY-15`). |
| `POST /api/admin/payments/double-charge-claims` | Enregistrer une réclamation de double débit | Gestion des Litiges | Actuellement réservé à l'admin ; le mobile pourrait en bénéficier. |

---

## 7. Back-Office — Sonothèque Audio & Droits Musicaux (9 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `GET /api/admin/sounds` | Inventaire complet de la sonothèque (actifs, masqués, purgés) | Sonothèque & Droits | Back-Office éditorial audio. |
| `POST /api/admin/sounds` | Uploader et indexer un nouveau morceau audio officiel | Alimentation Catalogue | Enrichit le catalogue accessible aux créateurs de Reels (`CR-21`). |
| `GET /api/admin/sounds/{id}` | Détails techniques et juridiques d'un son | Droits d'Auteur | Back-Office juridique. |
| `POST /api/admin/sounds/{id}` | Mettre à jour fichier audio, pochette ou métadonnées | Éditorial Audio | Back-Office. |
| `PATCH /api/admin/sounds/{id}/publication` | Activer ou désactiver la visibilité d'un son dans le catalogue | Modération Audio | Retire un son signalé pour atteinte aux droits (`LG-17`). |
| `PATCH /api/admin/sounds/{id}/rights` | Mettre à jour licences et contrats de diffusion d'un morceau | Droits d'Auteur | Back-Office juridique. |
| `DELETE /api/admin/sounds/{id}` | Désactiver et masquer un son (soft delete) | Éditorial Audio | Back-Office. |
| `POST /api/admin/sounds/{id}/restore` | Restaurer un son précédemment retiré | Éditorial Audio | Back-Office. |
| `DELETE /api/admin/sounds/{id}/force` | Suppression définitive et purge physique du fichier audio | Maintenance | Purge physique du stockage CDN. |

---

## 8. Webhooks Asynchrones & Documentation (3 routes)

| Route (Méthode & URI) | Fonction Métier | Domaine Concerné | Remarques d'Utilisation |
| :--- | :--- | :--- | :--- |
| `POST /api/webhooks/payments/monetbil` | Notification instantanée de paiement (IPN) Monetbil | Paiements Automatisés | Point d'entrée public appelé par les serveurs Monetbil pour valider les encaissements en tâche de fond. |
| `POST /api/webhooks/cloudflare-stream` | Notification de fin de transcodage vidéo Cloudflare | Média & Vidéo | Déclenché par Cloudflare Stream dès qu'une vidéo de Reel a été encodée en formats adaptatifs. |
| `GET docs.openapi` | Documentation OpenAPI / Swagger de l'API | Documentation | Généré par Scribe pour l'équipe de développement et les tiers. |
