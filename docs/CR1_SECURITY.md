# SÉCURITÉ ET CONTRÔLE D'ACCÈS — MODULE CR1 SQUARE BANGO

## 1. Principes de Sécurité Appliqués

1. **Authentification Forte OAuth2** :
   Toutes les routes d'écriture (`POST`, `PATCH`, `DELETE`) et d'accès contextuel sont protégées par le middleware `auth:api` de Laravel Passport.

2. **Protection IDOR (Insecure Direct Object References)** :
   Chaque action post-publication (modification de légende, d'audience, de statut de commentaires, archivage, désarchivage, suppression) est soumise à `PostPolicy`. Seul l'auteur authentifié du post a le droit d'effectuer ces opérations. Une tentative non autorisée retourne immédiatement un code HTTP `403 Forbidden`.

3. **Protection des Données Privées & Visibilité d'Audience** :
   - `public` : visible par tous.
   - `followers` : visible uniquement par l'auteur et les utilisateurs ayant une relation `Follow` validée avec l'auteur.
   - `neighbourhood` : restreint aux utilisateurs partageant la même ville ou le même quartier.
   - `is_archived` : les publications archivées sont invisibles dans tous les flux publics et ne sont restituées qu'à l'auteur lorsqu'il consulte explicitement ses archives.

4. **Validation Stricte des Fichiers et Uploads** :
   - Validation stricte des types MIME réels (images: `jpeg`, `png`, `webp` ; vidéos: `mp4`, `quicktime`).
   - Limitation de la taille à 15 Mo pour les images et 100 Mo pour les vidéos.
   - Noms de fichiers générés aléatoirement avec UUID v4 (`media_{uuid}.ext`) pour empêcher toute collision, prédictibilité et traversée de répertoires (`Directory Traversal`).

5. **Atomicité et Intégrité des Transactions** :
   La création d'un post avec ses médias, tags et hashtags est encapsulée dans une transaction SQL (`DB::transaction`). Si une seule opération échoue, l'ensemble est annulé (rollback).

6. **Protection contre la Duplication et Réseau Instable** :
   L'idempotence basée sur une clé unique (`idempotency_key`) empêche la duplication de publications lors de retentatives réseau du client mobile.
