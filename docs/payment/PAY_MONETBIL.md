# BANGO — Spécification & Intégration Passerelle Monetbil (Direct API)

Ce document formalise l'intégration de la passerelle **Monetbil** au sein du backend BANGO, via le mode direct server-to-server (Direct Payment API).

---

## 1. Principes & Sécurité des Clés

1. **Zéro Clé en Clair :**
   - Les identifiants marchands (`MONETBIL_SERVICE_KEY` et `MONETBIL_SERVICE_SECRET`) ne sont jamais codés en dur, jamais versionnés dans Git et jamais exposés dans les logs ou les réponses API.
   - Ils sont exclusivement injectés via les variables d'environnement dans `config/payment.php`.
2. **Double Vérification Serveur Systématique :**
   - Une notification webhook ou un retour de l'application cliente ne constitue jamais, à elle seule, une preuve de paiement.
   - Tout passage à l'état `confirmed` est conditionné par une requête directe `checkPayment` auprès des serveurs Monetbil.
3. **Protection Temporelle (Timing-Safe) :**
   - La comparaison de la signature cryptographique utilise strictement `hash_equals()`.

---

## 2. API d'Initialisation : `placePayment` (Push USSD)

* **Méthode & URL :** `POST https://api.monetbil.com/payment/v1/placePayment`
* **Format :** `application/x-www-form-urlencoded`
* **Délais :** Connect Timeout : 5s | Request Timeout : 15s

### Paramètres Transmis par BANGO

| Paramètre | Type | Valeur / Règle |
| :--- | :--- | :--- |
| `service` | `string` | `config('payment.gateways.monetbil.service_key')` |
| `phonenumber` | `string` | Numéro normalisé avec indicatif sans `+` (ex: `237699112233`) |
| `amount` | `float` | Montant total figé de la transaction, **frais inclus** (ex: `7650.00`) |
| `currency` | `string` | `"XAF"` |
| `country` | `string` | `"CM"` (Cameroun) |
| `item_ref` | `string` | Référence publique unique BANGO (ex: `BNG-TX-20261014-9K3L8A7Z`) |
| `payment_ref` | `string` | Référence de la transaction locale |
| `user` | `string` | Identifiant ID de l'utilisateur BANGO |
| `notify_url` | `string` | URL publique sécurisée : `https://.../api/webhooks/payments/monetbil` |
| `operator` | `string` (opt) | Code opérateur (`CM_ORANGEMONEY`, `CM_MTN`) si précisé |

### Réponse Monetbil Reçue

```json
{
  "status": "REQUEST_ACCEPTED",
  "paymentId": "mb_pay_8917234871239",
  "message": "Payment request has been initiated.",
  "channel_ussd": "#150*50#"
}
```

---

## 3. API de Vérification : `checkPayment`

* **Méthode & URL :** `POST https://api.monetbil.com/payment/v1/checkPayment`
* **Paramètre :** `paymentId = mb_pay_...`

### Interprétation des Statuts Reçus

| Valeur `status` | Signification Monetbil | Action BANGO |
| :--- | :--- | :--- |
| `1` ou `"success"` | Paiement validé par l'opérateur avec succès | Confirme la transaction, enregistre la référence télécom et active la prestation SUB. |
| `-1` ou `"pending"` | En cours de validation USSD par le client | Maintient la transaction en `processing`. |
| `0` ou `"failed"` / `"cancelled"` | Rejet, solde insuffisant ou refus PIN | Bascule la transaction en `failed` avec motif. |

---

## 4. Webhook IPN & Algorithme de Signature MD5

Monetbil émet un appel `POST` sur `notify_url` lors de l'aboutissement ou du rejet d'une transaction.

### Algorithme Officiel de Validation

```php
// 1. Extraire tous les paramètres et la signature reçue
$params = $request->all();
$incomingSignature = $request->input('sign');

// 2. Retirer la clé sign
unset($params['sign']);

// 3. Trier les clés par ordre alphabétique strict
ksort($params);

// 4. Concaténer le SERVICE_SECRET suivi de toutes les valeurs
$concatenated = $serviceSecret . implode('', $params);

// 5. Calculer le hash MD5
$expectedSignature = md5($concatenated);

// 6. Comparaison timing-safe
if (!hash_equals(strtolower($expectedSignature), strtolower($incomingSignature))) {
    abort(403, 'Signature invalide.');
}
```

### Contrôles Anti-Fraude Supplémentaires
À réception du webhook, BANGO effectue trois contrôles stricts :
1. **Concordance de la Devise :** rejet si différent de `XAF`.
2. **Concordance du Montant :** rejet avec enregistrement d'un événement `fraud_alert` si le montant notifié diffère du montant total dû enregistré.
3. **Contre-vérification :** appel direct de `checkPayment` avant confirmation de l'abonnement.
