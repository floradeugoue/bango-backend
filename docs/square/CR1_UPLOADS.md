# GESTION DES TÉLÉVERSEMENTS ET STOCKAGE MÉDIAS (CR1)

## 1. Architecture de Téléversement
Le téléversement de médias Square s'appuie sur le disque configuré dans Laravel (`public` par défaut, basculable sur S3 / MinIO / Cloudflare R2 sans modification de code applicatif via `config/filesystems.php`).

### 1.1. Flux de Téléversement Client-Serveur
1. L'application mobile sélectionne et retouche une ou plusieurs photos/vidéos (CR-02, CR-05).
2. L'application envoie chaque fichier via une requête multipart vers `POST /api/v1/media/upload`.
3. Le serveur valide le type MIME, calcule les dimensions (pour les photos), génère un nom non prévisible et stocke le fichier dans le dossier dédié (`storage/app/public/square/images/{user_id}/` pour les photos, ou `storage/app/public/square/videos/{user_id}/` pour les vidéos).
4. Le serveur renvoie l'objet média contenant l'identifiant unique, l'URL publique, le type, la taille et les dimensions.
5. Lorsque tous les médias sont prêts, l'application soumet le payload final à `POST /api/v1/posts` avec la liste des médias ordonnés (`display_order`).

---

## 2. Reprise sur Coupure Réseau (CR-13, CR-14)
- Les brouillons sont stockés localement sur le smartphone de l'utilisateur (AsyncStorage / SQLite local) conformément à CR-14.
- Le gestionnaire de publication client (`PublishJob` dans `lib/api/publishing.ts`) gère l'état d'avancement (`working`, `failed`, `done`).
- En cas de perte de connexion pendant l'upload, le client mobile relance l'upload des médias manquants.
- Lors de l'envoi final du post, le client transmet une clé d'idempotence (`idempotency_key`). Si la requête a déjà été traitée par le serveur avant la coupure, le serveur renvoie la publication existante avec le code 200 au lieu de créer un doublon.

---

## 3. Nettoyage et Maintenance
- Les médias attachés à une publication supprimée sont nettoyés lors de la purge définitive ou conservés pendant la durée de rétention du soft-delete.
- Un job de maintenance périodique peut nettoyer les fichiers orphelins n'ayant pas été rattachés à une publication sous 48 heures.
