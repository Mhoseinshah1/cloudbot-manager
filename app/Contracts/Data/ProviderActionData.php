<?php

namespace App\Contracts\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Normalized representation of an asynchronous provider action.
 *
 * Providers (e.g. Hetzner) return actions that start out "running" and must
 * be waited on until they reach "success" or "error". This DTO carries the
 * normalized fields the application persists and audits; raw provider
 * response structures never leave the adapter layer.
 */
class ProviderActionData implements Arrayable
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public function __construct(
        public string $id,
        public string $command,
        public string $status = self::STATUS_RUNNING,
        public int $progress = 0,
        public ?string $started = null,
        public ?string $finished = null,
        /** @var array<string, mixed>|null */
        public ?array $error = null,
        public ?string $serverId = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            command: (string) ($data['command'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_RUNNING),
            progress: (int) ($data['progress'] ?? 0),
            started: $data['started'] ?? null,
            finished: $data['finished'] ?? null,
            error: $data['error'] ?? null,
            serverId: $data['server_id'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'command' => $this->command,
            'status' => $this->status,
            'progress' => $this->progress,
            'started' => $this->started,
            'finished' => $this->finished,
            'error' => $this->error,
            'server_id' => $this->serverId,
        ];
    }
}
