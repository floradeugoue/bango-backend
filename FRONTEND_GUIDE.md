# BANGO - Guide d'Intégration Frontend

Bienvenue sur le projet backend de BANGO ! Ce document a été conçu pour faciliter au maximum la vie de l'équipe Frontend.

## 🔗 Documentation Interactive de l'API (Swagger / OpenAPI)

Toute la documentation de l'API a été générée automatiquement avec Scribe. Elle contient :
- Tous les Endpoints disponibles
- Le format exact des JSON attendus (Body Parameters)
- Les exemples de réponses (Succès et Erreurs)

Pour y accéder :
1. Démarrez le backend : `php artisan serve`
2. Ouvrez votre navigateur sur : **[http://localhost:8000/docs](http://localhost:8000/docs)**

## 🛡️ Postman Collection

Une collection Postman est également générée automatiquement. Vous pouvez l'importer dans votre client HTTP préféré (Postman, Insomnia) depuis ce fichier :
`storage/app/private/scribe/collection.json`

## 🔑 Authentification (Passport)

Le système utilise **Laravel Passport**.
1. **Inscription** : `/api/auth/signup`
2. **Connexion** : `/api/auth/signin`
Les deux requêtes retournent un objet contenant un `token` Bearer et les informations `user`.

**Exemple de retour :**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbG...",
  "user": {
    "id": 1,
    "email": "test@example.com",
    "handle": "pseudo",
    ...
  }
}
```

Pour les routes protégées (tout sauf auth), ajoutez ce Header HTTP à vos requêtes :
`Authorization: Bearer VOTRE_TOKEN`

## ⚠️ Gestion des Erreurs (Format BANGO)

Le backend respecte **strictement le contrat d'erreurs du Frontend**. 
Au lieu du format par défaut de Laravel, les erreurs 422, 409 et 429 renvoient un format custom.

Exemple pour un **Rate Limiting** (Route signin bloquée) :
```json
{
  "kind": "locked",
  "minutes": 15,
  "until": "2026-10-07T20:45:00Z"
}
```

Exemple pour un **Mot de passe faible** (Signup) :
```json
{
  "kind": "weak-password",
  "failed": ["min-length", "needs-digit"]
}
```

Exemple pour un **Email déjà pris** (Signup) :
```json
{
  "kind": "email-taken",
  "existingHandle": "taken_user",
  "displayName": "Taken User",
  "avatarUrl": "https://..."
}
```

## 🚀 Étapes de l'Onboarding

1. `/api/profile/handle-check` (POST) : Vérifier un pseudo
2. `/api/profile/identity` (PUT) : Maj Nom + Pseudo
3. `/api/profile/birthdate` (PUT) : Maj Date (Refus si < 18 ans)
4. `/api/profile/gender` (PUT) : Maj Genre
5. `/api/profile/location` (PUT) : Maj Pays/Ville
6. `/api/profile/interests` (PUT) : Maj Intérêts (Tableau de chaînes)
7. `/api/profile/avatar` (PUT) : Uploader l'image
8. `/api/onboarding/complete` (POST) : Sauvegarder la progression `{"completed": ["identity", "gender"]}`

## 📱 Validation OTP

Le système OTP vérifie et valide les numéros de téléphone via SMS :
- `/api/otp/request` (POST) : Demander un code
- `/api/otp/verify` (POST) : Valider un code (valide le numéro dans la DB)
- `/api/otp/resend` (POST) : Renvoyer un code (respecte un timer de 30 secondes)

## 🏠 Logement (Housing)

Pour la recherche de logement, les critères sont sauvegardés sur ce point d'accès unifié :
- `/api/housing/search` (GET/PUT)

---
*Happy Coding !* 🚀
