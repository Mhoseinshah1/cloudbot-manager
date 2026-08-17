<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderImageData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $osFamily = null,
        public ?string $osDistro = null,
        public ?string $version = null,
        public ?string $architecture = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            osFamily: $data['os_family'] ?? null,
            osDistro: $data['os_distro'] ?? null,
            version: $data['version'] ?? null,
            architecture: $data['architecture'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'os_family' => $this->osFamily,
            'os_distro' => $this->osDistro,
            'version' => $this->version,
            'architecture' => $this->architecture,
            'metadata' => $this->metadata,
        ];
    }
}
