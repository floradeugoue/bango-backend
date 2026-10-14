<?php

namespace App\Services\Payment;

use App\Exceptions\Payment\UnsupportedGatewayException;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\MonetbilGateway;
use Closure;
use Illuminate\Contracts\Foundation\Application;

class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $drivers = [];

    /**
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    public function __construct(protected Application $app) {}

    /**
     * Résout le driver de passerelle demandé ou le driver configuré par défaut.
     *
     * @throws UnsupportedGatewayException
     */
    public function driver(?string $driver = null): PaymentGatewayInterface
    {
        $name = $driver ?? config('payment.default_gateway', 'monetbil');

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        return $this->drivers[$name] = $this->createDriver($name);
    }

    /**
     * Crée une instance du driver avec validation stricte de son activation.
     *
     * @throws UnsupportedGatewayException
     */
    protected function createDriver(string $name): PaymentGatewayInterface
    {
        if (isset($this->customCreators[$name])) {
            $instance = ($this->customCreators[$name])($this->app);
            if (! $instance instanceof PaymentGatewayInterface) {
                throw new UnsupportedGatewayException($name);
            }

            return $instance;
        }

        $config = config("payment.gateways.{$name}");

        // Refus formel si la passerelle n'existe pas dans la configuration ou est désactivée
        if (! is_array($config) || empty($config['enabled'])) {
            throw new UnsupportedGatewayException($name);
        }

        return match ($name) {
            'monetbil' => new MonetbilGateway,
            default => throw new UnsupportedGatewayException($name),
        };
    }

    /**
     * Enregistre un résolveur personnalisé (utile pour les tests et futures passerelles).
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->customCreators[$driver] = $callback;
        unset($this->drivers[$driver]);

        return $this;
    }

    /**
     * Réinitialise les drivers instanciés (utile en environnement de test).
     */
    public function forgetDrivers(): void
    {
        $this->drivers = [];
        $this->customCreators = [];
    }

    /**
     * Proxy dynamique vers le driver par défaut.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}
