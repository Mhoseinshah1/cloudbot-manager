<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderPricingData implements Arrayable
{
    public function __construct(
        public string $serverTypeId,
        public string $locationId,
        public string $currency = 'EUR',
        public ?float $priceHourly = null,
        public ?float $priceMonthly = null,
        public ?int $includedTraffic = null,
        public ?float $pricePerTbTraffic = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            serverTypeId: $data['server_type_id'],
            locationId: $data['location_id'],
            currency: $data['currency'] ?? 'EUR',
            priceHourly: isset($data['price_hourly']) ? (float) $data['price_hourly'] : null,
            priceMonthly: isset($data['price_monthly']) ? (float) $data['price_monthly'] : null,
            includedTraffic: $data['included_traffic'] ?? null,
            pricePerTbTraffic: isset($data['price_per_tb_traffic']) ? (float) $data['price_per_tb_traffic'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'server_type_id' => $this->serverTypeId,
            'location_id' => $this->locationId,
            'currency' => $this->currency,
            'price_hourly' => $this->priceHourly,
            'price_monthly' => $this->priceMonthly,
            'included_traffic' => $this->includedTraffic,
            'price_per_tb_traffic' => $this->pricePerTbTraffic,
        ];
    }
}
