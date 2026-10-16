# BANGO — ÉTAPE 7 : AUTHENTIFICATION AVANCÉE, OAUTH GOOGLE/APPLE, 2FA TOTP ET LANCEMENT APPLICATIF

## 1. Vue d'Ensemble & Objectifs

L'Étape 7 structure et consolide l'ensemble du cycle de vie d'authentification, de sécurité et d'onboarding de l'application BANGO :
- **SP — Splash & États Système** : Contrôle de version sémantique minimale et blocage des versions obsolètes (`GET /api/system/version`).
- **AU — Authentification & Onboarding** :
  - Authentification sociale sécurisée Google & Apple (`POST /api/auth/provider`) avec validation cryptographique RS256 / JWKS, politique anti auto-merge et support du 2FA.
  - Moteur intelligent de suggestions de pseudonymes disponibles (`POST /api/profile/handle-suggestions`).
  - Rapprochement chiffré du carnet d'adresses (`POST /api/friends/match-fingerprints`) respectant la stricte confidentialité des numéros et les blocages mutuels.
- **ST — Paramètres & Sécurité** :
  - Double authentification TOTP conforme RFC 6238 avec protection anti-rejeu et chiffrement au repos (`POST /api/settings/2fa/totp/setup`, `POST /api/settings/2fa/totp/verify`, `POST /api/settings/2fa/disable`).
  - Codes de secours à usage unique (8 codes au format `XXXX-XXXX`, stockés hachés SHA-256, consommation atomique avec verrou de ligne).

---

## 2. Matrice d'Architecture & Endpoints

| Domaine | Méthode & Route | Contrôleur / Service | Middleware | Description |
| :--- | :--- | :--- | :--- | :--- |
| **SP** | `GET /api/system/version` | `AppVersionController`<br>`AppVersionService` | Public | Vérification de la compatibilité de version minimale (`supported`, `update_required`, `critical_update`). |
| **AU** | `POST /api/auth/provider` | `SocialAuthController`<br>`SocialAuthService`<br>`GoogleTokenVerifier`<br>`AppleTokenVerifier` | Public | Connexion / Inscription OAuth (Google/Apple), détection de conflits d'emails et challenge 2FA. |
| **AU** | `POST /api/profile/handle-suggestions` | `HandleSuggestionController`<br>`HandleSuggestionService` | Public (Rate-limited) | Propose 4 à 5 suggestions élégantes et vérifiées libres en base de données selon le nom, prénom et ville. |
| **AU** | `POST /api/friends/match-fingerprints` | `ContactMatchingController`<br>`ContactMatchingService` | `auth:api` (10 req/min) | Rapprochement chiffré d'un carnet de contacts (max 500 entrées) sans aucune fuite de numéros. |
| **ST** | `POST /api/settings/2fa/totp/setup` | `TwoFactorTotpController@setup`<br>`TotpService` | `auth:api` | Génération d'un secret Base32 sécurisé (160 bits) et URI `otpauth://totp/...` pour applications authentificatrices. |
| **ST** | `POST /api/settings/2fa/totp/verify` | `TwoFactorTotpController@verify`<br>`TotpService` | `auth:api` | Validation d'un code 6 chiffres, chiffrement du secret au repos, activation du 2FA et émission des 8 codes de secours. |
| **ST** | `POST /api/settings/2fa/disable` | `TwoFactorTotpController@disable` | `auth:api` | Désactivation sécurisée du 2FA avec confirmation par mot de passe ou code 2FA. |
| **ST** | `GET /api/settings/2fa/backup-codes` | `BackupCodeController@index`<br>`BackupCodeService` | `auth:api` | Consultation de l'état et du nombre de codes de secours restants (sans exposition des codes). |
| **ST** | `POST /api/settings/2fa/backup-codes/regenerate` | `BackupCodeController@regenerate`<br>`BackupCodeService` | `auth:api` | Régénération de 8 codes à usage unique invalidant immédiatement tous les anciens codes. |
| **AU** | `POST /api/auth/signin/second-factor` | `SecondFactorController`<br>`BackupCodeService`<br>`TotpService` | Public | Validation du 2ème facteur à la connexion (supporte SMS, Email, TOTP authenticator et codes de secours). |

---

## 3. Détail des Implémentations

### 3.1. Phase 1 — Contrôle de version minimale (SP)
- **Configuration** : `config/system.php` définit pour `android` et `ios` :
  - `min_supported_version` : Seuil en-dessous duquel l'application est bloquante (`update_required = true`).
  - `recommended_version` : Seuil suggéré.
  - `critical_update_threshold` : Seuil pour forcer une mise à jour d'urgence sans contournement.
  - `store_urls` : Liens Google Play Store et Apple App Store.
- **Logique** : `AppVersionService` effectue la comparaison avec `version_compare()`, tolère les préfixes 'v' et formats semver, et fournit un fallback gracieux si les paramètres sont absents ou erronés.

### 3.2. Phase 2 — OAuth Social Google & Apple (AU)
- **Base de données** : Table `oauth_accounts` avec contrainte unique composite `(provider, provider_user_id)`.
- **Vérificateurs JWT natifs** :
  - `JwtVerifierHelper` : Décodeur et validateur cryptographique pur PHP basé sur `openssl_verify()` avec algorithme RS256, conversion de clés publiques JWK en clés PEM X.509 et gestion timing-safe.
  - `GoogleTokenVerifier` : Télécharge et met en cache les JWKS officiels Google (`https://www.googleapis.com/oauth2/v3/certs`) avec validation de l'audience (`aud`), de l'émetteur (`accounts.google.com`) et de l'expiration.
  - `AppleTokenVerifier` : Télécharge et met en cache les JWKS officiels Apple (`https://appleid.apple.com/auth/keys`) avec validation de l'émetteur (`https://appleid.apple.com`) et de l'audience.
- **Politique Anti Auto-Merge** :
  - Si un compte BANGO existe déjà avec l'adresse email mais sans liaison OAuth préalable, le système refuse la fusion silencieuse et renvoie HTTP 409 Conflict (`kind: account_exists_unlinked`) pour empêcher l'usurpation de compte.
  - Le rattachement explicite requiert `link_existing = true` avec le mot de passe du compte (`existing_password`).
- **Support 2FA** : Si l'utilisateur connecté via OAuth a le 2FA actif, un challenge temporaire est émis et le token d'accès n'est délivré qu'après validation du 2nd facteur (`SecondFactorController`).

### 3.3. Phase 3 — Suggestions de Pseudonymes (AU)
- **Service** : `HandleSuggestionService`.
- **Règles de validation** :
  - Longueur entre 3 et 20 caractères.
  - Caractères autorisés : lettres minuscules, chiffres et points (`^[a-z0-9.]+$`).
  - Pas de point en début ou fin, pas de points consécutifs (`..`).
  - Exclusion stricte des termes réservés (`admin`, `administrator`, `root`, `bango`, `support`, `moderation`, `official`, etc.).
- **Génération** :
  - Décomposition des prénoms, noms et villes.
  - Normalisation des accents (Translittération UTF-8 : `é` -> `e`, `ç` -> `c`, etc.).
  - Test d'unicité en base de données (`User::where('handle', $candidate)->exists()`).
  - Retourne systématiquement 4 à 5 suggestions élégantes et vérifiées libres.

### 3.4. Phase 4 — Rapprochement Chiffré du Carnet d'Adresses (AU)
- **Service** : `ContactMatchingService`.
- **Sécurité & Confidentialité** :
  - Protection DoS/Scraping : Limite stricte de 500 entrées par requête (HTTP 422 au-delà).
  - Rate limiting strict : 10 requêtes par minute par utilisateur (HTTP 429 au-delà).
  - Le serveur ne stocke et ne renvoie **jamais** les numéros ni les empreintes reçues.
  - Ne suggère jamais l'utilisateur appelant à lui-même.
- **Règles d'exclusion** :
  - Utilisateurs inactifs (`status != 'active'`), soft-deleted (`deleted_at IS NOT NULL`) ou suspendus (`suspended_until > now()`).
  - Blocages mutuels (`user_blocks`) : Si l'un des deux utilisateurs a bloqué l'autre, exclusion totale.
- **Algorithmes supportés** :
  - Numéros E.164 bruts (`+221...`, `+237...`, etc.).
  - Empreintes SHA-256 (du numéro complet, des 8 derniers chiffres ou avec sel d'application).
  - Empreinte polynomiale 32-bit JS pour compatibilité native directe avec l'application Expo (`computeLegacyJsHash`).

### 3.5. Phase 5 — Double Authentification TOTP RFC 6238 (ST)
- **Spécification** : RFC 6238 (Time-based One-Time Password) sur base RFC 4226 (HOTP).
- **Paramètres** : Fenêtre temporelle de 30 secondes, code 6 chiffres, HMAC-SHA1, tolérance de ±1 pas (±30s pour dérive horaire).
- **Protection Anti-Rejeu** :
  - Verrouillage en cache (`totp_used:{userId}:{step}`) pendant 90 secondes. Un code déjà consommé dans la tranche temporelle active est refusé.
- **Chiffrement au repos** :
  - Le secret Base32 (160 bits) est systématiquement chiffré dans la colonne `two_factor_secret` via `Crypt::encryptString()`. Aucun secret en clair n'est stocké en base ni consigné dans les logs.
- **Activation** : Requiert la présentation d'un code valide généré depuis le secret en attente.

### 3.6. Phase 6 — Codes de Secours à Usage Unique (ST)
- **Spécification** : 8 codes d'accès uniques de 8 caractères au format `XXXX-XXXX`.
- **Alphabet ergonomique** : `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (32 symboles, sans '0', 'O', '1', 'I' pour éviter les confusions de saisie).
- **Chiffrement / Hachage** :
  - Seul le hash SHA-256 de chaque code est persisté dans `users.backup_codes`.
  - Les codes en clair ne sont affichés qu'une seule fois au client lors de la génération.
- **Consommation atomique** :
  - Lors de la validation à la connexion (`SecondFactorController`), la recherche s'effectue dans une transaction SQL avec verrouillage pessimiste (`lockForUpdate`).
  - Le code validé est supprimé immédiatement du tableau `backup_codes`, décrémentant le compteur et interdisant tout réemploi.

---

## 4. Raccordement Mobile (React Native / Expo)

Dans l'application `Bango/` :
- `Bango/services/api/auth.ts` : Ajout de la méthode `providerAuth(payload)` pour déclencher `POST /api/auth/provider`.
- `Bango/services/api/system.ts` : Ajout de la méthode `checkAppVersion(platform, version, buildNumber)` pour `GET /api/system/version`.
- `Bango/services/api/settings.ts` :
  - `totpSetup()`, `totpVerify()`, `twoFactorDisable()`.
  - `backupCodes()`, `regenerateBackupCodes()`.
- `Bango/services/api/types.ts` : Définition des types TypeScript stricts `TotpSetupResponse`, `TotpVerifyResponse`, `BackupCodesResponse`.

---

## 5. Stratégie de Tests & Couverture

6 suites de tests complètes écrites avec Pest PHP couvrent l'intégralité des flux nominaux, erreurs de validation, scénarios d'attaque et cas limites :

1. `tests/Feature/System/AppVersionTest.php` : 9 tests (comparaison semver, blocage de version obsolète, mise à jour critique, formats invalides).
2. `tests/Feature/Auth/SocialAuthTest.php` : 12 tests (inscription Google/Apple, connexion compte existant, détection de conflit email, refus de fusion sans mot de passe, challenge 2FA, signature RS256 invalide, token expiré).
3. `tests/Feature/Profile/HandleSuggestionTest.php` : 6 tests (génération 4-5 suggestions, translittération accents, exclusion des pseudos pris, exclusion des mots réservés, fallback noms courts/vides).
4. `tests/Feature/Profile/ContactMatchingTest.php` : 8 tests (recherche par numéro E.164, hashes SHA-256 et hash JS, anti self-matching, exclusion bloqués/suspendus/supprimés, limitation 500 entrées, rate-limit 10 req/min).
5. `tests/Feature/Settings/TwoFactorTotpTest.php` : 7 tests (génération URI/secret, vérification code, chiffrement au repos, anti-rejeu, flux de connexion second facteur, désactivation sécurisée).
6. `tests/Feature/Settings/BackupCodesTest.php` : 7 tests (génération 8 codes format `XXXX-XXXX`, hachage en base, consommation atomique à usage unique, acceptation sans tiret, régénération invalidant les anciens codes).

**Total tests Étape 7 : 49 tests, 362 assertions — 100% Passing.**
