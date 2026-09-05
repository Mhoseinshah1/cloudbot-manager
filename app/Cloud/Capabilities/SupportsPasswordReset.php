<?php

declare(strict_types=1);

namespace App\Cloud\Capabilities;

use App\Cloud\Data\ProviderPasswordResetData;

/**
 * The provider can issue a new root password for a server it already runs.
 *
 * Optional, like every other capability: a provider without it simply does not
 * implement this interface, and `instanceof` is what answers whether it is
 * offered. A provider that implemented it to throw would be advertising a
 * promise it cannot keep.
 *
 * Release 1.0 uses this for exactly one thing, and the boundary matters. It is
 * the internal recovery path for a provisioning credential that was lost before
 * the server was ever delivered — the create response carried the only copy of
 * a password and the local write failed. Rotating a credential nobody has been
 * given is safe in a way that repeating a create, a reboot or a delete is not:
 * it makes no second machine, destroys no customer data, and invalidates no
 * password anybody is using.
 *
 * It is emphatically *not* a customer-facing reset. There is no button, no
 * rotation flow and no automation a customer can reach; that remains Release
 * 1.1. See ADR-003.
 */
interface SupportsPasswordReset
{
    /**
     * Issue a new root password for this server.
     *
     * The old password stops working. Callers must therefore only use this on a
     * server whose credential is not in anybody's hands — which in Release 1.0
     * means one that has not yet been delivered to its customer.
     *
     * @throws \App\Cloud\Exceptions\ProviderException
     */
    public function resetRootPassword(string $providerServerId): ProviderPasswordResetData;
}
