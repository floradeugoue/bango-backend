# RAPPORT D'AUDIT COMPLET DU BACKEND BANGO
## Services Externes, Estimation des Coûts & Guide de Déploiement en Production

---

## 1. CARTOGRAPHIE EXHAUSTIVE DES SERVICES EXTERNES

Le backend BANGO (Laravel 12 / PHP 8.4) s'appuie sur une architecture distribuée intégrant plusieurs services tiers spécialisés. Voici l'inventaire complet issu de l'audit du code source :

| Service Tiers | Rôle & Usage dans BANGO | Identifiants & Configuration Requise | Obligatoire en Prod ? |
| :--- | :--- | :--- | :---: |
| **Monetbil** | Passerelle de paiement Mobile Money (MTN MoMo, Orange Money) pour abonnements, visites, boosts et crédits. Traitement des webhooks IPN avec signature MD5. | `MONETBIL_SERVICE_KEY`<br>`MONETBIL_SERVICE_SECRET`<br>`MONETBIL_API_URL`<br>`MONETBIL_NOTIFY_URL` | **OUI** (Cœur financier) |
| **Zomloa SMS** | Passerelle SMS panafricaine (Cameroun, Côte d'Ivoire, Sénégal...) pour l'envoi des codes OTP, validation de téléphone et 2FA SMS. | `ZOMLOA_API_KEY`<br>`ZOMLOA_BASE_URL`<br>`ZOMLOA_SENDER_ID` | **OUI** (Onboarding & Sécurité) |
| **Resend** | Service d'envoi d'emails transactionnels (OTP email, réinitialisation de mot de passe, invitations administrateur, reçus de paiement PDF). | `RESEND_API_KEY`<br>`MAIL_MAILER=resend`<br>`MAIL_FROM_ADDRESS` | **OUI** (Délivrabilité certifiée) |
| **Cloudflare R2** | Stockage d'objets cloud compatible S3 sans frais de bande passante sortante (Photos Square, Médias d'annonces IM, Pièces KYC, Sons, Stories, Exports RGPD). | `CLOUDFLARE_R2_ACCESS_KEY_ID`<br>`CLOUDFLARE_R2_SECRET_ACCESS_KEY`<br>`CLOUDFLARE_R2_BUCKET`<br>`CLOUDFLARE_R2_ENDPOINT`<br>`CLOUDFLARE_R2_PUBLIC_URL` | **OUI** (Stockage principal) |
| **Cloudflare Stream** | Encodage HLS adaptatif multi-débits (1080p, 720p, 480p), génération de vignettes, diffusion vidéo optimisée et gestion des webhooks d'encodage. | `CLOUDFLARE_STREAM_ACCOUNT_ID`<br>`CLOUDFLARE_STREAM_API_TOKEN`<br>`CLOUDFLARE_STREAM_CUSTOMER_SUBDOMAIN`<br>`CLOUDFLARE_STREAM_WEBHOOK_SECRET` | **OUI** (Reels & Vidéos) |
| **Expo Push Notifications** | Acheminement des notifications push mobiles vers les applications iOS et Android par lots de 100 via les tokens `DeviceToken`. | Passerelle standard Expo (`exp.host`) + Clés APNs Apple / FCM Google dans le projet Expo. | **OUI** (Rétention & Alertes) |
| **Google Identity (OAuth2)** | Connexion sociale "Continuer avec Google" sur Web, Android et iOS. Vérification cryptographique des jetons JWT via l'API Google Certs. | `GOOGLE_CLIENT_ID`<br>`GOOGLE_CLIENT_ID_WEB`<br>`GOOGLE_CLIENT_ID_ANDROID`<br>`GOOGLE_CLIENT_ID_IOS` | **OUI** (Expérience utilisateur) |
| **Apple Sign In** | Connexion sociale "Se connecter avec Apple" obligatoire pour l'App Store iOS. Vérification des jetons JWT via les clés publiques Apple ID. | `APPLE_CLIENT_ID`<br>`APPLE_BUNDLE_ID`<br>`APPLE_TEAM_ID`<br>`APPLE_KEY_ID`<br>`APPLE_PRIVATE_KEY` (.p8) | **OUI** (Validation App Store) |
| **Google Cloud Translation & DeepL** | Traduction automatique bilingue (FR ↔ EN) des posts, annonces et commentaires, avec support d'heuristique locale Wolof et cache persistant SHA-256. | `TRANSLATION_DRIVER=google`<br>`GOOGLE_TRANSLATE_API_KEY`<br>`DEEPL_API_KEY` | Recommandé (Mode local disponible) |
| **Smile ID / AWS Rekognition** | Reconnaissance faciale, vérification de pièces d'identité et vivacité 3 poses pour l'homologation des bailleurs, agents et créateurs (KYC). | `services.smile_id.*` ou `services.rekognition.*` | Optionnel (Moteur heuristique local actif par défaut) |
| **IP-API (Géolocalisation IP)** | Détection de l'emplacement (Pays / Ville) lors de la création de sessions administrateur et utilisateurs pour la détection de fraudes. | `http://ip-api.com/json` (Gratuit jusqu'à 45 req/min) | Inclus (Sans frais) |
| **WebSockets (Laravel Reverb)** | Moteur temps réel pour la messagerie instantanée, la frappe en direct et l'acquittement de lecture (`ShouldBroadcastNow`). | `REVERB_APP_ID`<br>`REVERB_APP_KEY`<br>`REVERB_APP_SECRET` | **OUI** (Expérience Chat) |
| **Monitoring & Alertes (Sentry / Slack)** | Suivi en temps réel des exceptions 5xx (`incident_id`), métriques de performance et alertes critiques sur canal Slack dédié. | `SLACK_BOT_USER_OAUTH_TOKEN`<br>`SENTRY_LARAVEL_DSN` | Fortement Recommandé |

---

## 2. AUDIT FINANCIER : TOUT CE QU'IL FAUT PAYER

Voici la synthèse des coûts récurrents, des abonnements obligatoires et des frais à la consommation nécessaires à l'exploitation de la plateforme.

### A. Frais d'ouverture & Licences Stores (Indispensables)

| Poste de Dépense | Type de Frais | Montant Estimé | Fréquence | Justification & Obligation |
| :--- | :---: | :---: | :---: | :--- |
| **Apple Developer Program** | Fixe | **99 $ / an** (~60 000 FCFA) | Annuel | Obligatoire pour publier l'application sur l'App Store, activer Apple Sign In et envoyer les push APNs. |
| **Google Play Console** | Fixe | **25 $** (~15 500 FCFA) | Paiement unique | Obligatoire pour créer le compte développeur Google et publier l'application Android. |
| **Nom de domaine (ex: `bango.app` / `bango.cm`)** | Fixe | **15 $ à 45 $ / an** (~10 000 - 30 000 FCFA) | Annuel | Indispensable pour l'API publique (`api.bango.app`), le back-office (`admin.bango.app`) et les certificats SSL. |
| **Certificats SSL / TLS** | Fixe | **0 $ (GRATUIT)** | Permanent | Fournis gratuitement via Let's Encrypt / Certbot ou via Cloudflare SSL. |

### B. Hébergement, Base de Données & Infrastructure Cloud

| Composant Infra | Dimensionnement Recommandé | Fournisseurs Recommandés | Coût Mensuel Estimé |
| :--- | :--- | :--- | :---: |
| **Serveur d'Application (API & Workers)** | 2 à 4 vCPU, 4 à 8 Go RAM, SSD NVMe | Hetzner Cloud / OVH / DigitalOcean / AWS EC2 | 15 $ à 40 $ / mois |
| **Base de Données PostgreSQL** | PostgreSQL 16 managé avec réplication et sauvegardes quotidiennes (2 vCPU, 4 Go RAM) | Scaleway / DigitalOcean / Hetzner / AWS RDS | 20 $ à 55 $ / mois |
| **Cache & Files d'attente (Redis)** | Redis 7+ (1 à 2 Go RAM, persistance disque) | Instance mutualisée ou managée (Upstash / Aiven) | 10 $ à 20 $ / mois |
| **Total Infrastructure Mensuelle de Départ** | — | — | **45 $ à 115 $ / mois** (~28 000 à 72 000 FCFA) |

### C. Services Externes & Frais à l'Usage (Pay-as-you-go)

| Service | Modèle Tarifaire & Quota Gratuit | Coût à l'Usage au-delà du Quota | Budget Initial Estimé / Mois |
| :--- | :--- | :--- | :---: |
| **Zomloa SMS (OTP)** | Prépayé. Aucun forfait fixe mensuel. | ~15 à 22 FCFA par SMS délivré au Cameroun / Afrique de l'Ouest. | **20 $ à 50 $ / mois** (~12 000 - 30 000 FCFA) pour 1 000 à 2 500 OTPs. |
| **Resend (Emails)** | **3 000 emails/mois GRATUITS** (100/jour). | Formule Pro à **20 $ / mois** pour 50 000 emails/mois avec domaine vérifié. | **0 $** (au début) puis **20 $ / mois**. |
| **Cloudflare R2 (Médias)** | **10 Go stockés GRATUITS/mois**, opérations Class A (1M) et Class B (10M) gratuites. | 0,015 $ / Go supplémentaire. **0 $ de frais de bande passante sortante (Egress gratuit).** | **5 $ à 15 $ / mois** (très économique). |
| **Cloudflare Stream (Vidéos)** | Aucun niveau gratuit illimité. | **5 $ pour 1 000 minutes stockées** / mois + **1 $ pour 1 000 minutes visionnées** / mois. | **15 $ à 30 $ / mois** pour démarrer le catalogue de Reels. |
| **Monetbil (Paiements)** | Pas d'abonnement mensuel. | 2,0 % à 3,0 % prélevé sur les encaissements (déjà répercuté au client via `PAYMENT_MONETBIL_FEE_PERCENTAGE=2.0`). | **0 $ de frais fixes** (autofinancé par les transactions). |
| **Google Cloud Translate** | **500 000 caractères GRATUITS / mois**. | 20 $ par million de caractères (atténué par le cache persistant SHA-256 en base). | **0 $ à 10 $ / mois**. |
| **Expo Push Notifications** | **100 % GRATUIT** (géré par le cloud Expo). | Aucune facturation pour l'envoi de push. | **0 $ / mois**. |
| **Sentry (Monitoring)** | **Gratuit jusqu'à 5 000 erreurs / mois**. | Formule Team à 26 $ / mois si le volume explose. | **0 $ / mois**. |

### 💰 Récapitulatif Budgétaire Global pour le Lancement

| Période / Phase | Postes Inclus | Montant Estimé (EUR / USD) | Équivalent FCFA |
| :--- | :--- | :---: | :---: |
| **Coûts Uniques de Départ** | Comptes Apple (99 $) + Google Play (25 $) + Domaine (20 $) + Pack SMS Initial (50 $) | **~195 $** | **~125 000 FCFA** |
| **Coût d'Exploitation Mensuel (Phase Lancement : 0 à 10 000 utilisateurs)** | Serveur + Postgres + Redis + R2 + Stream + SMS + Resend | **~85 $ à 175 $ / mois** | **~55 000 à 110 000 FCFA / mois** |

---

## 3. CHECK-LIST COMPLÈTE DU DÉPLOIEMENT EN PRODUCTION

### Étape 1 : Pré-requis Système du Serveur de Production

1. **Système d'exploitation :** Ubuntu 22.04 LTS ou Debian 12.
2. **PHP 8.4-FPM** avec les extensions compilées :
   - `php8.4-cli`, `php8.4-fpm`, `php8.4-pgsql`, `php8.4-redis`, `php8.4-mbstring`, `php8.4-xml`, `php8.4-curl`, `php8.4-gd` (ou `imagick`), `php8.4-intl`, `php8.4-bcmath`, `php8.4-zip`.
3. **PostgreSQL 15+** configuré avec l'encodage `UTF-8`.
4. **Redis 7+** avec mot de passe et écoute locale (`127.0.0.1:6379`).
5. **Serveur Web Nginx ou Caddy** configuré avec :
   - Reverse proxy PHP-FPM (`fastcgi_pass unix:/run/php/php8.4-fpm.sock;`).
   - Taille maximale de téléversement : `client_max_body_size 120M;` (requis pour les vidéos de Reels et documents KYC).
   - En-têtes de sécurité HTTP (HSTS, X-Frame-Options, X-Content-Type-Options, Content-Security-Policy).
6. **FFmpeg** installé sur le serveur (`apt install ffmpeg`) comme solution de secours pour le transcodage et l'extraction de miniatures locales.
7. **Supervisor** (`apt install supervisor`) pour maintenir en vie les démons Laravel (Workers & WebSocket).

---

### Étape 2 : Variables d'Environnement `.env` Critiques pour la Production

```ini
APP_NAME=BANGO
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:VOTRE_CLE_DE_PRODUCTION_GENIREE
APP_URL=https://api.bango.app

# Base de données PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bango_prod
DB_USERNAME=bango_db_user
DB_PASSWORD=MOT_DE_PASSE_TRES_SECURISÉ

# Cache, Sessions & Files d'attente (Redis obligatoire en prod)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=MOT_DE_PASSE_REDIS
REDIS_PORT=6379

# Envois d'Emails Transactionnels via Resend
MAIL_MAILER=resend
RESEND_API_KEY=re_live_xxxxxxxxxxxxxxxxxxxxxxxx
MAIL_FROM_ADDRESS=notifications@bango.app
MAIL_FROM_NAME="BANGO"

# Passerelle SMS Zomloa
ZOMLOA_API_KEY=zs_live_xxxxxxxxxxxxxxxxxxxxxxxx
ZOMLOA_BASE_URL=https://api.sms.zomloa.app
ZOMLOA_SENDER_ID=BANGO

# Stockage Cloudflare R2
MEDIA_DISK=r2
FILESYSTEM_DISK=r2
CLOUDFLARE_R2_ACCESS_KEY_ID=xxxxxxxxxxxxxxxxxxxxxxxx
CLOUDFLARE_R2_SECRET_ACCESS_KEY=xxxxxxxxxxxxxxxxxxxxxxxx
CLOUDFLARE_R2_BUCKET=bango-production-media
CLOUDFLARE_R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
CLOUDFLARE_R2_PUBLIC_URL=https://media.bango.app

# Diffusion Vidéo Cloudflare Stream
STREAM_PROVIDER=cloudflare_stream
CLOUDFLARE_STREAM_ACCOUNT_ID=xxxxxxxxxxxxxxxxxxxxxxxx
CLOUDFLARE_STREAM_API_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxx
CLOUDFLARE_STREAM_CUSTOMER_SUBDOMAIN=customer-xxxx.cloudflarestream.com
CLOUDFLARE_STREAM_WEBHOOK_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx

# Paiements Monetbil
PAYMENT_ACTIVE_GATEWAY=monetbil
PAYMENT_DEFAULT_CURRENCY=XAF
PAYMENT_TIMEOUT_SECONDS=120
PAYMENT_MONETBIL_ENABLED=true
MONETBIL_SERVICE_KEY=VOTRE_SERVICE_KEY_MONETBIL
MONETBIL_SERVICE_SECRET=VOTRE_SERVICE_SECRET_MONETBIL
MONETBIL_API_URL=https://api.monetbil.com
MONETBIL_NOTIFY_URL=https://api.bango.app/api/webhooks/payments/monetbil
PAYMENT_MONETBIL_FEE_TYPE=percentage_with_min
PAYMENT_MONETBIL_FEE_PERCENTAGE=2.0
PAYMENT_MONETBIL_MIN_FEE=100.0

# Authentification Sociale
GOOGLE_CLIENT_ID_WEB=xxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_ID_ANDROID=xxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_ID_IOS=xxxxxx.apps.googleusercontent.com
APPLE_CLIENT_ID=com.bango.app
APPLE_BUNDLE_ID=com.bango.app
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIGTAgE...\n-----END PRIVATE KEY-----"

# Sécurité & Sel Interne
CONTACT_MATCHING_SALT=CHAINE_ALEATOIRE_UNIQUE_DE_64_CARACTERES_MINIMUM

# WebSockets (Laravel Reverb)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=bango-app
REVERB_APP_KEY=cle_reverb_unique
REVERB_APP_SECRET=secret_reverb_unique
REVERB_HOST="ws.bango.app"
REVERB_PORT=443
REVERB_SCHEME="https"
```

---

### Étape 3 : Séquence d'Exécution des Commandes de Déploiement

Exécutez dans l'ordre les commandes suivantes sur le serveur :

```bash
# 1. Cloner et installer les dépendances de production sans paquets de dev
composer install --no-dev --optimize-autoloader

# 2. Exécuter les migrations de base de données
php artisan migrate --force

# 3. Initialiser les données de référence et catalogues obligatoires
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=GeoSeeder --force
php artisan db:seed --class=SubscriptionCatalogSeeder --force
php artisan db:seed --class=LegalDocumentSeeder --force
php artisan db:seed --class=HelpArticleSeeder --force
php artisan db:seed --class=SoundSeeder --force

# 4. Générer les clés de chiffrement de Laravel Passport (OAuth2)
php artisan passport:keys --force
php artisan passport:client --personal --no-interaction

# 5. Créer le lien symbolique de stockage
php artisan storage:link

# 6. Créer le premier compte Super-Administrateur Back-Office
php artisan admin:create --name="Direction Bango" --email="admin@bango.app" --role=super_admin

# 7. Mettre en cache toutes les configurations, routes et événements pour des performances maximales
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

### Étape 4 : Configuration des Processus d'Arrière-Plan (Démons Supervisor)

Créez le fichier `/etc/supervisor/conf.d/bango-workers.conf` :

```ini
[program:bango-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/bango_backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/bango-worker.log
stopwaitsecs=3600

[program:bango-reverb]
command=php /var/www/bango_backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/bango-reverb.log
```

Activez avec :
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

---

### Étape 5 : Planificateur de Tâches Système (Crontab)

Le backend BANGO comporte **10 tâches programmées indispensables** (Expiration des stories 15 min, verrous de visites 1 min, réconciliation des paiements 10 min, relances abonnements, reversements bailleurs à 08:00, purges Passport et KYC).

Ajoutez cette ligne unique dans le crontab de l'utilisateur `www-data` (`sudo crontab -u www-data -e`) :

```bash
* * * * * cd /var/www/bango_backend && php artisan schedule:run >> /dev/null 2>&1
```

---

### Étape 6 : Configuration des Webhooks Externes

Pour que la plateforme fonctionne de façon asynchrone sans bloquer les utilisateurs :
1. **Monetbil IPN :** Renseigner dans le dashboard Monetbil l'URL de notification :  
   `https://api.bango.app/api/webhooks/payments/monetbil`
2. **Cloudflare Stream :** Renseigner dans le dashboard Cloudflare Stream l'URL de webhook :  
   `https://api.bango.app/api/webhooks/cloudflare-stream`  
   et reporter le secret généré dans `CLOUDFLARE_STREAM_WEBHOOK_SECRET`.
3. **DNS Resend :** Configurer les entrées DNS `DKIM`, `SPF` et `DMARC` sur votre registrar pour valider le domaine `bango.app` et garantir que les emails d'OTP et reçus n'atterrissent jamais dans les spams.

---

### Étape 7 : Stratégie de Sauvegardes & Tolérance aux Pannes

1. **Sauvegarde PostgreSQL automatisée :** Exécution quotidienne d'un script `pg_dump` compressé, chiffré et transféré vers un stockage hors-site (ex: bucket de backup distant) avec rétention de 30 jours glissants.
2. **Rotation des logs :** Mise en place de `logrotate` pour éviter la saturation du disque par `storage/logs/laravel.log`.
3. **Surveillance d'intégrité :** Monitoring de la route de santé Laravel native `https://api.bango.app/up` via un service d'uptime (UptimeRobot, BetterUptime ou Pingdom).
