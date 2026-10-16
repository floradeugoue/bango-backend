<?php

namespace App\Enums\Support;

enum TicketCategory: string
{
    case AccountAuth = 'account_auth';
    case PaymentWallet = 'payment_wallet';
    case SubscriptionBadge = 'subscription_badge';
    case RealEstateVisits = 'real_estate_visits';
    case PublishingContent = 'publishing_content';
    case Messaging = 'messaging';
    case ProPages = 'pro_pages';
    case SecurityReport = 'security_report';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AccountAuth => 'Compte et authentification',
            self::PaymentWallet => 'Paiement et portefeuille',
            self::SubscriptionBadge => 'Abonnement et badge',
            self::RealEstateVisits => 'Immobilier et visites',
            self::PublishingContent => 'Publication et contenu',
            self::Messaging => 'Messagerie',
            self::ProPages => 'Pages Pro',
            self::SecurityReport => 'Sécurité et signalement',
            self::Other => 'Autre',
        };
    }
}
