# BANGO — Architecture du Module PR (Profil)

Ce document décrit la structure logicielle, les responsabilités et les frontières architecturales du module **PR (Profil)** au sein du backend Laravel.

---

## 🏛️ 1. Principes d'Architecture & Séparation des Responsabilités

Le module PR s'insère dans l'architecture modulaire de BANGO en respectant scrupuleusement les principes suivants :

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Profile/
│   │       ├── ProfileController.php          # Consultation et mise à jour des identités
│   │       ├── FollowController.php           # Abonnements et bascule de suivi
│   │       ├── FollowRequestController.php    # Demandes d'abonnement pour comptes privés
│   │       ├── BlockController.php            # Blocage et déblocage d'utilisateurs
│   │       ├── PrivacyController.php          # Réglages de visibilité et confidentialité
│   │       ├── QrController.php               # Résolution des QR codes de profil
│   │       ├── AvatarController.php           # Téléversement et gestion d'avatar
│   │       ├── EmailController.php            # Procédure sécurisée de changement d'email
│   │       ├── HandleCheckController.php      # Disponibilité et suggestions de pseudo
│   │       └── SecurityController.php        # Sessions et sécurisation d'urgence
│   └── Resources/
│       ├── UserResource.php                   # Profil unifié et sécurisé de l'utilisateur
│       ├── PublicProfileResource.php          # Fiche publique d'un compte externe
│       └── FollowUserResource.php             # Élément d'une liste sociale (abonnés/abonnements)
├── Models/
│   ├── User.php                               # Modèle utilisateur enrichi des relations PR
│   ├── Follow.php                             # Modèle pivot d'abonnement
│   ├── FollowRequest.php                      # Demandes d'abonnement en attente
│   └── UserBlock.php                          # Modèle de blocage réciproque
└── Services/
    └── Otp/                                   # Services de validation par code
```

---

## 🔍 2. Rôles et Responsabilités Détaillés

### Contrôleurs Dédiés (`app/Http/Controllers/Profile/`)
- **`ProfileController`** : Point d'entrée pour la consultation du profil authentifié (`show`), l'affichage du profil public d'un tiers (`showPublic`), et la mise à jour des champs d'identité (`updateIdentity`), date de naissance, genre et centres d'intérêt.
- **`FollowController`** : Gère la logique d'abonnement. Décide si l'action aboutit à un abonnement immédiat (`status: followed`) ou à une demande (`status: requested`) selon la confidentialité du compte cible (`is_private`).
- **`FollowRequestController`** : Responsable du flux des comptes privés : listing des demandes reçues/envoyées, acceptation unitaire ou groupée (`acceptAll`), et rejet silencieux.
- **`BlockController`** : Garantit l'isolation de sécurité entre deux comptes. Rompt immédiatement toute relation sociale existante ou en cours dès qu'un blocage intervient.
- **`PrivacyController`** : Gère le basculement entre compte public et privé. Applique la règle métier critique : repasser un compte privé en public valide automatiquement toutes les demandes en attente.
- **`QrController`** : Décode les liens profonds (`bango://u/<handle>`) et permet la navigation fluide vers le bon profil utilisateur.

### Ressources API (`app/Http/Resources/`)
- **Isolation des données sensibles** : Aucune donnée interne (`two_factor_secret`, `backup_codes`, `password`, `pending_email_code`, etc.) n'est exposée.
- **Format universel** :
  - `UserResource` pour l'utilisateur connecté.
  - `PublicProfileResource` pour l'affichage tiers (`OtherAccount`), incluant les badges métier, le drapeau de vérification et les relations mutuelles (`isFollowing`, `isBlocked`, `canViewContent`).
  - `FollowUserResource` optimisé pour les listes volumineuses d'abonnés/abonnements.

---

## 🛡️ 3. Frontières Modulaires et Non-Ingérence

Le module PR établit des frontières strictes avec les autres domaines fonctionnels de BANGO :

```
┌────────────────────────────────────────────────────────┐
│                      MODULE AUTH                       │
│     (Tokens Passport, Signup, Signin, OTP, 2FA)        │
└───────────────────────────┬────────────────────────────┘
                            │ (Identité authentifiée)
                            ▼
┌────────────────────────────────────────────────────────┐
│                       MODULE PR                        │
│   (Profil, Bio, Avatar, Abonnements, Confidentialité)  │
└──────────────┬──────────────────────────┬──────────────┘
               │                          │
 (Badges & droits)                        │ (Recherche géo)
               ▼                          ▼
┌─────────────────────────────┐ ┌────────────────────────┐
│     MODULE SUB (Futur)      │ │       MODULE GEO       │
│  (Abonnements, KYC, Badges) │ │  (Pays, Villes, Rues)  │
└──────────────┬──────────────┘ └────────────────────────┘
               │
   (Paiements Mobile Money)
               ▼
┌─────────────────────────────┐
│     MODULE PAY (Futur)      │
│  (Transactions, Webhooks)   │
└─────────────────────────────┘
```

1. **Avec AUTH** : PR ne gère pas l'émission de jetons ni la validation des mots de passe. Il consomme le modèle `User` authentifié via `auth:api`.
2. **Avec GEO** : PR s'appuie sur le référentiel géographique existant (`countries`, `cities`, `neighbourhoods`) pour normaliser les adresses.
3. **Avec IM (Immo) et SQ (Square)** : PR calcule uniquement les compteurs statistiques (`posts`, `followers`, `following`). Les listes détaillées d'annonces ou de posts restent la propriété de leurs modules respectifs.
4. **Avec SUB & PAY** : PR expose un champ `badge` et `verification_lapsed` contrôlé par le backend, prêt à être alimenté de manière étanche par le futur module SUB.
