<?php

namespace App\Services\Payment;

use App\Enums\Payment\PaymentServiceType;
use App\Exceptions\Payment\InvalidPriceException;
use App\Exceptions\Payment\MissingPricingConfigurationException;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Services\Payment\DTO\PriceQuote;
use Illuminate\Support\Carbon;

class PricingService
{
    /**
     * Calcule le devis précis d'une prestation financière avec décomposition des frais et taxes.
     *
     * @throws InvalidPriceException
     * @throws MissingPricingConfigurationException
     */
    public function calculateQuote(
        PaymentServiceType $serviceType,
        ?int $priceId = null,
        ?float $customAmount = null,
        ?string $gateway = null
    ): PriceQuote {
        $gatewayName = $gateway ?? config('payment.default_gateway', 'monetbil');

        // 1. Détermination du produit, du tarif catalogue et du montant de base
        [$product, $price, $baseAmount, $currency] = $this->resolveBaseProductAndAmount(
            $serviceType,
            $priceId,
            $customAmount
        );

        // 2. Calcul des frais de passerelle (facturés en sus au client)
        $feeAmount = $this->calculateGatewayFee($baseAmount, $gatewayName);

        // 3. Calcul des taxes éventuelles
        $taxAmount = $this->calculateTax($baseAmount);

        // 4. Calcul du montant total final
        $totalAmount = PaymentTransaction::calculateTotal($baseAmount, $feeAmount, $taxAmount);

        // 5. Construction de la décomposition et de la photographie immuable
        $breakdown = [
            'base_price' => (float) $baseAmount,
            'gateway_fee' => (float) $feeAmount,
            'tax' => (float) $taxAmount,
            'total' => (float) $totalAmount,
            'currency' => $currency,
            'billed_to_customer' => true,
        ];

        $snapshot = [
            'pricing_version' => '1.0',
            'service_type' => $serviceType->value,
            'product_id' => $product?->id,
            'product_code' => $product?->code,
            'price_id' => $price?->id,
            'cadence' => $price?->cadence,
            'duration_days' => $price?->duration_days,
            'base_amount' => $baseAmount,
            'currency' => $currency,
            'fee_amount' => $feeAmount,
            'gateway' => $gatewayName,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'quoted_at' => Carbon::now()->toIso8601String(),
        ];

        return new PriceQuote(
            serviceType: $serviceType,
            product: $product,
            price: $price,
            currency: $currency,
            amount: $baseAmount,
            feeAmount: $feeAmount,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            gateway: $gatewayName,
            breakdown: $breakdown,
            snapshot: $snapshot
        );
    }

    /**
     * Résout l'objet catalogue et le montant de base correspondant au type de service.
     *
     * @return array{0: ?SubscriptionProduct, 1: ?SubscriptionPrice, 2: string, 3: string}
     *
     * @throws InvalidPriceException
     */
    protected function resolveBaseProductAndAmount(
        PaymentServiceType $serviceType,
        ?int $priceId,
        ?float $customAmount
    ): array {
        return match ($serviceType) {
            PaymentServiceType::Subscription => $this->resolveSubscriptionPrice($priceId),
            PaymentServiceType::Certification => $this->resolveCertificationPrice($priceId),
            PaymentServiceType::Boost => $this->resolveBoostPrice($priceId),
            PaymentServiceType::Visit => $this->resolveVisitPrice($customAmount),
            PaymentServiceType::Credit => $this->resolveCreditPrice($customAmount),
        };
    }

    /**
     * @return array{0: SubscriptionProduct, 1: SubscriptionPrice, 2: string, 3: string}
     */
    protected function resolveSubscriptionPrice(?int $priceId): array
    {
        if ($priceId === null) {
            throw new InvalidPriceException('Un identifiant de tarif (price_id) est obligatoire pour les abonnements.');
        }

        /** @var SubscriptionPrice|null $price */
        $price = SubscriptionPrice::with('product')->find($priceId);

        if (! $price || ! $price->is_active) {
            throw new InvalidPriceException(sprintf('Le tarif ID %d est introuvable ou inactif.', $priceId));
        }

        if (! $price->product || ! $price->product->is_active) {
            throw new InvalidPriceException('Le produit d\'abonnement associé à ce tarif est inactif.');
        }

        $now = Carbon::now();
        if ($price->effective_from && $price->effective_from->isAfter($now)) {
            throw new InvalidPriceException('Ce tarif n\'est pas encore effectif.');
        }

        if ($price->effective_until && $price->effective_until->isBefore($now)) {
            throw new InvalidPriceException('Ce tarif a expiré.');
        }

        return [
            $price->product,
            $price,
            number_format((float) $price->price_amount, 2, '.', ''),
            $price->currency ?: config('payment.currency', 'XAF'),
        ];
    }

    /**
     * @return array{0: SubscriptionProduct, 1: SubscriptionPrice, 2: string, 3: string}
     */
    protected function resolveCertificationPrice(?int $priceId): array
    {
        if ($priceId !== null) {
            return $this->resolveSubscriptionPrice($priceId);
        }

        $product = SubscriptionProduct::where('code', 'page_pro')->first();
        if (! $product || ! $product->is_active) {
            throw new InvalidPriceException('Le produit de certification Page Pro est introuvable ou inactif.');
        }

        $price = $product->prices()->where('is_active', true)->first();
        if (! $price) {
            throw new InvalidPriceException('Aucun tarif actif n\'est configuré pour la certification Page Pro.');
        }

        return [
            $product,
            $price,
            number_format((float) $price->price_amount, 2, '.', ''),
            $price->currency ?: config('payment.currency', 'XAF'),
        ];
    }

    /**
     * @return array{0: SubscriptionProduct, 1: SubscriptionPrice, 2: string, 3: string}
     */
    protected function resolveBoostPrice(?int $priceId): array
    {
        if ($priceId !== null) {
            return $this->resolveSubscriptionPrice($priceId);
        }

        $product = SubscriptionProduct::where('code', 'listing_boost')->first();
        if (! $product || ! $product->is_active) {
            throw new InvalidPriceException('Le produit de boost d\'annonce est introuvable ou inactif.');
        }

        $price = $product->prices()->where('is_active', true)->first();
        if (! $price) {
            throw new InvalidPriceException('Aucun tarif actif n\'est configuré pour le boost d\'annonce.');
        }

        return [
            $product,
            $price,
            number_format((float) $price->price_amount, 2, '.', ''),
            $price->currency ?: config('payment.currency', 'XAF'),
        ];
    }

    /**
     * @return array{0: null, 1: null, 2: string, 3: string}
     */
    protected function resolveVisitPrice(?float $customAmount): array
    {
        if ($customAmount === null || $customAmount <= 0) {
            throw new InvalidPriceException('Le montant des frais de visite doit être spécifié et strictement supérieur à zéro.');
        }

        return [
            null,
            null,
            number_format($customAmount, 2, '.', ''),
            config('payment.currency', 'XAF'),
        ];
    }

    /**
     * @return array{0: null, 1: null, 2: string, 3: string}
     */
    protected function resolveCreditPrice(?float $customAmount): array
    {
        if ($customAmount === null || $customAmount < 100) {
            throw new InvalidPriceException('Le montant d\'achat de crédits doit être spécifié et d\'au moins 100 FCFA.');
        }

        return [
            null,
            null,
            number_format($customAmount, 2, '.', ''),
            config('payment.currency', 'XAF'),
        ];
    }

    /**
     * Calcule les frais de passerelle selon la configuration explicite.
     *
     * @throws MissingPricingConfigurationException
     */
    public function calculateGatewayFee(string $baseAmount, string $gateway): string
    {
        $config = config("payment.gateways.{$gateway}");

        if (! is_array($config)) {
            throw new MissingPricingConfigurationException(
                sprintf('La passerelle "%s" n\'est pas déclarée dans la configuration.', $gateway)
            );
        }

        if (! array_key_exists('fee_percentage', $config) || $config['fee_percentage'] === null) {
            throw new MissingPricingConfigurationException(
                sprintf('Le taux de frais de la passerelle "%s" n\'est pas configuré.', $gateway)
            );
        }

        $base = (float) $baseAmount;
        $percentage = (float) $config['fee_percentage'];
        $fixed = (float) ($config['fee_fixed'] ?? 0.0);
        $minFee = (float) ($config['min_fee'] ?? 0.0);
        $maxFee = isset($config['max_fee']) && $config['max_fee'] !== null ? (float) $config['max_fee'] : null;

        $fee = ($base * $percentage / 100.0) + $fixed;

        if ($minFee > 0 && $fee < $minFee) {
            $fee = $minFee;
        }

        if ($maxFee !== null && $maxFee > 0 && $fee > $maxFee) {
            $fee = $maxFee;
        }

        return number_format(round($fee, 2), 2, '.', '');
    }

    /**
     * Calcule la taxe applicable sur le montant de base.
     */
    public function calculateTax(string $baseAmount): string
    {
        $enabled = (bool) config('payment.tax.enabled', false);

        if (! $enabled) {
            return '0.00';
        }

        $rate = (float) config('payment.tax.rate_percentage', 0.0);
        $tax = (float) $baseAmount * ($rate / 100.0);

        return number_format(round($tax, 2), 2, '.', '');
    }

    /**
     * Retourne le catalogue complet des offres commercialisables et prestations payantes.
     *
     * @return array<string, mixed>
     */
    public function getCatalog(?string $gateway = null): array
    {
        $gatewayName = $gateway ?? config('payment.default_gateway', 'monetbil');
        $currency = config('payment.currency', 'XAF');

        $products = SubscriptionProduct::with(['prices' => function ($query) {
            $query->where('is_active', true)->orderBy('duration_days');
        }])->where('is_active', true)->get();

        $offers = [];

        foreach ($products as $product) {
            $isSubscription = in_array($product->code, ['landlord', 'agent'], true);
            $serviceType = match ($product->code) {
                'landlord', 'agent' => PaymentServiceType::Subscription->value,
                'page_pro' => PaymentServiceType::Certification->value,
                'listing_boost' => PaymentServiceType::Boost->value,
                default => PaymentServiceType::Subscription->value,
            };

            $isPurchasable = $isSubscription;
            $availabilityStatus = $isPurchasable ? 'available' : 'coming_soon';

            $priceItems = [];
            foreach ($product->prices as $price) {
                $baseAmount = number_format((float) $price->price_amount, 2, '.', '');
                $feeAmount = $this->calculateGatewayFee($baseAmount, $gatewayName);
                $taxAmount = $this->calculateTax($baseAmount);
                $totalAmount = PaymentTransaction::calculateTotal($baseAmount, $feeAmount, $taxAmount);

                $priceItems[] = [
                    'price_id' => $price->id,
                    'cadence' => $price->cadence,
                    'duration_days' => $price->duration_days,
                    'base_amount' => (float) $baseAmount,
                    'fee_amount' => (float) $feeAmount,
                    'tax_amount' => (float) $taxAmount,
                    'total_amount' => (float) $totalAmount,
                    'currency' => $price->currency ?: $currency,
                    'savings_description' => $price->savings_description,
                    'is_active' => (bool) $price->is_active,
                ];
            }

            $offers[] = [
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'description' => $product->description,
                'service_type' => $serviceType,
                'badge_type' => $product->badge_type,
                'is_purchasable' => $isPurchasable,
                'availability_status' => $availabilityStatus,
                'prices' => $priceItems,
            ];
        }

        $services = [
            [
                'service_type' => PaymentServiceType::Visit->value,
                'code' => 'visit',
                'name' => 'Frais de Visite',
                'description' => 'Règlement sécurisé des frais de visite d\'un bien immobilier.',
                'pricing_model' => 'custom',
                'currency' => $currency,
                'is_purchasable' => true,
                'availability_status' => 'available',
                'min_amount' => 500.0,
                'max_amount' => 50000.0,
            ],
        ];

        return [
            'currency' => $currency,
            'gateway' => $gatewayName,
            'offers' => $offers,
            'services' => $services,
            'fee_policy' => [
                'type' => 'billed_to_customer',
                'description' => 'Les frais d\'opérateur Mobile Money sont calculés et affichés en sus avant la confirmation.',
            ],
        ];
    }
}
