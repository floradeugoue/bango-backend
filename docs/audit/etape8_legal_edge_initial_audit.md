# BANGO — AUDIT TECHNIQUE INITIAL : ÉTAPE 8
## FINALISATION DES MODULES LG (LÉGAL / RGPD) ET EDGE (CAS LIMITES)

**Date :** 16 Octobre 2026  
**Environnement :** Laravel 13, PostgreSQL 18.6, PHP 8.4, Pest PHP  
**Auteurs/Contexte :** Pair-programming BANGO Backend  

---

### 1. Cartographie de l'Existant et Écarts Identifiés

| Module & Fonctionnalité | État Constaté | Fichiers & Schémas Existants | Écarts par Rapport aux Exigences | Modifications & Composants à Développer |
| :--- | :---: | :--- | :--- | :--- |
| **LG-1 : CMS de documents légaux versionnés** | 🔴 Absent | Aucun modèle `LegalDocument`, aucune table `legal_documents`. Seul `DataExportRequest` existe dans `app/Models/Legal/`. | Aucune table pour stocker les documents (`slug`, `version`, `locale`, `effective_date`, `content_markdown`, `is_published`). Aucun endpoint de distribution. | Créer la migration `create_legal_documents_table`, modèle `LegalDocument`, `LegalDocumentService`, `LegalDocumentResource`, `LegalDocumentController` (`GET /api/legal/documents/{slug}`) avec sélection de la version active et fallback de langue. |
| **LG-2 : Traçabilité & Réacceptation obligatoire des CGU** | 🟡 Partiel | Mention documentaire de `terms_accepted_at`, mais aucune colonne sur `users` ni table d'historique de consentement. | Aucun stockage de la version acceptée, aucun historique d'acceptations successives, aucun endpoint `POST /api/legal/accept-terms`, aucun middleware bloquant pour les CGU devenues obsolètes. | Créer la table `terms_acceptances` (historique complet des versions et horodatages), colonnes `terms_accepted_at` et `accepted_terms_version` sur `users`, modèle `TermsAcceptance`, endpoint `POST /api/legal/accept-terms`, middleware `EnsureTermsAccepted`. |
| **EDGE-1 : Résolution des liens profonds universels** | 🔴 Absent | Méthode `shareUrl()` ponctuelle sur `Post`, mais aucune table de liens courts `/r/{code}` ni endpoint de résolution universelle `GET /api/content/resolve-link`. | Pas de résolveur unifié prenant en charge `Post`, `Reel`, `Listing`, `User`, `Page`, ni de distinction des états (public, privé, supprimé, archivé, bloqué par modération). | Créer la table `short_links`, modèle `ShortLink`, service `LinkResolverService`, `LinkResolverController` (`GET /api/content/resolve-link?path=...`), gestion des chemins canoniques et liens courts sans fuite de données privées. |
| **EDGE-2 : Codes d'erreur HTTP sémantiques** | 🟡 Partiel | Quelques exceptions spécifiques (Boost, Payment), mais pas d'exceptions standardisées pour les états de ressources (404, 410, 423). | Toutes les suppressions tombent souvent en 404 au lieu de distinguer les suppressions volontaires (410 Gone) et les blocages de modération (423 Locked). | Créer `ResourceNotFoundException` (404), `ResourceGoneException` (410), `ResourceLockedException` (423), centraliser la normalisation JSON dans `bootstrap/app.php` et brancher le résolveur et les services dessus. |

---

### 2. Plan d'Implémentation Séquentiel

1. **Phase 1 — LG : CMS de Documents Légaux Versionnés**
   - Migration `2026_10_16_180000_create_legal_documents_table.php` (`id`, `slug`, `version`, `locale`, `title`, `content_markdown`, `effective_date`, `is_published`, timestamps, contrainte d'unicité `['slug', 'version', 'locale']`).
   - Modèle Eloquent `App\Models\Legal\LegalDocument`.
   - Service `App\Services\Legal\LegalDocumentService` (recherche publiée, fallback locale `fr`, sélection de la version active).
   - Resource `App\Http\Resources\Legal\LegalDocumentResource`.
   - Contrôleur `App\Http\Controllers\Legal\LegalDocumentController` (`GET /api/legal/documents/{slug}`).
   - Tests Pest ciblés.

2. **Phase 2 — LG : Acceptation & Réacceptation Obligatoire des CGU**
   - Migration `2026_10_16_180100_create_terms_acceptances_table.php` (`id`, `user_id`, `version`, `document_slug`, `locale`, `ip_address`, `user_agent`, `accepted_at`, timestamps) et ajout de `terms_accepted_at` / `accepted_terms_version` sur `users`.
   - Modèle `App\Models\Legal\TermsAcceptance` et relation sur `User`.
   - Service d'acceptation dans `LegalDocumentService` (idempotence, détection de version obligatoire côté serveur).
   - Contrôleur `App\Http\Controllers\Legal\TermsAcceptanceController` (`POST /api/legal/accept-terms`).
   - Middleware `App\Http\Middleware\EnsureTermsAccepted` (contrôle si l'utilisateur a accepté la version requise, blocage avec 403 `TERMS_ACCEPTANCE_REQUIRED`, exemptions pour auth, légal, profil de conformité).
   - Tests Pest ciblés.

3. **Phase 3 — EDGE : Résolution des Liens Profonds Universels**
   - Migration `2026_10_16_180200_create_short_links_table.php` (`id`, `code`, `target_type`, `target_id`, `creator_id`, `clicks_count`, `expires_at`, timestamps).
   - Modèle `App\Models\Content\ShortLink`.
   - Service `App\Services\Content\LinkResolverService` prenant en charge :
     - Liens courts `/r/{code}`
     - Chemins `/posts/{id}`, `/reels/{id}`, `/listings/{id}`, `/users/{id}` (ou `/@handle`), `/pages/{id}` (ou `/pages/{handle}`).
     - États : valide public, valide privé (requires_auth), supprimé (gone), modéré/gelé (locked), introuvable.
   - Resource `App\Http\Resources\Content\ResolvedLinkResource`.
   - Contrôleur `App\Http\Controllers\Content\LinkResolverController` (`GET /api/content/resolve-link`).
   - Tests Pest ciblés.

4. **Phase 4 — EDGE : Codes d'Erreur HTTP Sémantiques (404, 410, 423)**
   - Exceptions métier :
     - `App\Exceptions\Content\ResourceNotFoundException` (404)
     - `App\Exceptions\Content\ResourceGoneException` (410)
     - `App\Exceptions\Content\ResourceLockedException` (423)
   - Configuration centralisée du rendu JSON dans `bootstrap/app.php`.
   - Helper d'état de disponibilité dans `App\Services\Content\ContentAvailabilityService`.
   - Tests Pest ciblés.

5. **Phase 5 & 6 — Qualité, Raccordements, Formatage & Documentation**
   - Validation Pint : `vendor/bin/pint --format agent`.
   - Tests Pest complets de l'étape et régression.
   - Documentation technique détaillée dans `docs/transverse/etape8_legal_edge_documentation.md`.
