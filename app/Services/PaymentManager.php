<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $instances = [];

    /**
     * @param  array<string, class-string<PaymentGatewayInterface>>  $gateways
     */
    public function __construct(private array $gateways = []) {}

    public function resolve(string $code): PaymentGatewayInterface
    {
        if (isset($this->instances[$code])) {
            return $this->instances[$code];
        }

        $class = $this->gateways[$code] ?? null;

        if ($class === null || ! class_exists($class)) {
            throw new InvalidArgumentException("Payment gateway [{$code}] is not registered.");
        }

        // The class-string type guarantees the contract; no runtime check needed.
        return $this->instances[$code] = new $class;
    }

    public function availableCodes(): array
    {
        return array_keys($this->gateways);
    }
}
