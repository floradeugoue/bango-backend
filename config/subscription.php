<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paramètres de Rappel de Renouvellement d'Abonnement
    |--------------------------------------------------------------------------
    |
    | Délais (en jours) avant échéance pour envoyer les notifications de rappel.
    | Initialement configuré à J-7, J-3 et J-1.
    |
    */
    'reminders_days_before' => [7, 3, 1],

    /*
    |--------------------------------------------------------------------------
    | Période de Grâce (Grace Period)
    |--------------------------------------------------------------------------
    |
    | Nombre de jours pendant lesquels l'utilisateur conserve ses droits et ses
    | badges après l'échéance de son abonnement, en attente de renouvellement manuel.
    |
    */
    'grace_period_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Délais de Relance après Échec de Paiement
    |--------------------------------------------------------------------------
    |
    | Heures écoulées après un échec définitif de paiement avant d'envoyer
    | une notification de relance pour inciter au renouvellement manuel.
    |
    */
    'retry_delays_hours' => [24, 72],

    /*
    |--------------------------------------------------------------------------
    | Notifications Actives
    |--------------------------------------------------------------------------
    */
    'notifications_enabled' => env('SUBSCRIPTION_NOTIFICATIONS_ENABLED', true),
];
