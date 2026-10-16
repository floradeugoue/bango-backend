<?php

namespace App\Enums\Boost;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    case Debit = 'debit';
    case Deposit = 'deposit';
    case CampaignDebit = 'campaign_debit';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
    case CampaignRefund = 'campaign_refund';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Achat de Crédits BANGO',
            self::Debit => 'Consommation de crédits',
            self::Deposit => 'Recharge de crédits BANGO',
            self::CampaignDebit => 'Financement de campagne publicitaire',
            self::Bonus => 'Bonus promotionnel',
            self::Adjustment => 'Régularisation comptable',
            self::CampaignRefund => 'Remboursement de campagne',
        };
    }

    /**
     * Indique si l'opération augmente (crédit) ou diminue (débit) le solde.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Purchase, self::Deposit, self::Bonus, self::CampaignRefund => true,
            self::Debit, self::CampaignDebit => false,
            self::Adjustment => true,
        };
    }
}
