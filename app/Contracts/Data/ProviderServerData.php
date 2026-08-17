<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderServerData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $ipAddress = null,
        public ?string $rootPassword = null,
        public ?string $locationId = null,
        public ?string $planId = null,
        public ?string $imageId = null,
        public array $metadata = [],
        public ?ProviderActionData $action = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            status: $data['status'],
            ipAddress: $data['ip_address'] ?? null,
            rootPassword: $data['root_password'] ?? null,
            locationId: $data['location_id'] ?? null,
            planId: $data['plan_id'] ?? null,
            imageId: $data['image_id'] ?? null,
            metadata: $data['metadata'] ?? [],
            action: isset($data['action']) && is_array($data['action']) ? ProviderActionData::fromArray($data['action']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'ip_address' => $this->ipAddress,
            'root_password' => $this->rootPassword,
            'location_id' => $this->locationId,
            'plan_id' => $this->planId,
            'image_id' => $this->imageId,
            'metadata' => $this->metadata,
            'action' => $this->action?->toArray(),
        ];
    }
}
