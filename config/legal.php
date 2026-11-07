<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce Terms Acceptance
    |--------------------------------------------------------------------------
    |
    | Détermine si le middleware terms.accepted bloque activement les requêtes
    | d'écriture des utilisateurs n'ayant pas encore accepté les CGU obligatoires.
    |
    | IMPORTANT : En production, doit rester à false tant que la nouvelle
    | version mobile gérant l'écran de CGU et le 403 n'est pas déployée
    | sur les stores, pour éviter de bloquer les anciennes versions d'app.
    |
    */
    'enforce_terms_acceptance' => (bool) env('ENFORCE_TERMS_ACCEPTANCE', false),

];
