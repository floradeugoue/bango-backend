# BANGO — Spécification du Moteur de Tarification PAY

Ce document formalise les règles de calcul des prix, des frais d'opérateur/passerelle et des taxes applicables au sein du module **PAY**.

---

## 1. Principes et Règle d'Or Métier

1. **Frais Monetbil Facturés en Plus au Client :**
   - Conformément aux exigences produit, les frais de transaction Mobile Money prélevés par Monetbil sont **facturés en sus** au client final.
   - Le montant exact du service, les frais de passerelle et le montant total doivent être intégralement détaillés et transparents avant que l'utilisateur n'initie son paiement.
2. **Absence d'Invention de Taux :**
   - Aucun taux n'est codé en dur dans les services. Les frais sont définis dans `config/payment.php` et paramétrables via l'environnement.
   - Si la configuration des frais est absente ou incomplète pour la passerelle sollicitée, le moteur refuse formellement de générer le devis en levant une exception `MissingPricingConfigurationException`.
3. **Précision Décimale Absolue :**
   - Tous les calculs utilisent des représentations en chaînes décimales formatées à deux chiffres (`DECIMAL(12, 2)`), excluant tout type flottant IEEE 754.
4. **Photographie Tarifaire Immuable (Snapshot) :**
   - Chaque devis génère une photographie complète (`pricing_snapshot`) comprenant le prix de base, la règle de frais appliquée, la devise, les taxes et le total.
   - Ce snapshot est stocké dans la colonne `metadata` de `payment_transactions`. Une modification ultérieure du catalogue SUB n'altère jamais les montants d'une transaction existante.

---

## 2. Formules Mathématiques

### 2.1. Calcul des Frais de Passerelle
Pour un montant de base $A$, un taux de frais en pourcentage $P$, des frais fixes $F$, un seuil minimum $M_{\min}$ et un plafond optionnel $M_{\max}$ :

$$\text{Frais Bruts} = \left( A \times \frac{P}{100} \right) + F$$

$$\text{fee\_amount} = \min\left(M_{\max}, \max\left(M_{\min}, \text{Frais Bruts}\right)\right)$$

*Exemple nominal Monetbil :*
- $P = 2.0\%$
- $F = 0.0\text{ XAF}$
- $M_{\min} = 100.0\text{ XAF}$

Pour un abonnement Agent Mensuel ($A = 3\,500\text{ XAF}$) :
$$3\,500 \times 2\% = 70.0\text{ XAF} < 100\text{ XAF} \implies \text{fee\_amount} = 100.00\text{ XAF}$$

Pour un abonnement Bailleur Mensuel ($A = 7\,500\text{ XAF}$) :
$$7\,500 \times 2\% = 150.0\text{ XAF} \ge 100\text{ XAF} \implies \text{fee\_amount} = 150.00\text{ XAF}$$

### 2.2. Calcul des Taxes
Si `payment.tax.enabled = true`, avec un taux de taxe $T$ (ex: TVA à 19.25%) :

$$\text{tax\_amount} = A \times \frac{T}{100}$$

Si `payment.tax.enabled = false` :
$$\text{tax\_amount} = 0.00\text{ XAF}$$

### 2.3. Formule du Montant Total Facturé
$$\text{total\_amount} = A + \text{fee\_amount} + \text{tax\_amount}$$

---

## 3. Services Pris en Charge

| Type de Service (`PaymentServiceType`) | Source du Prix | Règle de Résolution | Exemple Nominal |
| :--- | :--- | :--- | :--- |
| **`subscription`** | Catalogue SUB | Récupère le tarif via `price_id` dans `subscription_prices`. Vérifie `is_active = true` et `effective_from/effective_until`. | Bailleur Mensuel : 7 500 XAF + 150 XAF frais = **7 650 XAF** |
| **`certification`** | Catalogue SUB (`page_pro`) | Récupère le tarif unique d'examen (one_off) de la certification Page Pro. | Audit Page Pro : 15 000 XAF + 300 XAF frais = **15 300 XAF** |
| **`boost`** | Catalogue SUB (`listing_boost`) | Récupère le tarif CPM configuré pour le boost de visibilité d'annonce. | Boost 1 000 imp : 2 180 XAF + 100 XAF frais = **2 280 XAF** |
| **`visit`** | Contexte de réservation | Reçoit un montant explicite $> 0$ issu de la réservation de visite immobilière. | Visite guidée : 5 000 XAF + 100 XAF frais = **5 100 XAF** |

---

## 4. Structure de l'Objet `PriceQuote`

```json
{
  "service_type": "subscription",
  "product_name": "Badge Bailleur Certifié",
  "price_id": 1,
  "currency": "XAF",
  "amount": 7500.00,
  "fee_amount": 150.00,
  "tax_amount": 0.00,
  "total_amount": 7650.00,
  "gateway": "monetbil",
  "breakdown": {
    "base_price": 7500.00,
    "gateway_fee": 150.00,
    "tax": 0.00,
    "total": 7650.00,
    "currency": "XAF",
    "billed_to_customer": true
  }
}
```
