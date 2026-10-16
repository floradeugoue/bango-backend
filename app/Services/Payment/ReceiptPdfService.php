<?php

namespace App\Services\Payment;

use App\Models\Payment\PaymentTransaction;

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
        ];

        return $this->buildPdfBinary($lines, "Recu {$ref}");
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
        $streamContent = "BT\n/F1 10 Tf\n40 780 Td\n14 TL\n";
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
