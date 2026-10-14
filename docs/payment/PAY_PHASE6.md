# BANGO BACKEND — RAPPORT D'EXÉCUTION PHASE 6 : RENOUVELLEMENT MANUEL ASSISTÉ

## 1. Contexte et Objectifs

La Phase 6 du backend BANGO met en œuvre le cycle de vie du **renouvellement manuel assisté** des abonnements, combinant les modules SUB et PAY sous Laravel 12, PHP 8.4, PostgreSQL et Laravel Passport (`auth:api`).

### Objectifs atteints :
1. **Rappels planifiés avant échéance** : Information proactive des utilisateurs à l'approche du terme de leur abonnement (J-7, J-3, J-1) sans harcèlement.
2. **Relances après échec de paiement** : Notification ciblée et espacée (24h puis 72h) après un échec avéré de paiement.
3. **Parcours de renouvellement manuel assisté avec devis certifié** : L'utilisateur consulte son abonnement, obtient une cotation serveur certifiée, puis confirme explicitement son intention et son montant avant tout déclenchement Mobile Money.
4. **Période de grâce et expiration contrôlée** : Respect strict d'une période de grâce de 7 jours après l'échéance nominale pendant laquelle les droits et badges restent actifs, suivie d'une expiration idempotente avec retrait synchronisé du badge KYC.
5. **Prévention des doublons et des conflits** : Table dédiée `subscription_reminders` assurant l'idempotence des notifications, verrouillage contre les soumissions concurrentes et protection contre les doubles activations.

### Règle d'or absolue respectée :
- **Aucun prélèvement automatique** : Aucun paiement n'est initié sans consentement explicite de l'utilisateur.
- **Aucun allongement de droits prématuré** : Ni l'envoi d'un rappel, ni la consultation d'un devis, ni la création d'une intention de paiement ne prolongent un abonnement. Seule la confirmation fiable et vérifiée d'un paiement déclenche l'activation via `PaymentEngineService::confirmAndFulfill`.
- **Distinguer rigoureusement les états de paiement** : Un paiement en cours (`pending`, `processing`) ou indéterminé (`unknown`/timeout) n'est jamais assimilé à un échec définitif.

---

## 2. Architecture & Composants Réalisés

### A. Configuration Dédiée (`config/subscription.php`)
- `renewal_reminder_days` : `[7, 3, 1]` (délais de rappel configurables par rapport à `current_period_end`).
- `grace_period_days` : `7` (période de grâce pendant laquelle les fonctionnalités restent actives).
- `failed_payment_retry_hours` : `[24, 72]` (fenêtres de relance après un échec définitif).
- `max_failed_payment_reminders` : `2` (plafond de relances pour éviter tout spam).

### B. Base de Données & Idempotence (`subscription_reminders`)
1. **Migration SQL** : `database/migrations/2026_10_14_115420_create_subscription_reminders_table.php`
   - Table `subscription_reminders` avec index unique sur `idempotency_key`.
   - Colonnes : `subscription_id`, `user_id`, `type` (`upcoming_renewal`, `failed_payment`), `idempotency_key`, `target_date`, `attempt_number`, `channel`, `sent_at`, `metadata` (JSON).
2. **Migration Notifications** :
   - Table `notifications` créée pour le canal `database` de Laravel.
3. **Modèle Eloquent** : `app/Models/Subscription/SubscriptionReminder.php`
   - Génération déterministe des clés d'idempotence :
     - Rappel échéance : `makeUpcomingKey(subId, periodEnd, daysBefore)` -> `renewal_{subId}_{Y-m-d}_{daysBefore}d`.
     - Relance échec : `makeFailedPaymentKey(subId, transactionId, hoursDelay)` -> `failed_{subId}_{txId}_{hoursDelay}h`.

### C. Moteur Métier de Renouvellement (`SubscriptionRenewalService`)
Situé dans `app/Services/Subscription/SubscriptionRenewalService.php` :
1. **`sendUpcomingReminders()`** :
   - Parcourt les abonnements `active` dont l'échéance coïncide avec `J-7`, `J-3` ou `J-1` (selon le fuseau de l'application).
   - Vérifie l'absence de rappel déjà consigné pour cette clé d'idempotence.
   - Envoie `RenewalUpcomingReminderNotification` (canaux mail et database) avec les informations exactes d'échéance et l'URL de renouvellement.
   - Consigne un événement d'audit dans `subscription_events`.
2. **`sendFailedPaymentRetries()`** :
   - Cible les abonnements actifs ou en période de grâce ayant subi un échec de paiement.
   - **Protection cruciale** : Ignore les transactions dont le statut est `pending`, `processing` ou `unknown`.
   - Vérifie si le délai configuré (ex: 24h ou 72h) s'est écoulé depuis la dernière tentative échouée.
   - Envoie `PaymentFailedReminderNotification` renvoyant vers le parcours de renouvellement manuel (aucun débit direct).
3. **`processLifecycle()`** :
   - **Phase 1 - Bascule en Grâce** : Pour tout abonnement `active` dont `current_period_end <= now()` et n'ayant pas encore de `grace_until`, calcule `grace_until = current_period_end + 7 jours`, émet l'événement `grace_period_started`. Les droits et le badge restent intacts.
   - **Phase 2 - Expiration Contrôlée** : Pour tout abonnement où `grace_until <= now()` ou dont l'échéance est dépassée sans grâce, passe le statut en `expired`, consigne `subscription_expired` et retire le badge associé de l'utilisateur si aucun autre abonnement actif n'est détenu. L'opération est strictement idempotente.

### D. Notifications Laravel
1. `app/Notifications/Subscription/RenewalUpcomingReminderNotification.php` :
   - Indique clairement l'offre, la date exacte d'échéance et les instructions de renouvellement.
   - Ne revendique en aucun cas un débit effectif.
2. `app/Notifications/Subscription/PaymentFailedReminderNotification.php` :
   - Notifie l'utilisateur du rejet de sa tentative précédente, l'invite à réitérer manuellement et rappelle la date limite avant suspension des services.

### E. Commandes Console Artisan & Planification
1. `app/Console/Commands/Subscription/SendRenewalRemindersCommand.php` (`subscription:send-renewal-reminders`)
2. `app/Console/Commands/Subscription/SendFailedPaymentRemindersCommand.php` (`subscription:send-failed-payment-reminders`)
3. `app/Console/Commands/Subscription/ProcessSubscriptionLifecycleCommand.php` (`subscription:process-lifecycle`)
4. **Planification dans `routes/console.php`** :
   - `subscription:send-renewal-reminders` : Quotidien à 08:00 (`dailyAt('08:00')`), sans chevauchement (`withoutOverlapping()`), exécuté en tâche de fond (`runInBackground()`).
   - `subscription:send-failed-payment-reminders` : Toutes les heures (`hourly()`), sans chevauchement.
   - `subscription:process-lifecycle` : Toutes les heures (`hourly()`), sans chevauchement.

### F. Contrôleur API Mobile & Web (`SubscriptionRenewalController`)
Situé dans `app/Http/Controllers/Subscription/SubscriptionRenewalController.php`, protégé par `auth:api` :
1. `GET /api/subscriptions/{id}/renewal` :
   - Retourne les détails de l'abonnement, l'éligibilité au renouvellement, le statut de grâce éventuel et les tarifs disponibles.
   - Vérifie que l'utilisateur connecté est bien le propriétaire de l'abonnement (`403 Forbidden` sinon).
2. `POST /api/subscriptions/{id}/renewal/quote` :
   - Établit un devis officiel certifié côté serveur via `PricingService` (prix de base, frais éventuels, taxes, montant total).
   - Ignore tout montant fourni par le client.
   - Indique explicitement `requires_manual_confirmation: true`.
3. `POST /api/subscriptions/{id}/renewal/confirm` :
   - Exige la confirmation explicite de l'utilisateur (`confirmed: true`, `phone`, `operator`, `price_id`).
   - Vérifie l'absence de paiement déjà en cours (`Processing`) pour éviter les doubles débits.
   - Initialise la transaction et la tentative de paiement via `PaymentEngineService` et la passerelle Mobile Money.
   - Passe la transaction à l'état `Processing` dès l'acceptation du push USSD par l'opérateur.

---

## 3. Résultats des Tests de Validation

### Suite de Tests Pest Phase 6 (`tests/Feature/Subscription/SubscriptionRenewalPhase6Test.php`)
14 tests exhaustifs couvrant scrupuleusement les exigences de la section 10 :
1. ✅ **Notifications avant échéance** : Détection et notification correcte à J-7, J-3 et J-1.
2. ✅ **Idempotence des notifications** : Aucune notification en double lors d'exécutions répétées du planificateur.
3. ✅ **Abonnements inéligibles** : Exclusion stricte des abonnements déjà renouvelés, annulés ou expirés.
4. ✅ **Relances après échec avéré** : Déclenchement de relances aux fenêtres 24h et 72h pour les paiements définitivement échoués.
5. ✅ **Protection des paiements en cours** : Aucun envoi de relance pour des paiements au statut `pending` ou `processing`.
6. ✅ **Protection des paiements indéterminés** : Les paiements au statut `unknown` (ex: timeout de vérification) ne sont pas traités comme des échecs.
7. ✅ **Obligation de confirmation explicite** : Rejet de toute initiation sans confirmation explicite (`confirmed: true`).
8. ✅ **Calcul tarifaire certifié côté serveur** : Les montants envoyés par le client sont ignorés au profit de la cotation certifiée du serveur.
9. ✅ **Prévention des renouvellements concurrents** : Blocage d'une nouvelle tentative lorsqu'un push USSD est déjà en cours de traitement.
10. ✅ **Respect de la période de grâce** : Conservation active des droits et du badge pendant les 7 jours de grâce.
11. ✅ **Expiration idempotente** : Révocation idempotente des abonnements après dépassement de la période de grâce.
12. ✅ **Synchronisation des badges** : Retrait du badge utilisateur uniquement s'il ne détient plus aucun autre abonnement actif.
13. ✅ **Isolation des données utilisateur** : Rejet strict (`403 Forbidden`) lorsqu'un utilisateur tente de consulter ou renouveler l'abonnement d'autrui.
14. ✅ **Compatibilité avec le flux de confirmation PAY** : Après paiement confirmé, `PaymentEngineService::confirmAndFulfill` prolonge l'abonnement sans créer de doublon.

### Résultats d'exécution :
- `SubscriptionRenewalPhase6Test.php` : **14/14 tests réussis**, 64 assertions.
- Suite complète PAY (`tests/Feature/Payment`) : **81/81 tests réussis**, 503 assertions.
- Suite complète SUB (`tests/Feature/Subscription`) : **28/28 tests réussis**, 211 assertions.
- **Suite Complète de l'Application** : **205/205 tests réussis**, 1108 assertions, 0 échec.
- Formatage Pint : `vendor/bin/pint --format agent` exécuté avec succès (100% conforme aux standards du projet).

---

## 4. Guide d'Exploitation & Commandes

### Commandes Console Disponibles :
```bash
# Envoyer les rappels avant échéance (J-7, J-3, J-1)
php artisan subscription:send-renewal-reminders

# Envoyer les relances après échec de paiement (24h, 72h)
php artisan subscription:send-failed-payment-reminders

# Traiter le cycle de vie (période de grâce et expiration des droits)
php artisan subscription:process-lifecycle
```

### Configuration du Worker et du Cron :
Sur le serveur de production, la crontab standard Laravel doit être active :
```cron
* * * * * cd /chemin/vers/bango_backend && php artisan schedule:run >> /dev/null 2>&1
```
Les tâches planifiées s'exécutent de manière asynchrone et sans chevauchement grâce aux directives `withoutOverlapping()` et `runInBackground()`.

---

## 5. Synthèse de Sécurité & Non-Régression

- **Aucun prélèvement automatique** : Le renouvellement est 100% initié et confirmé par l'utilisateur.
- **Aucune altération des transactions historiques** : Les tables `payment_transactions`, `payment_attempts` et `payment_events` conservent leur immutabilité.
- **Aucun paiement réel déclenché lors des tests** : Utilisation exclusive de doubles de passerelle et de mocks HTTP.
