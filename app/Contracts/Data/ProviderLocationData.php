<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderLocationData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $countryCode = null,
        public ?string $city = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            countryCode: $data['country_code'] ?? null,
            city: $data['city'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country_code' => $this->countryCode,
            'city' => $this->city,
            'metadata' => $this->metadata,
        ];
    }
}
