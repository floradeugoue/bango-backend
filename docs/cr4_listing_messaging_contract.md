# Contrat d'Intégration — Référencement des Annonces Immobilières dans la Future Messagerie (MS)

**Statut du document** : Spécification d'architecture et contrat d'interface pour le futur module Messagerie.
**Important** : Le module Messagerie n'est pas implémenté dans cette étape. Aucune table de conversations, de messages, de boîtes de réception ou de pièces jointes de chat n'a été créée.

---

## 1. Contexte et Objectif

Dans l'application mobile BANGO, un utilisateur consultant une annonce immobilière (écran `app/listing.tsx` ou feed `app/(tabs)/immo.tsx`) aura ultérieurement la possibilité de cliquer sur *"Contacter le bailleur"* ou *"Envoyer un message"* afin d'initier une conversation ciblée.

Le but de ce contrat est de définir comment le futur module Messagerie (MS) fera référence à une annonce de manière normalisée, légère et découplée, sans dupliquer inutilement les données de l'annonce en base de données.

---

## 2. Fondations Backend Préparées

Dans le module CR4, les fondations suivantes sont prêtes et garanties stables :

1. **Identifiant immuable** :
   Chaque annonce possède une clé primaire UUID stable (`listings.id` de type `uuid`).

2. **Ressource API contextuelle légère** :
   Le modèle `App\Models\Listing\Listing` expose une méthode dédiée `toMessagingCard(): array` prête à être embarquée dans n'importe quelle charge utile de message ou aperçu de conversation :
   ```php
   [
       'id' => $listing->id,
       'title' => $listing->title,
       'mode' => $listing->mode->value,
       'kind' => $listing->kind->value,
       'status' => $listing->status->value,
       'price' => (float) $listing->price,
       'currency' => $listing->currency,
       'city' => $listing->city,
       'neighbourhood' => $listing->neighbourhood,
       'primary_photo' => $listing->photos->first()?->url,
       'owner_id' => $listing->owner_id,
       'agent_id' => $listing->agent_id,
   ]
   ```

3. **Protection de confidentialité** :
   Si une annonce passe au statut `draft`, `review` ou est supprimée (`soft deleted`), la carte de message pourra afficher l'indicateur `"Cette annonce n'est plus disponible"` sans rompre l'intégrité de la conversation.

---

## 3. Schéma de Clé Étrangère Recommandé pour le Futur Module MS

Lorsque le module Messagerie sera développé, la table des messages ou conversations pourra intégrer une colonne `listing_id` optionnelle :

```php
// Exemple pour la future migration des messages
$table->foreignUuid('listing_id')
    ->nullable()
    ->constrained('listings')
    ->nullOnDelete();
```

### Comportement en cas de suppression de l'annonce :
Grâce à `nullOnDelete()` et au `SoftDeletes` présent sur la table `listings`, la suppression d'une annonce n'effacera jamais l'historique des messages des utilisateurs. L'identifiant reste intact ou passe à `null` selon la règle de rétention retenue.

---

## 4. Format de Carte d'Annonce dans le Message (Payload JSON)

Lorsqu'un message contiendra un contexte d'annonce (`type: "listing_inquiry"`), le payload de message envoyé au client mobile contiendra :

```json
{
  "message_id": "990e8400-e29b-41d4-a716-446655440099",
  "sender_id": 45,
  "recipient_id": 12,
  "type": "listing_inquiry",
  "text": "Bonjour, ce bien est-il toujours disponible pour une visite ce samedi ?",
  "listing": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Superbe Appartement Bonanjo",
    "price": 250000,
    "currency": "XOF",
    "mode": "rent",
    "city": "Douala",
    "neighbourhood": "Bonanjo",
    "primary_photo": "https://bango.test/storage/listings/photos/photo_1.webp",
    "status": "published"
  },
  "created_at": "2026-10-15T20:30:00Z"
}
```

Ce contrat permet au frontend React Native d'afficher directement un aperçu visuel (carte cliquable) au sommet du fil de discussion sans faire de requête réseau additionnelle.
