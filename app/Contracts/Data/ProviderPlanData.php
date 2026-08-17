<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderPlanData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public int $vcpu,
        public int $ramMb,
        public int $diskGb,
        public ?int $bandwidthGb = null,
        public float $priceMonthly = 0.0,
        public string $currency = 'EUR',
        public ?float $priceHourly = null,
        public ?string $description = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            vcpu: $data['vcpu'],
            ramMb: $data['ram_mb'],
            diskGb: $data['disk_gb'],
            bandwidthGb: $data['bandwidth_gb'] ?? null,
            priceMonthly: (float) ($data['price_monthly'] ?? 0),
            currency: $data['currency'] ?? 'EUR',
            priceHourly: isset($data['price_hourly']) ? (float) $data['price_hourly'] : null,
            description: $data['description'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'vcpu' => $this->vcpu,
            'ram_mb' => $this->ramMb,
            'disk_gb' => $this->diskGb,
            'bandwidth_gb' => $this->bandwidthGb,
            'price_monthly' => $this->priceMonthly,
            'currency' => $this->currency,
            'price_hourly' => $this->priceHourly,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }
}
