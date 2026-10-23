<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Délai de réponse du bailleur (heures)
    |--------------------------------------------------------------------------
    |
    | Délai accordé au bailleur pour traiter une demande de visite (BL-07)
    | avant expiration automatique et remboursement intégral du prospect.
    |
    */
    'answer_hours' => (int) env('VISITS_ANSWER_HOURS', 24),
];
