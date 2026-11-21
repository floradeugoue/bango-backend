<?php

return [
    // Social
    'like' => [
        'title' => 'Nouveau j\'aime',
        'body' => ':user a aimé ta publication.',
    ],
    'story_like' => [
        'title' => 'Nouveau j\'aime',
        'body' => ':user a aimé ta story.',
    ],
    'story_question_answer' => [
        'title' => 'Réponse à ta question',
        'body' => ':user a répondu à ta question : ":snippet"',
    ],
    'countdown_remind' => [
        'title' => 'Rappel d\'événement',
        'body' => 'C\'est l\'heure : le compte à rebours que tu suivais est terminé.',
    ],
    'comment' => [
        'title' => 'Nouveau commentaire',
        'body' => ':user a commenté ta publication : ":snippet"',
    ],
    'reply' => [
        'title' => 'Nouvelle réponse',
        'body' => ':user a répondu à ton commentaire : ":snippet"',
    ],
    'follow' => [
        'title' => 'Nouvel abonné',
        'body' => ':user a commencé à te suivre.',
    ],
    'follow_request' => [
        'title' => 'Demande d\'abonnement',
        'body' => ':user souhaite s\'abonner à ton compte.',
    ],
    'mention' => [
        'title' => 'Mention',
        'body' => ':user t\'a mentionné·e dans une publication ou un commentaire.',
    ],

    // Visit
    'visit_requested' => [
        'title' => 'Demande de visite',
        'body' => 'Nouvelle demande de visite pour :title',
    ],
    'visit_proposed' => [
        'title' => 'Créneau proposé',
        'body' => 'Un nouveau créneau (:time) t\'est proposé pour :title',
    ],
    'visit_confirmed' => [
        'title' => 'Visite confirmée',
        'body' => 'Visite confirmée pour :title',
    ],
    'visit_declined' => [
        'title' => 'Demande déclinée',
        'body' => 'Ta demande de visite pour :title a été déclinée. Tu as été remboursé·e.',
    ],
    'visit_cancelled' => [
        'title' => 'Visite annulée',
        'body' => 'La visite pour :title a été annulée.',
    ],
    'listing_visit_requested' => [
        'title' => 'Demande de visite',
        'body' => 'Nouvelle demande de visite : :user a proposé des créneaux pour :title',
    ],
    'listing_visit_slot_chosen' => [
        'title' => 'Créneau retenu',
        'body' => 'Ton créneau du :time a été retenu pour la visite de :title',
    ],

    // Message
    'new_message' => [
        'title' => 'Nouveau message de :user',
        'voice' => 'Note vocale',
        'photo' => 'Photo',
        'story_reply' => 'Réponse à une story',
        'default' => 'Nouveau message',
    ],
    'new_story_reply' => [
        'title' => ':user a répondu à ta story',
    ],
    'added_to_group' => [
        'title' => 'Nouveau groupe',
        'body' => ':user t\'a ajouté·e au groupe « :group »',
    ],

    // Listing
    'new_matching_listing' => [
        'title' => 'Nouvelle annonce disponible : :title',
    ],
    'listing_decision' => [
        'title' => 'Statut de ton annonce',
        'reject' => 'Ton annonce ":title" a été refusée. Motif : :reason',
        'request_changes' => 'Des modifications sont demandées sur ton annonce ":title". Motif : :reason',
        'unpublish' => 'Ton annonce ":title" a été dépubliée. Motif : :reason',
        'merge' => 'Ton annonce ":title" a été fusionnée avec une annonce similaire. Motif : :reason',
        'default' => 'Mise à jour sur ton annonce ":title".',
    ],
    'listing_fraud' => [
        'title' => 'Alerte fausse annonce',
        'body' => 'L\'annonce « :title » avec laquelle tu as échangé a été retirée pour fausse annonce. Ne verse aucune somme d\'argent.',
    ],

    // KYC
    'kyc_submitted' => [
        'title' => 'Dossier KYC soumis',
        'body' => 'Ton dossier de vérification KYC a été soumis avec succès et est en cours d\'examen.',
    ],
    'kyc_decision' => [
        'title' => 'Vérification d\'identité',
        'approved_name' => 'Ta pièce d\'identité a été validée. Ton profil s\'appelle désormais « :name ».',
        'approved' => 'Ton dossier de vérification d\'identité a été approuvé avec succès.',
        'rejected' => 'Ton dossier de vérification KYC a été refusé. Motif : :reason',
        'changes_requested' => 'Des compléments sont nécessaires pour ton dossier KYC. Motif : :reason',
    ],

    // Boost
    'campaign_activated' => [
        'title' => 'Campagne Boost active',
        'body' => 'Ta campagne de boost est désormais active et en cours de diffusion.',
    ],
    'campaign_completed' => [
        'title' => 'Campagne Boost terminée',
        'body' => 'Ta campagne de boost est terminée. Consulte tes statistiques.',
    ],
    'campaign_suspended' => [
        'title' => 'Campagne Boost suspendue',
        'body' => 'Ta campagne de boost a été suspendue : :reason.',
    ],

    // Subscription
    'renewal_reminder' => [
        'title' => 'Échéance proche (:days j) — :product',
        'body' => 'Ton abonnement :product expire le :date (:amount :currency). Renouvelle manuellement pour conserver ton badge.',
    ],
    'payment_failed' => [
        'title' => 'Échec de paiement',
        'body' => 'Le paiement pour :product a échoué. Régularise ta situation pour conserver ton badge actif.',
    ],

    // Page
    'page_invitation' => [
        'title' => 'Invitation de page',
        'body' => 'Tu as reçu une invitation pour rejoindre la page :page.',
    ],
    'page_member_accepted' => [
        'title' => 'Membre accepté',
        'body' => ':user a accepté l\'invitation à rejoindre la Page.',
    ],
    'page_member_declined' => [
        'title' => 'Invitation refusée',
        'body' => 'L\'invitation pour rejoindre la Page a été refusée.',
    ],
    'page_member_revoked' => [
        'title' => 'Accès révoqué',
        'body' => 'Tes droits sur la Page Professionnelle ont été révoqués avec prise d\'effet immédiate.',
    ],
    'page_suspended' => [
        'title' => 'Page suspendue',
        'body' => 'La Page Professionnelle « :page » a été suspendue.',
    ],
    'page_certification_submitted' => [
        'title' => 'Certification soumise',
        'body' => 'Le dossier de certification de ta Page a été transmis aux équipes d\'examen.',
    ],
    'page_certification_approved' => [
        'title' => 'Page certifiée',
        'body' => 'Félicitations, ta Page Professionnelle a été officiellement certifiée !',
    ],
    'page_certification_rejected' => [
        'title' => 'Certification refusée',
        'body' => 'Le dossier de certification de ta Page a été rejeté.',
    ],
    'page_certification_supplement' => [
        'title' => 'Compléments requis',
        'body' => 'Des informations complémentaires sont requises pour ton dossier de certification.',
    ],

    // Delegation
    'agent_invitation' => [
        'title' => 'Mandat de gestion',
        'body' => 'Nouvelle invitation de mandat reçue',
    ],
    'agent_accepted' => [
        'title' => 'Mandat accepté',
        'body' => 'L\'agent a accepté ton invitation de mandat',
    ],
    'agent_declined' => [
        'title' => 'Mandat décliné',
        'body' => 'L\'agent a décliné ton invitation de mandat',
    ],
    'delegation_revoked' => [
        'title' => 'Mandat révoqué',
        'body' => 'Le mandat a été révoqué',
    ],
    'visit_share_offer' => [
        'title' => 'Nouvelle proposition de frais de visite : :percent%',
        'body' => 'Une proposition de :percent% sur les frais de visite a été émise par :offer_by.',
    ],
    'visit_share_agreed' => [
        'title' => 'Accord sur les frais de visite : :percent%',
        'body' => 'Un accord a été conclu fixant la part de l\'agent sur les frais de visite à :percent%.',
    ],

    // Support & Moderation
    'support_ticket_reply' => [
        'title' => 'Réponse au ticket :reference',
    ],
    'moderation_decision' => [
        'title' => 'Décision de modération',
        'warn' => 'Tu as reçu un avertissement officiel de modération.',
        'suspend' => 'Ton compte a été suspendu jusqu\'au :until suite à un manquement aux règles.',
        'action' => 'Une action de modération a été appliquée sur l\'un de tes contenus.',
    ],
    'moderation_appeal' => [
        'revoked' => 'Ton recours :reference a été accepté et la sanction a été levée.',
        'rejected' => 'Ton recours :reference a été rejeté. Motif : :notes',
    ],

    // Auth & Security
    'neighbourhood_confirmation_required' => [
        'title' => 'Confirme ton quartier',
        'body' => 'Confirme ton quartier depuis ta position actuelle pour accéder à l\'onglet Quartier du Square et afficher la pastille Voisin.',
        'body_with_place' => 'Confirme ton quartier (:neighbourhood) depuis ta position actuelle pour accéder à l\'onglet Quartier du Square et afficher la pastille Voisin.',
    ],
    'unknown_device_reported' => [
        'title' => 'Alerte de sécurité',
        'body' => 'Ton compte a été sécurisé et verrouillé suite au signalement d\'un appareil inconnu.',
    ],
    'unknown_login' => [
        'title' => 'Nouvelle connexion détectée',
        'body' => 'Ton compte vient d\'être connecté sur :device près de :city à :time. Si ce n\'était pas toi, déconnecte cette session.',
    ],
    'device_verification_code' => [
        'title' => 'Code de confirmation',
        'body' => 'Ton code de confirmation d\'appareil est :code.',
    ],

    // Birthday
    'birthday_self' => [
        'title' => 'Joyeux anniversaire !',
        'body' => 'Joyeux anniversaire, :name ! Toute l\'équipe BANGO te souhaite une belle journée.',
    ],
    'birthday_friend' => [
        'title' => 'Anniversaire',
        'body' => 'C\'est l\'anniversaire de :name aujourd\'hui. Souhaite-le-lui !',
    ],

    // Push Notification Categories
    'categories' => [
        'visit' => 'Visite immobilière',
        'new_message' => 'Nouveau message',
        'new_story_reply' => 'Réponse à ta story',
        'added_to_group' => 'Nouveau groupe',
        'kyc' => 'Vérification d\'identité',
        'listing' => 'Annonce immobilière',
        'campaign' => 'Campagne Boost',
        'subscription' => 'Paiement & Abonnement',
        'page' => 'Page professionnelle',
        'delegation' => 'Délégation immobilière',
        'birthday' => 'Anniversaire',
        'default' => 'BANGO',
    ],
];
