# Audit Initial — Étape 7 : Authentification avancée, OAuth Google/Apple, 2FA TOTP et lancement applicatif

**Date de réalisation :** 16 octobre 2026  
**Auteur :** Assistant IA Antigravity  
**Projet :** BANGO Backend (Laravel 13, PHP 8.4, PostgreSQL, Pest)  
**Modules concernés :** SP (Splash & Version), AU (Authentification & Onboarding), ST (Paramètres & Sécurité)

---

## 1. État des lieux et Matrice d'Audit

| Fonctionnalité | État constaté | Fichiers réels concernés | Action |
| :--- | :--- | :--- | :--- |
| **Version minimale** (`GET /api/system/version`) | **Absent** | Backend: absent de `routes/api.php` et `app/Http/Controllers/System/`<br>Frontend: `Bango/app/update-required.tsx`, `Bango/lib/api/serviceStatus.ts` | **Créer** contrôleur, service de version sémantique, configuration, route publique et tests Pest. |
| **Google OAuth** (`POST /api/auth/provider`) | **Absent** | Backend: absent de `routes/api.php`, pas de colonnes `google_id` ni table d'association<br>Frontend: `Bango/app/(auth)/sign-in.tsx`, `Bango/lib/auth/google.ts` | **Créer** service de vérification d'ID token cryptographique (aud/iss/sub/nonce/claims), contrôleur, migration `oauth_accounts`, intégration flux session BANGO et tests. |
| **Apple Sign In** (`POST /api/auth/provider`) | **Absent** | Backend: absent de `routes/api.php`, pas de colonnes `apple_id`<br>Frontend: `Bango/app/(auth)/sign-in.tsx`, `Bango/lib/auth/apple.ts` | **Créer** service de vérification JWT Apple (clés publiques JWKS, relay email, sub, nom), contrôleur commun, liaison sécurisée de compte et tests. |
| **Suggestions de pseudos** (`POST /api/profile/handle-suggestions`) | **Partiel** | Backend: `App/Http/Controllers/Profile/HandleCheckController.php` valide un handle existant, mais endpoint de suggestion absent de `routes/api.php`<br>Frontend: `Bango/app/(auth)/identity.tsx`, `docs/API_CONTRACTS.md` | **Compléter** en créant `HandleSuggestionController` et `HandleSuggestionService` générant 4 à 5 suggestions vérifiées en base selon les règles réelles (3-20 car, `[a-z0-9.]`, exclus mots réservés). |
| **Matching des contacts** (`POST /api/friends/match-fingerprints`) | **Absent** | Backend: aucune route ni contrôleur pour les empreintes<br>Frontend: `Bango/app/(auth)/friends.tsx`, `Bango/lib/api/friends.ts` (`matchContacts`) | **Créer** `ContactMatchingController`, normalisation des numéros E.164, matching cryptographique / empreinte sécurisée, exclusion stricts des profils masqués/suspendus/bloqués et limitation de débit/taille. |
| **2FA TOTP (Setup / Verify)** (`POST /api/settings/2fa/totp/setup`, `verify`) | **Partiel** | Backend: `users.two_factor_method`, `two_factor_secret` existent en base, mais aucun endpoint setup/verify TOTP n'est implémenté ; `SigninController` ne gère que SMS/Email OTP ; `SecondFactorController` n'a pas de TOTP | **Créer** service TOTP RFC 6238, chiffrement du secret au repos, routes `setup` et `verify`, validation anti-rejeu et intégration au challenge de connexion. |
| **Codes de secours 2FA** (`POST /api/settings/2fa/backup-codes`) | **Partiel & Incohérent** | Backend: `users.backup_codes` existe mais sans route de génération et le prototype stockait les codes en clair ! | **Corriger et compléter** : génération de 8 codes cryptographiques, hachage sécurisé au repos, affichage unique, consommation atomique et usage unique lors du challenge. |

---

## 2. Dépendances et Écosystème

- **Framework :** Laravel 13.17 sur PHP 8.4.
- **Authentification :** Laravel Passport (Personal Access Tokens via `auth:api`).
- **Tests :** Pest 5.1 (833 tests existants, 100% verts).
- **Sécurité :** Cryptographie native PHP/Laravel (`hash_hmac`, `sodium`, `openssl`, `Illuminate\Support\Facades\Crypt`, `Illuminate\Support\Facades\Hash`).
- Aucun package tiers non maîtrisé ne sera ajouté pour le TOTP ou l'OAuth si les bibliothèques PHP natives et les composants Laravel permettent une implémentation plus robuste, contrôlée et sans risque de conflit avec PHP 8.4.

---

## 3. Plan d'Exécution par Phases

1. **Phase 1 — SP : Contrôle de version minimale (`GET /api/system/version`)**
2. **Phase 2 — AU : Authentification Google et Apple (`POST /api/auth/provider`)**
3. **Phase 3 — AU : Suggestions automatiques de pseudonymes (`POST /api/profile/handle-suggestions`)**
4. **Phase 4 — AU : Rapprochement du carnet d'adresses (`POST /api/friends/match-fingerprints`)**
5. **Phase 5 — ST : Configuration et activation du 2FA TOTP (`POST /api/settings/2fa/totp/setup`, `verify`)**
6. **Phase 6 — ST : Codes de secours 2FA à usage unique (`POST /api/settings/2fa/backup-codes`)**
7. **Phase 7 à 12 : Architecture, Mobile, Régressions, Tests complets, Documentation et Bouclage**
