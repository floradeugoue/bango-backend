# BANGO — Rapport des Tests Exécutés Module PR (Profil)

Ce document consigne les tests fonctionnels rédigés et réellement exécutés sur la suite de tests Pest / PHPUnit pour le module **PR (Profil)** et les tests de non-régression associés.

---

## 🧪 1. Suite de Tests Dédiée au Module PR (`ProfileModuleTest.php`)

Commande exécutée :
```bash
vendor/bin/pest tests/Feature/ProfileModuleTest.php --compact
```

### Résultats d'exécution
- **Nombre de tests** : 13 / 13 réussis (100 %)
- **Nombre d'assertions** : 95
- **Temps d'exécution** : 5.4s
- **Statut** : ✅ **PASSED**

### Scénarios Couverts et Vérifiés

| Scénario | Description | Statut |
|---|---|---|
| **Authentification Passport** | Authentification de bout en bout sur `GET /api/profile` et `PUT /api/profile/identity` avec de véritables tokens Passport générés (`Authorization: Bearer <token>`). Vérification du rejet `401` sans token et après révocation du token Passport. | ✅ Validé |
| **Profil Personnel** | `GET /api/profile` renvoie `UserResource` avec les compteurs (`followers: 0, following: 0, posts: 0`), le statut `isPrivate`, `badge`, et masque les données sensibles | ✅ Validé |
| **Profil Public** | `GET /api/profile/public/{identifier}` résout correctement par identifiant numérique et par `@handle`, avec vérification du statut `verified` et `isFollowing` | ✅ Validé |
| **Profil Inexistant** | `GET /api/profile/public/inconnu` renvoie bien une erreur HTTP `404 Not Found` avec `{ "kind": "not-found" }` | ✅ Validé |
| **Mise à jour Identité** | `PUT /api/profile/identity` met à jour simultanément le pseudonyme, le nom affiché et la présentation (`bio`) | ✅ Validé |
| **Unicité Pseudonyme** | `PUT /api/profile/identity` rejette avec HTTP `422` une tentative d'utiliser un pseudo déjà pris par un autre utilisateur | ✅ Validé |
| **Vérification Pseudo** | `POST /api/profile/handle-check` renvoie `{ state: "free" }`, `{ state: "taken" }`, ou `{ state: "reserved" }` pour les pseudos système (`admin`, `bango`) | ✅ Validé |
| **Abonnement Public** | `POST /api/profile/{id}/follow` sur un compte public crée un abonnement direct (`status: "followed"`). Empêche l'auto-suivi (`422`) | ✅ Validé |
| **Désabonnement & Toggle** | `DELETE /api/profile/{id}/follow` et `POST /api/profile/{id}/toggle-follow` basculent l'état entre suivi et non-suivi | ✅ Validé |
| **Compte Privé & Demandes** | Suivre un compte privé génère une demande en attente (`status: "requested"`), visible dans `GET /api/profile/follow-requests`, puis acceptée avec `POST /api/profile/follow-requests/{id}/accept` | ✅ Validé |
| **Rejet de Demande** | `POST /api/profile/follow-requests/{id}/reject` supprime la demande discrètement sans créer d'abonnement | ✅ Validé |
| **Passage Privé $\rightarrow$ Public** | Basculer `privateAccount: false` sur un compte privé convertit automatiquement toutes les demandes en attente en abonnés effectifs | ✅ Validé |
| **Blocage d'Utilisateur** | `POST /api/profile/{id}/block` coupe immédiatement les abonnements dans les deux sens et supprime les demandes en attente. `DELETE .../block` débloque | ✅ Validé |
| **Résolution QR Code** | `POST /api/qr/resolve` avec `bango://u/{handle}` résout l'utilisateur et renvoie `profileLink`, ou `404` si inconnu | ✅ Validé |
| **Remplacement d'Avatar** | `PUT /api/profile/avatar` téléverse une nouvelle image, supprime l'ancienne du stockage et met à jour l'URL publique | ✅ Validé |

---

## 🔒 2. Tests de Non-Régression AUTH et Sécurité

Commande exécutée :
```bash
vendor/bin/pest tests/Feature/ProfileModuleTest.php tests/Feature/UserSecurityAndProfileTest.php --compact
```

### Résultats d'exécution
- **Nombre de tests combinés** : 21 / 21 réussis (100 %)
- **Nombre d'assertions** : 139
- **Temps d'exécution** : 2.9s
- **Statut** : ✅ **PASSED**

### Contrôles Validés
1. **Masquage strict `UserResource`** : `password`, `two_factor_secret`, `backup_codes`, `pending_email_code`, `locked_until` restent protégés.
2. **Double facteur (2FA)** : Validation des challenges 2FA et codes de secours fonctionnelle.
3. **Changement d'email** : Envoi de code via Resend, vérification et expiration vérifiées.
4. **Gestion des sessions** : Révocation ciblée et bouton d'urgence sécurisés.
5. **Centres d'intérêt** : Synchronisation du pivot `interest_user` et seeder opérationnels.
