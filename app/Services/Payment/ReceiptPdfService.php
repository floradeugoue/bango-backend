<?php

namespace App\Services\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReceiptPdfService
{
    /**
     * Nom officiel unique de l'éditeur (conforme à l'article 1 des CGU).
     */
    public const PUBLISHER_NAME = 'CCEDA Finance';

    /**
     * Mention légale en pied de page.
     */
    public const LEGAL_FOOTER = 'CCEDA Finance — Opérateur de la plateforme immobilière et sociale BANGO.';

    /**
     * Génère un document PDF binaire 1.4 conforme aux spécifications ISO PDF,
     * certifiant le paiement d'une transaction BANGO sans dépendance externe lourde.
     */
    public function generateReceiptPdf(PaymentTransaction $transaction): string
    {
        $ref = $transaction->reference;
        $tz = $this->resolveTimezone(null, $transaction->user);
        $date = $transaction->confirmed_at
            ? $transaction->confirmed_at->copy()->setTimezone($tz)->format('d/m/Y à H:i:s')
            : now()->setTimezone($tz)->format('d/m/Y à H:i:s');

        $currency = $transaction->currency ?? 'XAF';
        $amount = $this->formatCurrency((float) $transaction->total_amount, $currency);

        $clientName = ($transaction->user?->display_name && trim($transaction->user->display_name) !== '')
            ? trim($transaction->user->display_name)
            : ($transaction->user?->name ?? 'Client BANGO');
        $clientEmail = $transaction->user?->email ?? 'N/A';
        $serviceLabel = $this->getServiceLabel($transaction);

        $isWallet = $transaction->channel === PaymentChannel::CreditWallet
            || $transaction->provider === PaymentProvider::Wallet
            || $transaction->provider?->value === 'wallet'
            || $transaction->provider?->value === 'credit';

        $channel = $isWallet ? 'Portefeuille Crédit BANGO' : ($transaction->channel?->value ?? 'Mobile Money');
        $provider = $isWallet ? 'wallet' : ($transaction->provider?->value ?? 'Monetbil');
        $externalRef = $transaction->external_reference ?? 'N/A';
        $signature = strtoupper(substr(hash('sha256', "BANGO:{$ref}:{$transaction->total_amount}:{$transaction->user_id}"), 0, 24));

        $lines = [
            '========================================================================',
            '                             '.self::PUBLISHER_NAME.'                              ',
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
            $isWallet ? "Canal de paiement    : {$channel}" : "Canal de paiement    : {$channel} ({$provider})",
            $isWallet ? "Reference debit      : {$externalRef}" : "Reference operateur  : {$externalRef}",
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
            sprintf('Montant principal    : %s', $this->formatCurrency((float) $transaction->amount, $currency)),
            sprintf('Frais de service     : %s', $this->formatCurrency((float) $transaction->fee_amount, $currency)),
            sprintf('TVA / Taxes          : %s', $this->formatCurrency((float) $transaction->tax_amount, $currency)),
            '------------------------------------------------------------------------',
            "TOTAL DEBITE         : {$amount}",
            '========================================================================',
            '',
            'CERTIFICAT D AUTHENTICITE NUMERIQUE BANGO',
            "Signature numerique  : {$signature}",
            'Ce document tient lieu de justificatif de paiement certifie conforme.',
            self::LEGAL_FOOTER,
            '========================================================================',
        ]);

        return $this->buildPdfBinary($lines, "Recu {$ref}");
    }

    /**
     * Génère le reçu PDF officiel pour une réservation de visite (même réglée par crédits BANGO).
     */
    public function generateVisitReceiptPdf(VisitReservation $reservation): string
    {
        $listing = $reservation->listing;
        $tz = $this->resolveTimezone($listing, $reservation->prospect);

        $ref = $reservation->payment_reference ?? ('VISIT-'.$reservation->id);
        $paidAt = $reservation->paid_at ?? $reservation->created_at ?? now();
        $date = $paidAt->copy()->setTimezone($tz)->format('d/m/Y à H:i:s');

        $fee = (float) $reservation->fee_amount;
        $currency = $reservation->currency ?? 'XAF';
        $amount = $this->formatCurrency($fee, $currency);

        $prospect = $reservation->prospect;
        $prospectName = ($prospect?->display_name && trim($prospect->display_name) !== '')
            ? trim($prospect->display_name)
            : ($prospect?->name ?? 'Client BANGO');
        $prospectEmail = $prospect?->email ?? 'N/A';
        $prospectPhone = $reservation->prospect_phone ?? ($prospect?->phone ?? 'N/A');

        $listingTitle = $listing?->title ?? 'Annonce immobiliere BANGO';
        $listingLoc = trim(($listing?->neighbourhood ? $listing->neighbourhood.', ' : '').($listing?->city ?? ''));

        if ($reservation->slot?->start_at) {
            $slotLocal = $reservation->slot->start_at->copy()->setTimezone($tz);
            $scheduledAt = $slotLocal->format('d/m/Y à H:i');
        } else {
            $scheduledAt = 'Date à convenir';
        }

        $channel = $reservation->paymentTransaction?->channel?->value ?? ($fee > 0 && str_contains((string) $ref, 'CREDIT') ? 'Portefeuille Credits BANGO' : 'Prestation directe');
        $signature = strtoupper(substr(hash('sha256', "BANGO:VISIT:{$reservation->id}:{$fee}:{$reservation->prospect_id}"), 0, 24));
        $statusTranslated = $this->translateVisitStatus($reservation->status);

        $lines = [
            '========================================================================',
            '                             '.self::PUBLISHER_NAME.'                              ',
            '                RECU OFFICIEL DE VISITE IMMOBILIERE                    ',
            '========================================================================',
            '',
            "Reference du recu    : REC-{$ref}",
            "Reservation visite   : {$reservation->id}",
            "Date et heure reglt  : {$date}",
            "Statut reservation   : {$statusTranslated}",
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
            self::LEGAL_FOOTER,
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
        $tz = $this->resolveTimezone(null, $user);
        $userName = ($user->display_name && trim($user->display_name) !== '')
            ? trim($user->display_name)
            : ($user->name ?? 'Client BANGO');
        $userEmail = $user->email ?? 'N/A';
        $generatedAt = now()->setTimezone($tz)->format('d/m/Y à H:i:s');
        $periodLabel = ($from || $to)
            ? sprintf('%s -> %s', $from ? $from->format('d/m/Y') : 'Debut', $to ? $to->format('d/m/Y') : 'A ce jour')
            : 'Toutes les transactions';

        $totalSpent = $transactions->where('status', PaymentStatus::Confirmed)->sum('total_amount');
        $count = $transactions->count();

        $signature = strtoupper(substr(hash('sha256', "BANGO:STATEMENT:{$user->id}:{$count}:{$totalSpent}"), 0, 24));
        $totalFormatted = $this->formatCurrency((float) $totalSpent, 'XAF');

        $lines = [
            '========================================================================',
            '                             '.self::PUBLISHER_NAME.'                              ',
            '                     RELEVE DE TRANSACTIONS BANGO                      ',
            '========================================================================',
            '',
            "Titulaire du compte  : {$userName}",
            "Email                : {$userEmail}",
            "Date d emission      : {$generatedAt}",
            "Periode analysee     : {$periodLabel}",
            "Nombre d operations  : {$count}",
            "Volume total confirme: {$totalFormatted}",
            '',
            '------------------------------------------------------------------------',
            sprintf('%-12s | %-16s | %-16s | %-10s | %s', 'DATE', 'REFERENCE', 'MONTANT', 'STATUT', 'SERVICE'),
            '------------------------------------------------------------------------',
        ];

        foreach ($transactions as $tx) {
            $txDate = $tx->created_at ? $tx->created_at->copy()->setTimezone($tz)->format('d/m/Y') : '-';
            $txRef = substr($tx->reference, 0, 16);
            $txAmount = $this->formatCurrency((float) $tx->total_amount, $tx->currency ?? 'XAF');
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
            self::LEGAL_FOOTER,
            '========================================================================',
        ]);

        return $this->buildPdfBinary($lines, "Releve {$user->id}");
    }

    /**
     * Traduit le statut d'une réservation de visite en français.
     */
    public function translateVisitStatus(VisitReservationStatus|string|null $status): string
    {
        $statusEnum = $status instanceof VisitReservationStatus ? $status : VisitReservationStatus::tryFrom((string) $status);

        return match ($statusEnum) {
            VisitReservationStatus::Confirmed => 'CONFIRMÉE',
            VisitReservationStatus::Visited => 'EFFECTUÉE',
            VisitReservationStatus::Requested => 'EN ATTENTE DE CONFIRMATION',
            VisitReservationStatus::Proposed => 'CRÉNEAU PROPOSÉ',
            VisitReservationStatus::Cancelled => 'ANNULÉE',
            VisitReservationStatus::Declined => 'REFUSÉE',
            VisitReservationStatus::RefundPending => 'REMBOURSEMENT EN COURS',
            VisitReservationStatus::Refunded => 'REMBOURSÉE',
            VisitReservationStatus::Expired => 'EXPIRÉE',
            VisitReservationStatus::PendingPayment => 'EN ATTENTE DE PAIEMENT',
            default => strtoupper((string) ($status?->value ?? $status ?? 'CONFIRMÉE')),
        };
    }

    /**
     * Formate un montant financier selon l'usage local du pays (ex: '2 000 FCFA' pour XAF/XOF).
     */
    public function formatCurrency(float $amount, string $currency): string
    {
        $currencyUpper = strtoupper(trim($currency));
        $displayCurrency = match ($currencyUpper) {
            'XAF', 'XOF' => 'FCFA',
            default => $currencyUpper,
        };

        $hasDecimals = (floor($amount) != $amount);
        $decimals = $hasDecimals ? 2 : 0;
        $formattedAmount = number_format($amount, $decimals, ',', ' ');

        return "{$formattedAmount} {$displayCurrency}";
    }

    /**
     * Résout le fuseau horaire du pays de l'annonce ou de l'utilisateur (Douala par défaut).
     */
    public function resolveTimezone(?Listing $listing = null, ?User $user = null): string
    {
        if ($listing) {
            $city = mb_strtolower((string) $listing->city);
            $countryCode = strtoupper((string) ($listing->country_code ?? $listing->owner?->country_code));

            if ($countryCode === 'CM' || in_array($city, ['douala', 'yaounde', 'yaoundé', 'bafoussam', 'garoua', 'bamenda', 'maroua', 'kribi', 'limbe', 'buea'], true)) {
                return 'Africa/Douala';
            }
            if ($countryCode === 'CI' || in_array($city, ['abidjan', 'yamoussoukro', 'bouake', 'bouaké', 'san-pedro'], true)) {
                return 'Africa/Abidjan';
            }
            if ($countryCode === 'SN' || in_array($city, ['dakar', 'thies', 'thiès', 'saint-louis'], true)) {
                return 'Africa/Dakar';
            }
            if ($countryCode === 'GA' || in_array($city, ['libreville', 'port-gentil'], true)) {
                return 'Africa/Libreville';
            }
            if ($countryCode === 'CG' || in_array($city, ['brazzaville', 'pointe-noire'], true)) {
                return 'Africa/Brazzaville';
            }
            if ($countryCode === 'CD' || in_array($city, ['kinshasa', 'lubumbashi', 'goma'], true)) {
                return 'Africa/Kinshasa';
            }
            if ($countryCode === 'FR' || in_array($city, ['paris', 'lyon', 'marseille'], true)) {
                return 'Europe/Paris';
            }

            $currency = strtoupper((string) ($listing->currency ?? ''));
            if ($currency === 'XAF') {
                return 'Africa/Douala';
            }
            if ($currency === 'XOF') {
                return 'Africa/Abidjan';
            }
            if ($currency === 'EUR') {
                return 'Europe/Paris';
            }
        }

        if ($user) {
            $countryCode = strtoupper((string) $user->country_code);
            if ($countryCode === 'CM') {
                return 'Africa/Douala';
            }
            if ($countryCode === 'CI') {
                return 'Africa/Abidjan';
            }
            if ($countryCode === 'SN') {
                return 'Africa/Dakar';
            }
            if ($countryCode === 'FR') {
                return 'Europe/Paris';
            }
        }

        return 'Africa/Douala';
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
     * Assemble un fichier PDF standard 1.4 pur PHP avec la police DejaVuSans et encodage WinAnsi (UTF-8 compatible).
     *
     * @param  array<int, string>  $textLines
     */
    protected function buildPdfBinary(array $textLines, string $title): string
    {
        $streamContent = "BT\n/F1 9 Tf\n40 790 Td\n13 TL\n";
        foreach ($textLines as $line) {
            // Convertir vers Windows-1252 pour que les accents latins (à, é, è, ê, ô, î, ç, etc.) correspondent aux codes de glyphes WinAnsi
            $converted = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
            $escaped = '';
            $len = strlen($converted);
            for ($i = 0; $i < $len; $i++) {
                $byte = $converted[$i];
                if ($byte === '\\') {
                    $escaped .= '\\\\';
                } elseif ($byte === '(') {
                    $escaped .= '\\(';
                } elseif ($byte === ')') {
                    $escaped .= '\\)';
                } else {
                    $escaped .= $byte;
                }
            }
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
        // 5: Police DejaVuSans avec encodage WinAnsi pour le support UTF-8 / accents latins
        $objects[5] = '<< /Type /Font /Subtype /TrueType /BaseFont /DejaVuSans /Encoding /WinAnsiEncoding >>';

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
