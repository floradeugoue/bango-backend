# GUIDE DE DÉPLOIEMENT ET CONFIGURATION — MODULE CR1 SQUARE

## 1. Prérequis Système
- **PHP** : >= 8.4 avec extensions `pdo_pgsql`, `mbstring`, `fileinfo`, `gd` (pour `getimagesize`).
- **PostgreSQL** : >= 16.
- **Stockage** : Lien symbolique de stockage configuré (`php artisan storage:link`).
- **Passport** : Clés OAuth2 générées (`php artisan passport:keys` ou `php artisan passport:install`).

---

## 2. Variables d'Environnement Clés (`.env`)
```ini
# Base de données PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bango_db
DB_USERNAME=bango_user
DB_PASSWORD=secret

# Système de fichiers
FILESYSTEM_DISK=public
APP_URL=https://api.bango.cm
```

---

## 3. Commandes de Déploiement

### 3.1. Exécution des migrations
```bash
php artisan migrate --force
```

### 3.2. Optimisation du cache de configuration et de routage
```bash
php artisan config:cache
php artisan route:cache
```

### 3.3. Vérification des routes enregistrées
```bash
php artisan route:list --path=v1
```

---

## 4. Procédure de Rollback
En cas d'incident, la migration Square peut être annulée de manière isolée sans affecter les autres tables :
```bash
php artisan migrate:rollback --step=1
```
Les tables `post_hashtag`, `hashtags`, `post_tags`, `post_media` et `posts` seront supprimées dans l'ordre inverse des contraintes de clés étrangères.
