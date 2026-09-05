<?php

declare(strict_types=1);

namespace App\Cloud\Fake\Models;

use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A server inside the simulated provider.
 *
 * This is pretend remote state, not something this business owns. The local
 * record of a server that a customer bought is a different model, in a later
 * phase; confusing the two would mean deleting a customer's record because a
 * simulator forgot a row.
 *
 * @property string $provider_server_id
 * @property string|null $provisioning_token
 * @property string $name
 * @property string $provider_plan_id
 * @property string $provider_location_id
 * @property string $provider_image_id
 * @property string|null $ipv4
 * @property string|null $ipv6
 * @property ProviderServerStatus $status
 * @property ProviderPowerState $power_state
 * @property array<string, mixed>|null $metadata
 */
class FakeProviderServer extends Model
{
    protected $table = 'fake_provider_servers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_server_id', 'provisioning_token', 'name',
        'provider_plan_id', 'provider_location_id', 'provider_image_id',
        'status', 'power_state', 'ipv4', 'ipv6', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderServerStatus::class,
            'power_state' => ProviderPowerState::class,
            'metadata' => 'array',
        ];
    }
}
