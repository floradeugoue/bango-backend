# Contrat d'Intégration — Réponses Privées Story & Future Messagerie

**Version du contrat** : `1.0`  
**Module source** : `CR3 — Story`  
**Module cible futur** : `Messagerie & Boîte de réception privée`  
**Date d'émission** : Octobre 2026  

---

## 1. Principes Directeurs & Périmètre

Le module **CR3 — Story** est entièrement autonome et découplé de la future messagerie :
1. **Aucune table de messagerie artificielle** : CR3 n'invente pas de schéma concurrent pour les conversations ou les messages.
2. **Enregistrement de l'intention** : Lorsqu'un utilisateur répond à une Story ou envoie une réaction rapide emoji, CR3 enregistre une **interaction Story** dans la table `story_replies`.
3. **Statut initial** : L'interaction est persistée avec le statut `pending` (en attente d'ingestion par la messagerie).
4. **Non-ambiguïté** : L'API CR3 confirme au client que l'interaction a été enregistrée avec succès, sans prétendre qu'un message de chat a déjà été acheminé si aucune conversation n'a été créée.

---

## 2. Modèle de Données d'une Interaction (`story_replies`)

| Colonne | Type | Description |
| --- | --- | --- |
| `id` | UUID | Identifiant unique et immuable de l'interaction |
| `story_id` | UUID | Clé étrangère vers la Story ciblée |
| `story_slide_id` | UUID (nullable) | Clé étrangère vers la diapositive précise |
| `story_author_id` | BigInt | Identifiant du créateur et destinataire de la réponse |
| `user_id` | BigInt | Identifiant du répondant (expéditeur) |
| `type` | String (`text` ou `reaction`) | Type d'interaction |
| `text` | Text (nullable) | Contenu textuel rédigé si `type = text` |
| `reaction` | String (nullable) | Caractère Unicode / Émoji si `type = reaction` |
| `status` | String | État d'intégration : `pending`, `forwarded_to_inbox`, `dismissed` |
| `idempotency_key` | String (nullable) | Clé client unique pour empêcher les doublons réseau |
| `metadata` | JSON | **Snapshot de la carte d'aperçu Story** (autonome même après expiration) |
| `future_conversation_id` | String (nullable) | Point d'extension pour rattacher l'identifiant de conversation créé |
| `future_message_id` | String (nullable) | Point d'extension pour rattacher l'identifiant du message créé |
| `created_at` | Timestamp | Date et heure de l'interaction |

---

## 3. Snapshot de la Carte d'Aperçu (`metadata`)

Lors de la création de la réponse, CR3 fige un aperçu immuable de la Story dans le champ `metadata`. Cet aperçu permet à la future interface de messagerie d'afficher la vignette et le contexte de la Story, **même si celle-ci expire ou est ultérieurement supprimée** :

```json
{
  "story_id": "9d1b092a-8742-4f36-a36c-dfc919d65742",
  "author_handle": "cpaul",
  "author_name": "Christian Paul",
  "media_url": "https://storage.bango.app/stories/images/12/story_1.jpg",
  "thumbnail_url": "https://storage.bango.app/stories/thumbnails/12/story_1_thumb.jpg",
  "caption": "Superbe coucher de soleil à Kribi !",
  "filter": "vintage",
  "published_at": "2026-10-15T14:30:00.000000Z"
}
```

---

## 4. Événement Métier : `StoryReplyCreated`

Dès qu'une réponse privée est validée et stockée, l'événement Laravel `App\Events\Story\StoryReplyCreated` est automatiquement émis sur le bus d'événements interne.

### Payload standardisé de l'événement (`$event->integrationPayload`) :
```json
{
  "event": "story.reply.created",
  "version": "1.0",
  "interaction_id": "8e3c1782-41f2-4981-b519-aa579401bfd1",
  "story_id": "9d1b092a-8742-4f36-a36c-dfc919d65742",
  "story_slide_id": "31e76916-cac3-4639-9717-cbc49d9348d0",
  "author_id": 12,
  "respondent_id": 45,
  "type": "text",
  "text": "Magnifique photo ! C'était où ?",
  "reaction": null,
  "status": "pending",
  "idempotency_key": "c2b19f6a-4972-4d7a-8bfe-9c5963f4a47d",
  "story_preview_card": {
    "story_id": "9d1b092a-8742-4f36-a36c-dfc919d65742",
    "author_handle": "cpaul",
    "author_name": "Christian Paul",
    "media_url": "https://storage.bango.app/stories/images/12/story_1.jpg",
    "thumbnail_url": "https://storage.bango.app/stories/thumbnails/12/story_1_thumb.jpg",
    "caption": "Superbe coucher de soleil à Kribi !"
  },
  "created_at": "2026-10-15T16:15:30.000000Z"
}
```

---

## 5. Mécanisme d'Idempotence

- Le client mobile peut fournir un en-tête ou champ `idempotency_key`.
- Un index unique garantit l'unicité de `(user_id, idempotency_key)`.
- Si une requête réseau est rejouée avec la même clé d'idempotence, le backend CR3 renvoie immédiatement l'interaction déjà existante sans doublon en base ni réémission de l'événement.

---

## 6. Règles Métier & Gestion des Cas Limites

1. **Story expirée (> 24h)** :
   - Toute tentative de réponse sur une Story ayant dépassé sa fenêtre active est rejetée avec un code HTTP `410 Gone`.
2. **Réponses désactivées (`allow_replies = false`)** :
   - L'auteur peut refuser les réponses privées lors de la publication. Toute tentative est rejetée avec un code HTTP `403 Forbidden`.
3. **Auto-réponse interdite** :
   - L'auteur ne peut pas répondre en privé à sa propre Story. Rejet avec code HTTP `422 Unprocessable Content`.
4. **Blocage entre comptes** :
   - Si l'auteur a bloqué le répondant, ou si le répondant a bloqué l'auteur, la réponse est rejetée avec code HTTP `403 Forbidden`.
5. **Suppression ultérieure de la Story** :
   - La suppression d'une Story n'altère pas les interactions déjà archivées : la carte d'aperçu stockée dans `metadata` reste intacte pour l'affichage de l'historique dans la conversation.

---

## 7. Point d'Extension pour l'Équipe Messagerie

Lorsque le module Messagerie sera développé, il lui suffira de :
1. Définir un Listener Laravel écoutant `App\Events\Story\StoryReplyCreated`.
2. Résoudre ou initialiser le fil de conversation direct entre `respondent_id` et `author_id`.
3. Créer le message dans la messagerie avec la référence `interaction_id` et la carte d'aperçu `story_preview_card`.
4. Mettre à jour l'enregistrement `StoryReply` :
   ```php
   $reply->update([
       'status' => 'forwarded_to_inbox',
       'future_conversation_id' => (string) $conversation->id,
       'future_message_id' => (string) $message->id,
   ]);
   ```
