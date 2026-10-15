# Audit Initial — Étape 2 : Agenda, Créneaux et Réservation Transactionnelle des Visites Immobilières

**Date d'audit :** 15 octobre 2026
**Système audité :** Backend Laravel BANGO & Référence fonctionnelle Frontend React Native
**Auteur :** Développeur Backend Senior BANGO

---

## 1. Contexte et Objectifs de l'Étape 2

L'objectif de cette Étape 2 est de construire le moteur transactionnel de réservation des visites immobilières dans BANGO.
Ce système fait le pont entre :
- Le module immobilier **IM** (annonces, frais de visite, créneaux disponibles, historique du prospect).
- L'espace Bailleur **BL** (règles de créneaux BL-08, réception des demandes BL-07, acceptation, contre-proposition, refus).
- L'espace Agent **AG** (mandats AG-02/AG-03 avec capacité `visits`).
- Le système de paiement **PAY** (paiement Mobile Money Monetbil, devis, reçu, webhook IPN et remboursements).
- Les mécanismes de gestion des cas limites **EDGE** (verrou atomique de 15 minutes, expiration automatique, double réservation, paiement tardif).
- Les notifications **SY** (alertes en temps réel sans blocage des transactions SQL).

---

## 2. Analyse de l'Existant Backend

### 2.1. Module Immobilier (Étape 1 - CR4)
- Table `listings` avec clé primaire UUID (`listings.id`).
- Colonne `visit_fee` (decimal) : frais de réservation de visite configurés par le propriétaire.
- Colonne `currency` (string, ex: `XOF`, `XAF`).
- Colonnes `owner_id` (propriétaire) et `agent_id` (agent délégué).
- Colonnes `location` (indicative, publique) et `address` (adresse exacte, privée).
- Relation `owner` et `agent` vers le modèle `User`.

### 2.2. Système de Paiement (PAY)
- Modèle `PaymentTransaction` avec clé UUID/string `reference`, colonnes polymorphiques `payable_type` et `payable_id`.
- Enum `PaymentServiceType::Visit` (`'visit'`) déjà déclaré dans le code avec le libellé `"Frais de Visite Immobilière"`.
- Service `PaymentEngineService` avec gestion des états `pending`, `processing`, `confirmed`, `failed`, `cancelled`, `refunded`.
- Passerelle `MonetbilGateway` avec vérification de signature MD5 timing-safe et double-check serveur-à-serveur `checkStatus()`.
- Contrôleur webhook IPN `MonetbilWebhookController` opérationnel.
- **Principe d'isolation PAY / AB** : Les transactions de visites sont typées `PaymentServiceType::Visit` et ne touchent en rien le modèle `Subscription` ni les catalogues de badges.

### 2.3. Système de Badges et Vérification KYC
- Modèle `User` avec `is_verified` (KYC), `verification_lapsed`, `locked_until`, `suspended_until`, `badge`.
- `BadgeResolutionService` gère dynamiquement la validité des badges.
- `ListingPolicy` vérifie les droits d'administration et de gestion (`canManage`).

---

## 3. Analyse de la Référence Fonctionnelle Frontend (Lecture Seule)

### 3.1. `app/booking.tsx` & `lib/api/visits.ts`
- **Verrouillage au paiement** : La sélection d'un créneau dans l'UI ne bloque pas le créneau de manière permanente ; c'est la création de la réservation qui pose un verrou temporaire de 15 minutes (`awaiting_payment` / `pending_payment`).
- **Confirmation automatique ou manuelle** :
  - Si le bailleur a activé `automatic = true` (dans ses règles de créneaux BL-08) : la visite passe directement en `confirmed` dès validation du paiement.
  - Si `automatic = false` : la visite passe en `requested` (demandée) et le bailleur dispose de 18 heures pour répondre (`ANSWER_HOURS = 18`).
- **Réponses possibles du bailleur (BL-07)** :
  - `accept` : visite confirmée (`confirmed`).
  - `propose` : proposition d'un autre créneau libre (`proposed`). Le prospect a le choix d'accepter ou de décliner.
  - `decline` : refus avec motif (`declined`), entraînant un remboursement automatique.
- **Délai de réponse dépassé** : Si le bailleur ne répond pas sous 18 h, la demande passe en `declined` (`reason: no-answer`) et est automatiquement remboursée.
- **Annulation par le prospect (IM-14)** :
  - Si l'annulation a lieu plus de 24 h avant l'heure de visite (`LATE_CANCEL_HOURS = 24`), ou si la visite n'est pas encore confirmée (`requested` ou `proposed`) : remboursement intégral des frais de visite.
  - Si l'annulation a lieu à moins de 24 h d'une visite confirmée : les frais sont conservés (non remboursables).
- **Règles de créneaux (BL-08)** :
  - Durée par défaut d'une visite : 30 minutes.
  - Pas entre créneaux : 90 minutes.
  - Délai minimum avant visite (`minimumHours`) : 4 heures par défaut.
  - Visites groupées (`grouped: boolean`, `groupSize: number`).

---

## 4. Décisions Techniques et Architecture Cible

1. **Table `visit_slots`** :
   - Clé primaire UUID.
   - Rattachement à `listing_id` et `owner_id` (avec `agent_id` optionnel).
   - Horaires `start_at` et `end_at` avec fuseau horaire explicite.
   - Capacité `capacity` (par défaut 1, ou `groupSize`) et compteur `booked_count`.
   - Statut : `open`, `closed`, `cancelled`.

2. **Table `visit_reservations`** :
   - Clé primaire UUID.
   - Relations vers `listing_id`, `slot_id`, `prospect_id`, `owner_id`, `agent_id`.
   - Statuts normalisés : `pending_payment`, `requested`, `confirmed`, `proposed`, `cancelled`, `declined`, `expired`, `refund_pending`, `refunded`.
   - Verrou temporaire : `lock_expires_at` (15 minutes atomiques).
   - Données financières figées : `fee`, `currency`, `payment_reference`, `transaction_id`.
   - Suivi d'audit : `paid_at`, `answered_at`, `cancelled_at`, `refunded_at`.

3. **Protection de l'adresse exacte (Sécurité)** :
   - L'adresse exacte (`address`) et les consignes d'accès sont masquées (`null`) dans les API tant que la visite n'est pas au statut `confirmed`.

4. **Tâches planifiées et Expiration (EDGE)** :
   - Commande `visits:expire-pending` : libère les verrous non payés après 15 minutes.
   - Commande `visits:settle-overdue` : traite les demandes sans réponse après 18 heures (auto-decline & remboursement).
