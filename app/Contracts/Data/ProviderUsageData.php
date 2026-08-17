<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

class ProviderUsageData implements Arrayable
{
    public function __construct(
        public float $cpuPercent = 0.0,
        public float $ramMb = 0.0,
        public float $diskGb = 0.0,
        public ?float $bandwidthGb = null,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            cpuPercent: (float) ($data['cpu_percent'] ?? 0),
            ramMb: (float) ($data['ram_mb'] ?? 0),
            diskGb: (float) ($data['disk_gb'] ?? 0),
            bandwidthGb: isset($data['bandwidth_gb']) ? (float) $data['bandwidth_gb'] : null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'cpu_percent' => $this->cpuPercent,
            'ram_mb' => $this->ramMb,
            'disk_gb' => $this->diskGb,
            'bandwidth_gb' => $this->bandwidthGb,
            'metadata' => $this->metadata,
        ];
    }
}
