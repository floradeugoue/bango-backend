<?php

return [
    // Social
    'like' => [
        'title' => 'Nouveau j\'aime',
        'body' => ':user a aimé votre publication.',
    ],
    'story_like' => [
        'title' => 'Nouveau j\'aime',
        'body' => ':user a aimé votre story.',
    ],
    'story_question_answer' => [
        'title' => 'Réponse à votre question',
        'body' => ':user a répondu à votre question : ":snippet"',
    ],
    'countdown_remind' => [
        'title' => 'Rappel d\'événement',
        'body' => 'Rappel activé pour le compte à rebours.',
    ],
    'comment' => [
        'title' => 'Nouveau commentaire',
        'body' => ':user a commenté votre publication : ":snippet"',
    ],
    'reply' => [
        'title' => 'Nouvelle réponse',
        'body' => ':user a répondu à votre commentaire : ":snippet"',
    ],
    'follow' => [
        'title' => 'Nouvel abonné',
        'body' => ':user a commencé à vous suivre.',
    ],
    'follow_request' => [
        'title' => 'Demande d\'abonnement',
        'body' => ':user souhaite s\'abonner à votre compte.',
    ],
    'mention' => [
        'title' => 'Mention',
        'body' => ':user vous a mentionné dans une publication ou un commentaire.',
    ],

    // Visit
    'visit_requested' => [
        'title' => 'Demande de visite',
        'body' => 'Nouvelle demande de visite pour :title',
    ],
    'visit_proposed' => [
        'title' => 'Créneau proposé',
        'body' => 'Un nouveau créneau (:time) vous est proposé pour :title',
    ],
    'visit_confirmed' => [
        'title' => 'Visite confirmée',
        'body' => 'Visite confirmée pour :title',
    ],
    'visit_declined' => [
        'title' => 'Demande déclinée',
        'body' => 'Votre demande de visite pour :title a été déclinée. Les frais ont été remboursés.',
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
        'body' => 'Votre créneau du :time a été retenu pour la visite de :title',
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
        'title' => ':user a répondu à votre story',
    ],
    'added_to_group' => [
        'title' => 'Nouveau groupe',
        'body' => ':user vous a ajouté au groupe « :group »',
    ],

    // Listing
    'new_matching_listing' => [
        'title' => 'Nouvelle annonce disponible : :title',
    ],
    'listing_decision' => [
        'title' => 'Statut de votre annonce',
        'reject' => 'Votre annonce ":title" a été refusée. Motif : :reason',
        'request_changes' => 'Des modifications sont demandées sur votre annonce ":title". Motif : :reason',
        'unpublish' => 'Votre annonce ":title" a été dépubliée. Motif : :reason',
        'merge' => 'Votre annonce ":title" a été fusionnée avec une annonce similaire. Motif : :reason',
        'default' => 'Mise à jour sur votre annonce ":title".',
    ],

    // KYC
    'kyc_submitted' => [
        'title' => 'Dossier KYC soumis',
        'body' => 'Votre dossier de vérification KYC a été soumis avec succès et est en cours d\'examen.',
    ],
    'kyc_decision' => [
        'title' => 'Vérification d\'identité',
        'approved_name' => 'Votre pièce d\'identité a été validée. Votre profil s\'appelle désormais « :name ».',
        'approved' => 'Votre dossier de vérification d\'identité a été approuvé avec succès.',
        'rejected' => 'Votre dossier de vérification KYC a été refusé. Motif : :reason',
        'changes_requested' => 'Des compléments sont nécessaires pour votre dossier KYC. Motif : :reason',
    ],

    // Boost
    'campaign_activated' => [
        'title' => 'Campagne Boost active',
        'body' => 'Votre campagne de boost est désormais active et en cours de diffusion.',
    ],
    'campaign_completed' => [
        'title' => 'Campagne Boost terminée',
        'body' => 'Votre campagne de boost est terminée. Consultez vos statistiques.',
    ],
    'campaign_suspended' => [
        'title' => 'Campagne Boost suspendue',
        'body' => 'Votre campagne de boost a été suspendue : :reason.',
    ],

    // Subscription
    'renewal_reminder' => [
        'title' => 'Échéance proche (:days j) — :product',
        'body' => 'Votre abonnement :product expire le :date (:amount :currency). Renouvelez manuellement pour conserver votre badge.',
    ],
    'payment_failed' => [
        'title' => 'Échec de paiement',
        'body' => 'Le paiement pour :product a échoué. Régularisez votre situation pour conserver votre badge actif.',
    ],

    // Page
    'page_invitation' => [
        'title' => 'Invitation de page',
        'body' => 'Vous avez reçu une invitation pour rejoindre la page :page.',
    ],
    'page_member_accepted' => [
        'title' => 'Membre accepté',
        'body' => 'Un utilisateur a accepté l\'invitation à rejoindre la Page.',
    ],
    'page_member_declined' => [
        'title' => 'Invitation refusée',
        'body' => 'L\'invitation pour rejoindre la Page a été refusée.',
    ],
    'page_member_revoked' => [
        'title' => 'Accès révoqué',
        'body' => 'Vos droits sur la Page Professionnelle ont été révoqués avec prise d\'effet immédiate.',
    ],
    'page_suspended' => [
        'title' => 'Page suspendue',
        'body' => 'La Page Professionnelle « :page » a été suspendue.',
    ],
    'page_certification_submitted' => [
        'title' => 'Certification soumise',
        'body' => 'Le dossier de certification de votre Page a été transmis aux équipes d\'examen.',
    ],
    'page_certification_approved' => [
        'title' => 'Page certifiée',
        'body' => 'Félicitations, votre Page Professionnelle a été officiellement certifiée !',
    ],
    'page_certification_rejected' => [
        'title' => 'Certification refusée',
        'body' => 'Le dossier de certification de votre Page a été rejeté.',
    ],
    'page_certification_supplement' => [
        'title' => 'Compléments requis',
        'body' => 'Des informations complémentaires sont requises pour votre dossier de certification.',
    ],

    // Delegation
    'agent_invitation' => [
        'title' => 'Mandat de gestion',
        'body' => 'Nouvelle invitation de mandat reçue',
    ],
    'agent_accepted' => [
        'title' => 'Mandat accepté',
        'body' => 'L\'agent a accepté votre invitation de mandat',
    ],
    'agent_declined' => [
        'title' => 'Mandat décliné',
        'body' => 'L\'agent a décliné votre invitation de mandat',
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
        'warn' => 'Vous avez reçu un avertissement officiel de modération.',
        'suspend' => 'Votre compte a été suspendu jusqu\'au :until suite à un manquement aux règles.',
        'action' => 'Une action de modération a été appliquée sur l\'un de vos contenus.',
    ],
    'moderation_appeal' => [
        'revoked' => 'Votre recours :reference a été accepté et la sanction a été levée.',
        'rejected' => 'Votre recours :reference a été rejeté. Motif : :notes',
    ],

    // Auth & Security
    'unknown_device_reported' => [
        'title' => 'Alerte de sécurité',
        'body' => 'Votre compte a été sécurisé et verrouillé suite au signalement d\'un appareil inconnu.',
    ],
    'device_verification_code' => [
        'title' => 'Code de confirmation',
        'body' => 'Votre code de confirmation d\'appareil est :code.',
    ],

    // Push Notification Categories
    'categories' => [
        'visit' => 'Visite immobilière',
        'new_message' => 'Nouveau message',
        'new_story_reply' => 'Réponse à votre story',
        'added_to_group' => 'Nouveau groupe',
        'kyc' => 'Vérification d\'identité',
        'listing' => 'Annonce immobilière',
        'campaign' => 'Campagne Boost',
        'subscription' => 'Paiement & Abonnement',
        'page' => 'Page professionnelle',
        'delegation' => 'Délégation immobilière',
        'default' => 'BANGO',
    ],
];
