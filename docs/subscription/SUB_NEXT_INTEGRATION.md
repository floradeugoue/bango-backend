# BANGO — Guide d'Intégration Futur Module PAY ⟷ SUB

Ce document spécifie le contrat technique, les points de contact, les événements et les méthodes d'intégration que le futur module **PAY (Paiements & Transactions)** devra implémenter pour se connecter à **SUB (Abonnements & Badges)** sans couplage excessif.

---

## 1. Principes Directeurs d'Intégration

1. **SUB ne connaît pas les opérateurs télécoms :**
   - SUB ne sait pas si un paiement a été effectué via Orange Money, MTN MoMo, Wave ou Carte Bancaire.
   - SUB exige uniquement une confirmation formelle avec un montant, une devise et une référence de transaction unique.
2. **PAY ne gère pas les droits ni les durées d'abonnements :**
   - PAY enregistre la transaction financière, vérifie l'idempotence et traite les callbacks de passerelles.
   - Dès qu'une transaction est marquée `status = 'success'`, PAY invoque SUB via son interface de service ou émet un événement de domaine.
3. **Idempotence stricte :**
   - Aucune confirmation de paiement ne doit pouvoir être appliquée deux fois. PAY doit toujours transmettre la référence unique de transaction (`external_reference` ou `transaction_id`).

---

## 2. Le Flux Nominal de Souscription Mobile

```
[Mobile App]
     │
     │ 1. POST /api/subscriptions/subscribe {"price_id": 1}
     ▼
[Module SUB]
     │ (Vérifie éligibilité, produit actif, absence d'abonnement doublon)
     │ 2. Retourne { payment_intent: { purpose: 'subscription_landlord', amount: 7500, idempotency_key: ... } }
     ▼
[Mobile App]
     │
     │ 3. POST /api/payments/initiate { purpose: 'subscription_landlord', kind: 'orange_money', phone: '+237...', ... }
     ▼
[Module PAY]
     │ (Appel API Orange Money / Push USSD, Transaction::status = 'pending')
     │ 4. Retourne { transaction_id: 'tx_...', timeout: 120, prompt: '#150#' }
     ▼
[Opérateur Télécom / Push USSD Client]
     │ (L'utilisateur saisit son code PIN secret)
     │ 5. Webhook asynchrone reçu par PAY
     ▼
[Module PAY : PaymentWebhookController]
     │ (Vérifie signature HMAC de la passerelle, Transaction::status = 'success')
     │ 6. Déclenche l'activation dans SUB
     ▼
[Module SUB : SubscriptionService::activateSubscription()]
     │ - Met à jour l'abonnement : state = 'active', current_period_end = now() + 30j
     │ - Enregistre l'événement dans subscription_events
     │ - Recalcule et synchronise users.badge via BadgeResolutionService
     │ - Enregistre l'historique dans badge_histories
```

---

## 3. Méthode d'Appel Fournie par SUB pour PAY

Le module SUB expose la méthode suivante sur le conteneur de services Laravel :

```php
namespace App\Services\Subscription;

class SubscriptionService
{
    /**
     * Active ou renouvelle un abonnement suite à un paiement confirmé par PAY.
     *
     * @param User $user Utilisateur ayant payé
     * @param SubscriptionPrice $price Tarif souscrit
     * @param string $paymentReference Référence de transaction unique générée par PAY
     * @param array $metadata Données additionnelles (opérateur, montant payé, devises)
     * @return Subscription
     */
    public function activateSubscription(
        User $user,
        SubscriptionPrice $price,
        string $paymentReference,
        array $metadata = []
    ): Subscription;
}
```

### Événement de Domaine Recommandé (Optionnel)
PAY peut également émettre un événement Laravel standard :
```php
event(new \App\Events\PaymentSucceeded(
    userId: $transaction->user_id,
    purpose: $transaction->purpose,
    amount: $transaction->amount_total,
    reference: $transaction->id,
    metadata: $transaction->gateway_metadata
));
```
SUB comportera un listener `ActivateSubscriptionOnPayment` abonnant à cet événement.

---

## 4. Gestion des Échecs de Renouvellement Récurrent

Lorsque le cron de renouvellement automatique de PAY échouera à prélever le compte Mobile Money d'un utilisateur à échéance :
1. PAY informera SUB en invoquant :
   ```php
   app(SubscriptionService::class)->enterGracePeriod($subscription, $failureReason);
   ```
2. SUB basculera l'abonnement en `state = 'grace'`, avec `grace_until = now()->addDays(7)`.
3. Le badge restera actif pendant 7 jours, évitant la dépublication des annonces.
4. Si aucune régularisation n'intervient à $J+7$, le cron appellera :
   ```php
   app(SubscriptionService::class)->expireSubscription($subscription);
   ```
   Ce qui révoquera automatiquement le badge sur le profil et dépubliera les annonces.

---

## 5. Certification Page Pro (`page_pro`)

Pour la certification d'une Page Pro (`15 000 FCFA`) :
1. PAY traitera le paiement avec `purpose = 'page_certification'`.
2. À la confirmation du paiement, PAY notifiera SUB ou le futur module PG (Pages).
3. Le produit `page_pro` du catalogue SUB sert de référence de prix officielle.
4. La certification débloquera l'audit du dossier légal (RCCM + CNI gérant) avec jusqu'à 2 soumissions correctives gratuites incluses.
