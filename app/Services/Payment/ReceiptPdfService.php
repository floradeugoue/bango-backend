<?php

namespace App\Services\Payment;

use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReceiptPdfService
{
    /**
     * Génère un document PDF binaire 1.4 conforme aux spécifications ISO PDF,
     * certifiant le paiement d'une transaction BANGO sans dépendance externe lourde.
     */
    public function generateReceiptPdf(PaymentTransaction $transaction): string
    {
        $ref = $transaction->reference;
        $date = $transaction->confirmed_at ? $transaction->confirmed_at->format('d/m/Y H:i:s') : now()->format('d/m/Y H:i:s');
        $amount = number_format((float) $transaction->total_amount, 2, ',', ' ').' '.$transaction->currency;
        $clientName = $transaction->user?->name ?? 'Client BANGO';
        $clientEmail = $transaction->user?->email ?? 'N/A';
        $serviceLabel = $this->getServiceLabel($transaction);
        $channel = $transaction->channel?->value ?? 'Mobile Money';
        $provider = $transaction->provider?->value ?? 'Monetbil';
        $externalRef = $transaction->external_reference ?? 'N/A';
        $signature = strtoupper(substr(hash('sha256', "BANGO:{$ref}:{$transaction->total_amount}:{$transaction->user_id}"), 0, 24));

        $lines = [
            '========================================================================',
            '                        BANGO TECHNOLOGIES LTD                         ',
            '                     RECU OFFICIEL DE TRANSACTION                      ',
            '========================================================================',
            '',
            "Reference du recu    : REC-{$ref}",
            "Reference transaction: {$ref}",
            "Date et heure        : {$date}",
            'Statut du reglement  : CONFIRME / PAYE',
            '',
            '------------------------------------------------------------------------',
            'INFORMATIONS DU CLIENT',
            '------------------------------------------------------------------------',
            "Nom et Prenom        : {$clientName}",
            "Email                : {$clientEmail}",
            '',
            '------------------------------------------------------------------------',
            'DETAILS DE LA PRESTATION',
            '------------------------------------------------------------------------',
            "Type de service      : {$serviceLabel}",
            "Canal de paiement    : {$channel} ({$provider})",
            "Reference operateur  : {$externalRef}",
        ];

        // Pour les abonnements, mention de la période couverte
        if ($transaction->service_type === PaymentServiceType::Subscription || $transaction->subscription_id) {
            $periodStart = $transaction->subscription?->current_period_start
                ?? (isset($transaction->metadata['period_start']) ? Carbon::parse($transaction->metadata['period_start']) : null)
                ?? $transaction->confirmed_at
                ?? $transaction->created_at;

            $periodEnd = $transaction->subscription?->current_period_end
                ?? (isset($transaction->metadata['period_end']) ? Carbon::parse($transaction->metadata['period_end']) : null)
                ?? ($periodStart ? $periodStart->copy()->addMonth() : null);

            if ($periodStart && $periodEnd) {
                $lines[] = sprintf('Periode couverte     : %s -> %s', $periodStart->format('d/m/Y'), $periodEnd->format('d/m/Y'));
            }
        }

        $lines = array_merge($lines, [
            '',
            '------------------------------------------------------------------------',
            'VENTILATION FINANCIERE',
            '------------------------------------------------------------------------',
            sprintf('Montant principal    : %s %s', number_format((float) $transaction->amount, 2, ',', ' '), $transaction->currency),
            sprintf('Frais de service     : %s %s', number_format((float) $transaction->fee_amount, 2, ',', ' '), $transaction->currency),
            sprintf('TVA / Taxes          : %s %s', number_format((float) $transaction->tax_amount, 2, ',', ' '), $transaction->currency),
            '------------------------------------------------------------------------',
            "TOTAL DEBITE         : {$amount}",
            '========================================================================',
            '',
            'CERTIFICAT D AUTHENTICITE NUMERIQUE BANGO',
            "Signature numerique  : {$signature}",
            'Ce document tient lieu de justificatif de paiement certifie conforme.',
            'BANGO SAS - Plateforme de mise en relation immobiliere et sociale.',
            '========================================================================',
        ]);

        return $this->buildPdfBinary($lines, "Recu {$ref}");
    }

    /**
     * Génère le reçu PDF officiel pour une réservation de visite (même réglée par crédits BANGO).
     */
    public function generateVisitReceiptPdf(VisitReservation $reservation): string
    {
        $ref = $reservation->payment_reference ?? ('VISIT-'.$reservation->id);
        $date = $reservation->paid_at ? $reservation->paid_at->format('d/m/Y H:i:s') : ($reservation->created_at ? $reservation->created_at->format('d/m/Y H:i:s') : now()->format('d/m/Y H:i:s'));
        $fee = (float) $reservation->fee_amount;
        $amount = number_format($fee, 2, ',', ' ').' '.$reservation->currency;
        $prospectName = $reservation->prospect?->name ?? 'Client BANGO';
        $prospectEmail = $reservation->prospect?->email ?? 'N/A';
        $prospectPhone = $reservation->prospect_phone ?? ($reservation->prospect?->phone ?? 'N/A');

        $listing = $reservation->listing;
        $listingTitle = $listing?->title ?? 'Annonce immobiliere BANGO';
        $listingLoc = trim(($listing?->neighbourhood ? $listing->neighbourhood.', ' : '').($listing?->city ?? ''));
        $scheduledAt = $reservation->slot?->start_at ? $reservation->slot->start_at->format('d/m/Y a H:i') : 'Date a convenir';

        $channel = $reservation->paymentTransaction?->channel?->value ?? ($fee > 0 && str_contains($ref, 'CREDIT') ? 'Portefeuille Credits BANGO' : 'Prestation directe');
        $signature = strtoupper(substr(hash('sha256', "BANGO:VISIT:{$reservation->id}:{$fee}:{$reservation->prospect_id}"), 0, 24));

        $lines = [
            '========================================================================',
            '                        BANGO TECHNOLOGIES LTD                         ',
            '                RECU OFFICIEL DE VISITE IMMOBILIERE                    ',
            '========================================================================',
            '',
            "Reference du recu    : REC-{$ref}",
            "Reservation visite   : {$reservation->id}",
            "Date et heure reglt  : {$date}",
            "Statut reservation   : {$reservation->status->value}",
            '',
            '------------------------------------------------------------------------',
            'INFORMATIONS DU PROSPECT',
            '------------------------------------------------------------------------',
            "Nom et Prenom        : {$prospectName}",
            "Telephone            : {$prospectPhone}",
            "Email                : {$prospectEmail}",
            '',
            '------------------------------------------------------------------------',
            'DETAILS DU BIEN ET DU CRENEAU',
            '------------------------------------------------------------------------',
            "Bien concerne        : {$listingTitle}",
            "Localisation         : {$listingLoc}",
            "Creneau programme    : {$scheduledAt}",
            "Mode de reglement    : {$channel}",
            '',
            '------------------------------------------------------------------------',
            'VENTILATION FINANCIERE',
            '------------------------------------------------------------------------',
            "Frais de visite      : {$amount}",
            '------------------------------------------------------------------------',
            "TOTAL ACQUITTE       : {$amount}",
            '========================================================================',
            '',
            'CERTIFICAT D AUTHENTICITE NUMERIQUE BANGO',
            "Signature numerique  : {$signature}",
            'Ce document tient lieu de justificatif officiel de reservation de visite.',
            'BANGO SAS - Plateforme de mise en relation immobiliere et sociale.',
            '========================================================================',
        ];

        return $this->buildPdfBinary($lines, "Recu Visite {$reservation->id}");
    }

    /**
     * Génère un relevé financier de transactions (statement.pdf) signé numériquement.
     *
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    public function generateStatementPdf(User $user, Collection $transactions, ?Carbon $from = null, ?Carbon $to = null): string
    {
        $userName = $user->name ?? 'Client BANGO';
        $userEmail = $user->email ?? 'N/A';
        $generatedAt = now()->format('d/m/Y H:i:s');
        $periodLabel = ($from || $to)
            ? sprintf('%s -> %s', $from ? $from->format('d/m/Y') : 'Debut', $to ? $to->format('d/m/Y') : 'A ce jour')
            : 'Toutes les transactions';

        $totalSpent = $transactions->where('status', PaymentStatus::Confirmed)->sum('total_amount');
        $count = $transactions->count();

        $signature = strtoupper(substr(hash('sha256', "BANGO:STATEMENT:{$user->id}:{$count}:{$totalSpent}"), 0, 24));

        $lines = [
            '========================================================================',
            '                        BANGO TECHNOLOGIES LTD                         ',
            '                     RELEVE DE TRANSACTIONS BANGO                      ',
            '========================================================================',
            '',
            "Titulaire du compte  : {$userName}",
            "Email                : {$userEmail}",
            "Date d emission      : {$generatedAt}",
            "Periode analysee     : {$periodLabel}",
            "Nombre d operations  : {$count}",
            sprintf('Volume total confirme: %s XAF', number_format((float) $totalSpent, 2, ',', ' ')),
            '',
            '------------------------------------------------------------------------',
            sprintf('%-12s | %-16s | %-16s | %-10s | %s', 'DATE', 'REFERENCE', 'MONTANT', 'STATUT', 'SERVICE'),
            '------------------------------------------------------------------------',
        ];

        foreach ($transactions as $tx) {
            $txDate = $tx->created_at ? $tx->created_at->format('d/m/Y') : '-';
            $txRef = substr($tx->reference, 0, 16);
            $txAmount = sprintf('%s %s', number_format((float) $tx->total_amount, 0, ',', ' '), $tx->currency ?? 'XAF');
            $txStatus = substr($tx->status?->value ?? (string) $tx->status, 0, 10);
            $txService = substr($tx->service_type?->value ?? (string) $tx->service_type, 0, 15);

            $lines[] = sprintf('%-12s | %-16s | %-16s | %-10s | %s', $txDate, $txRef, $txAmount, $txStatus, $txService);
        }

        if ($transactions->isEmpty()) {
            $lines[] = 'Aucune transaction enregistree pour les criteres selectionnes.';
        }

        $lines = array_merge($lines, [
            '------------------------------------------------------------------------',
            '',
            'CERTIFICAT D AUTHENTICITE NUMERIQUE BANGO',
            "Signature numerique  : {$signature}",
            'Ce releve officiel certifie l historique des operations du compte.',
            'BANGO SAS - Plateforme de mise en relation immobiliere et sociale.',
            '========================================================================',
        ]);

        return $this->buildPdfBinary($lines, "Releve {$user->id}");
    }

    /**
     * Libellé humain du type de service.
     */
    protected function getServiceLabel(PaymentTransaction $transaction): string
    {
        $type = $transaction->service_type?->value ?? (string) $transaction->service_type;

        return match ($type) {
            'visit', 'visit_reservation' => 'Frais de reservation de visite immobiliere',
            'subscription' => 'Abonnement Professionnel BANGO',
            'boost', 'campaign' => 'Sponsoring et visibilite de contenu (Boost)',
            'wallet_recharge', 'credit' => 'Recharge de portefeuille de credits BANGO',
            default => 'Prestation de service BANGO ('.ucfirst($type).')',
        };
    }

    /**
     * Assemble un fichier PDF standard 1.4 pur PHP.
     *
     * @param  array<int, string>  $textLines
     */
    protected function buildPdfBinary(array $textLines, string $title): string
    {
        $streamContent = "BT\n/F1 9 Tf\n40 790 Td\n13 TL\n";
        foreach ($textLines as $line) {
            // Échapper les parenthèses et backslash pour les chaînes de texte PDF
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $streamContent .= "({$escaped}) '\n";
        }
        $streamContent .= "ET\n";

        $streamLength = strlen($streamContent);

        $objects = [];
        // 1: Catalog
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        // 2: Pages
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        // 3: Page
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
        // 4: Contents Stream
        $objects[4] = "<< /Length {$streamLength} >>\nstream\n{$streamContent}endstream";
        // 5: Font Courier
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($objects as $num => $obj) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}
