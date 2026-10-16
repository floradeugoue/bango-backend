# BANGO — Audit Pré-Implémentation : Étape 7
## Durcissement de l'Éligibilité au Boost, Raccordement SUB + PG et Moteur Publicitaire

**Date :** 16 Octobre 2026  
**Environnement :** Laravel 13, PostgreSQL, PHP 8.4, Pest PHP  
**Statut de la suite de tests :** 638 tests passés (3 098 assertions) ✅

---

### 1. Tableau de Synthèse de l'Audit (Livrable 2.3)

| Fonctionnalité | État constaté | Éléments existants trouvés | Action requise |
| :--- | :--- | :--- | :--- |
| **Autorisation `boost.start`** | **Absente** | Aucune Gate `boost.start` déclarée dans `AppServiceProvider` ; `CampaignPolicy::create()` renvoie inconditionnellement `true`. | **Créer et enregistrer la Gate `boost.start`** avec règle serveur unique liant SUB, BADGE, KYC et PG. Appeler cette Gate dans `CampaignController::store`, `BoostEstimateController::estimate` et `CampaignPolicy`. |
| **Vérification du badge actif (BADGE)** | **Partiellement intégrée** | `BadgeResolutionService` résout `hasLandlordBadge` et `hasAgentBadge` avec vérification KYC (`is_verified = true`, `verification_lapsed = false`). `User::hasActiveSubscription()` existe. | **Raccorder** : La Gate `boost.start` et `CampaignService` doivent interroger `BadgeResolutionService` pour valider que le compte personnel possède un badge actif non révoqué/suspendu. |
| **Vérification SUB (Abonnements)** | **Opérationnelle dans SUB** | Modèles `Subscription`, `SubscriptionProduct`, scopes `active()`, `usable()`, gestion de la période de grâce (`grace`). | **Raccorder** : Vérifier que pour un compte personnel, un abonnement `landlord` ou `agent` est actif ou en période de grâce valide avant tout lancement de boost. |
| **Éligibilité des Pages Pro (PG)** | **Présente dans PG mais non raccordée à BO** | Modèles `Page`, `PageMember`, `PageCertification`, méthodes `canUserPublish()`, `isOwner()`, `isCertified()`. Migration `add_page_id_to_related_tables` a déjà ajouté `page_id` à `campaigns`. | **Raccorder** : Vérifier que la Page est active (`status = 'active'`), certifiée (`certification_status = 'approved'`), et que l'utilisateur est membre accepté avec rôle `owner` ou `editor`. Interdire l'accès aux membres révoqués. |
| **Lancement des campagnes (BO)** | **Opérationnel mais permissif** | `CampaignService`, `CampaignController`, statuts `draft`, `pending`, `active`, `paused`, `completed`, `cancelled`. Débit atomique Crédit BANGO. | **Durcir** : Ajouter le statut `suspended`, vérifier la Gate `boost.start`, associer `page_id` à la création et dans `StoreCampaignRequest`, sécuriser la transition de statut et le motif d'arrêt (`stopped_reason`). |
| **Injection Square (SQ)** | **Manquante** | `PostFeedService` pagine les posts organiques (`discover`, `following`, `neighbourhood`), mais n'injecte aucune publication sponsorisée active. | **Compléter** : Intégrer la sélection des campagnes actives éligibles, appliquer le ciblage géographique et la déduplication, et enrichir `PostResource` avec `is_sponsored`, `sponsored_by`, `campaign_id`. |
| **Injection Reels (RL)** | **Manquante** | `ReelFeedService` filtre les reels prêts (`ready`), sans injection publicitaire de Reels boostés. | **Compléter** : Intégrer l'injection publicitaire dans `ReelFeedService`, vérifier l'accessibilité du média, ajouter les flags `is_sponsored`, `campaign_id` dans `ReelResource`. |
| **Consentement publicitaire (LG)** | **Non raccordé au ciblage** | `PrivacyController` gère `privacy_settings`, mais le flag `ads_personalization` n'était pas explicité. | **Compléter** : Ajouter `ads_personalization` dans `DEFAULT_PRIVACY`, et dans le moteur publicitaire, restreindre au ciblage géographique générique (sans profilage âge/intérêts) si l'utilisateur refuse. |
| **Modération & Indisponibilité (SUP, IM/BL)** | **Partielle** | `CampaignService` vérifie au lancement si le post est archivé ou si l'annonce est `taken`. | **Compléter** : Si une annonce devient `taken`, si un post/reel est supprimé ou suspendu, ou si un compte/page est suspendu, la campagne active doit être immédiatement arrêtée/suspendue de la diffusion. |
| **Métriques & Collecte** | **Partielle** | `CampaignMetric` et méthode `recordDailyMetric()` existent, mais aucun endpoint public d'incrémentation atomique sécurisé. | **Compléter** : Ajouter `POST /api/boost/campaigns/{id}/impression` et `POST /api/boost/campaigns/{id}/click` avec protection anti-fraude. |
| **Notifications (NOTIF)** | **Manquante pour BO** | Notifications existantes pour Visit, Subscription, Kyc, Delegation, Page, Message. Aucune notification pour BO. | **Créer** : `CampaignActivatedNotification`, `CampaignSuspendedNotification`, `CampaignCompletedNotification`. |
| **Tests et documentation** | **638 tests existants** | 4 tests de feature dans `tests/Feature/Boost`. | **Étendre** : Ajouter une suite exhaustive de tests Pest couvrant l'éligibilité, les refus, le sponsoring Page Pro, l'injection Square/Reels, le consentement LG et les cas EDGE. |

---

### 2. Risques de Régression et Points de Vigilance
1. **Tests existants de BO :** `CampaignCreationTest` crée des utilisateurs de test sans abonnement SUB. Si `CampaignController::store` appelle la Gate `boost.start`, les tests existants devront soit doter l'utilisateur d'un badge/abonnement, soit tester les deux branches (refus sans badge, succès avec badge).
2. **Intégrité financière Crédit BANGO :** Ne pas modifier la règle de débit atomique avec verrou pessimiste et idempotence absolue déjà validée.
3. **Performance des feeds :** L'injection publicitaire dans Square et Reels ne doit pas générer de requêtes N+1 ni ralentir le chargement initial.
